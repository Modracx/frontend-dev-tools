<?php
declare(strict_types=1);

namespace Modracx\FrontendDevTools\Model;

/**
 * The per-request recorder every collector plugin writes into.
 *
 * Deliberately dumb: no formatting, no analysis, no database. Plugins run inside the code
 * they are measuring, so the only thing they are allowed to do is append to an array — the
 * cost of a collector has to stay well under the cost of what it reports on, or the numbers
 * describe the profiler rather than the page.
 *
 * Every list is capped at Config::maxEntries(). Once a list is full it stops growing and
 * remembers how many entries it dropped, so the panel can say "showing 5000 of 41231"
 * instead of quietly implying that was all of them.
 */
class ProfileContext
{
    private ?string $token = null;

    private float $startedAt;

    /** @var array<string, list<array<string, mixed>>> */
    private array $lists = [];

    /** @var array<string, int> */
    private array $dropped = [];

    /** @var array<string, mixed> */
    private array $meta = [];

    /**
     * Time this module spent on its own bookkeeping, by bucket.
     *
     * Recorded outside the freeze, and outside every cap, because the one number a profiler
     * must never quietly omit is its own.
     *
     * @var array<string, float>
     */
    private array $overhead = [];

    private bool $layoutGenerated = false;

    private bool $frozen = false;

    public function __construct(
        private readonly Config $config
    ) {
        // $_SERVER['REQUEST_TIME_FLOAT'] is stamped by PHP before a single line of Magento
        // runs, which is the only honest zero for "how long did this page take".
        $this->startedAt = (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
    }

    public function token(): string
    {
        return $this->token ??= bin2hex(random_bytes(16));
    }

    public function startedAt(): float
    {
        return $this->startedAt;
    }

    public function elapsedMs(): float
    {
        return (microtime(true) - $this->startedAt) * 1000;
    }

    /**
     * Stop accepting data.
     *
     * Called once the response has been built. Anything that runs afterwards — our own panel
     * rendering, the profile write itself — would otherwise show up in its own numbers.
     */
    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function push(string $list, array $entry): void
    {
        if ($this->frozen) {
            return;
        }

        $current = $this->lists[$list] ?? [];

        if (count($current) >= $this->config->maxEntries()) {
            $this->dropped[$list] = ($this->dropped[$list] ?? 0) + 1;

            return;
        }

        $this->lists[$list][] = $entry;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(string $list): array
    {
        return $this->lists[$list] ?? [];
    }

    public function count(string $list): int
    {
        return count($this->lists[$list] ?? []);
    }

    public function droppedCount(string $list): int
    {
        return $this->dropped[$list] ?? 0;
    }

    public function setMeta(string $key, mixed $value): void
    {
        $this->meta[$key] = $value;
    }

    public function meta(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function allMeta(): array
    {
        return $this->meta;
    }

    public function addMs(string $key, float $ms): void
    {
        $this->meta[$key] = (float)($this->meta[$key] ?? 0) + $ms;
    }

    public function increment(string $key, int $by = 1): void
    {
        $this->meta[$key] = (int)($this->meta[$key] ?? 0) + $by;
    }

    /**
     * Charge time to this module's own account.
     *
     * Deliberately ignores the freeze: assembling and storing the profile happens after the
     * page is built, and that work is still this module's cost even though it no longer
     * inflates the page timings.
     */
    public function addOverhead(string $bucket, float $ms): void
    {
        $this->overhead[$bucket] = ($this->overhead[$bucket] ?? 0.0) + $ms;
    }

    /**
     * @return array<string, float>
     */
    public function overhead(): array
    {
        return $this->overhead;
    }

    /**
     * Overhead paid *before* the response was built, which is therefore included in — and
     * inflating — every duration this profile reports.
     */
    public function inPageOverheadMs(): float
    {
        $total = 0.0;

        foreach ($this->overhead as $bucket => $ms) {
            if (!in_array($bucket, ['build', 'store', 'render'], true)) {
                $total += $ms;
            }
        }

        return $total;
    }

    /**
     * Overhead paid after the page was finished: it delays the response reaching the browser
     * but is not counted in the page timings, so the two must never be added together and
     * presented as one number.
     */
    public function afterResponseOverheadMs(): float
    {
        return ($this->overhead['build'] ?? 0.0)
            + ($this->overhead['store'] ?? 0.0)
            + ($this->overhead['render'] ?? 0.0);
    }

    public function markLayoutGenerated(): void
    {
        $this->layoutGenerated = true;
    }

    /**
     * Whether this request actually built a page.
     *
     * If layout never generated, the response came out of the full page cache — which is
     * how the Cache panel can tell a hit from a miss without depending on the
     * X-Magento-Cache-Debug header being switched on.
     */
    public function layoutWasGenerated(): bool
    {
        return $this->layoutGenerated;
    }
}
