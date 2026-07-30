<?php
declare(strict_types=1);

namespace Modracx\FrontendDevTools\Plugin\Db;

use Magento\Framework\DB\LoggerInterface;
use Modracx\FrontendDevTools\Model\AccessGate;
use Modracx\FrontendDevTools\Model\Analyzer\QueryAnalyzer;
use Modracx\FrontendDevTools\Model\Config;
use Modracx\FrontendDevTools\Model\ProfileContext;
use Modracx\FrontendDevTools\Model\StackTrace;
use Modracx\FrontendDevTools\Model\ValueMasker;

/**
 * Records every statement the storefront runs, and who asked for it.
 *
 * Magento already calls startTimer() before a query and logStats() after it, on whichever
 * LoggerInterface the installation is configured with — normally the one that does nothing.
 * Hooking those two points gets the full statement, its bound values and its duration
 * without switching on Magento's own profiler, and without changing the preference, so an
 * installation that has deliberately set a different logger keeps it.
 */
class QueryLogger
{
    /**
     * Guards against the profiler profiling itself.
     *
     * Reading configuration can itself hit the database on a cold cache. Without this flag,
     * the first query of the request asks the gate whether it is allowed, the gate reads
     * config, that read issues a query, and the request never finishes.
     */
    private bool $busy = false;

    private ?float $startedAt = null;

    private ?bool $active = null;

    /**
     * How many times each statement shape has been seen, so a repeat can be recognised at the
     * moment it becomes one.
     *
     * @var array<string, int>
     */
    private array $seen = [];

    private ?float $slowThreshold = null;

    private ?int $repeatThreshold = null;

    public function __construct(
        private readonly ProfileContext $context,
        private readonly AccessGate $gate,
        private readonly Config $config,
        private readonly StackTrace $stackTrace,
        private readonly QueryAnalyzer $analyzer,
        private readonly ValueMasker $masker
    ) {
    }

    public function beforeStartTimer(LoggerInterface $subject): void
    {
        if ($this->isActive()) {
            $this->startedAt = microtime(true);
        }
    }

    /**
     * @param string $type
     * @param string $sql
     * @param array<mixed> $bind
     */
    public function beforeLogStats(
        LoggerInterface $subject,
        $type,
        $sql,
        $bind = [],
        $result = null
    ): void {
        if ($type !== LoggerInterface::TYPE_QUERY || !$this->isActive() || $this->startedAt === null) {
            return;
        }

        $ms = (microtime(true) - $this->startedAt) * 1000;
        $this->startedAt = null;

        $this->busy = true;
        $selfStart = microtime(true);

        try {
            $fingerprint = $this->analyzer->fingerprint((string)$sql);
            $count = $this->seen[$fingerprint] = ($this->seen[$fingerprint] ?? 0) + 1;
            $trace = $this->traceIfWorthIt($ms, $count);

            $this->context->push('queries', [
                'sql' => $this->truncate((string)$sql, 8000),
                'fingerprint' => $fingerprint,
                'ms' => round($ms, 3),
                'bind' => $this->sanitiseBind(is_array($bind) ? $bind : []),
                'origin' => $trace['origin'] ?? null,
                'trail' => $trace['trail'] ?? [],
                'is_userland' => $trace['is_userland'] ?? false,
                'module' => isset($trace['origin']) ? $this->stackTrace->moduleFor($trace['origin']) : null,
                'at_ms' => round(($this->startedAtOffset()), 3),
            ]);

            $this->context->addMs('query_time_ms', $ms);
        } catch (\Throwable) {
            // A profiler must never be the reason a page fails to render.
        } finally {
            $this->context->addOverhead('queries', (microtime(true) - $selfStart) * 1000);
            $this->busy = false;
        }
    }

    /**
     * Walk the stack only for the statements a finding could ever be raised about.
     *
     * A backtrace is by far the most expensive thing this collector does, and taking one for
     * every statement means the profiler dominates its own numbers — on a 250-query page that
     * is 250 stack walks to explain a page that ran none. Nothing is lost by being selective:
     * a finding is only ever raised about a statement that is slow, or one whose shape
     * repeats, so those are the only two cases where an origin is ever displayed.
     *
     * The repeat case is caught on the exact execution that crosses the threshold, which is
     * the cheapest possible moment to notice — and one origin describes the whole group,
     * because every member of it came from the same place.
     *
     * @return array{origin: string|null, is_userland: bool, trail: list<string>}|null
     */
    private function traceIfWorthIt(float $ms, int $count): ?array
    {
        $this->slowThreshold ??= $this->config->threshold('slow_query');
        $this->repeatThreshold ??= (int)min(
            $this->config->threshold('duplicate_limit'),
            $this->config->threshold('nplus1_limit')
        );

        if ($ms < $this->slowThreshold && $count !== max(2, $this->repeatThreshold)) {
            return null;
        }

        return $this->stackTrace->summarise(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30));
    }

    private function startedAtOffset(): float
    {
        return $this->context->elapsedMs();
    }

    private function isActive(): bool
    {
        if ($this->busy || $this->context->isFrozen()) {
            return false;
        }

        if ($this->active !== null) {
            return $this->active;
        }

        $this->busy = true;

        try {
            // The answer is not knowable until the request has an area, and caching "no"
            // before then would silence the collector for the rest of the request.
            if (!$this->gate->areaResolved()) {
                return false;
            }

            return $this->active = $this->config->isEnabled()
                && $this->config->collects('db')
                && $this->gate->isAllowed();
        } catch (\Throwable) {
            return $this->active = false;
        } finally {
            $this->busy = false;
        }
    }

    /**
     * @param array<mixed> $bind
     * @return array<mixed>
     */
    private function sanitiseBind(array $bind): array
    {
        $clean = [];

        foreach ($bind as $key => $value) {
            if (count($clean) >= 50) {
                $clean['…'] = sprintf('%d more', count($bind) - 50);
                break;
            }

            $clean[$key] = match (true) {
                is_scalar($value), $value === null => is_string($value)
                    // Masked before truncation: a half-shown email is still an email.
                    ? $this->truncate((string)$this->masker->maskBind($key, $value), 200)
                    : $value,
                is_array($value) => sprintf('array(%d)', count($value)),
                is_object($value) => get_class($value),
                default => gettype($value),
            };
        }

        return $clean;
    }

    private function truncate(string $value, int $length): string
    {
        return strlen($value) > $length ? substr($value, 0, $length) . '…' : $value;
    }
}
