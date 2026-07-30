<?php
declare(strict_types=1);

namespace Modracx\FrontendDevTools\Model;

use Magento\Framework\App\Cache\StateInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Indexer\ConfigInterface as IndexerConfig;
use Psr\Log\LoggerInterface;

/**
 * Measures the runtime the storefront is actually running on.
 *
 * Deliberately not an advice generator. "Enable OPcache" is a platitude that survives being
 * wrong; "OPcache is holding 127.2 MB of its 128 MB and has restarted 7 times" is a work
 * item with a number attached. Every finding here is derived from something read at runtime,
 * and no finding is raised unless the reading justifies it — a clean environment should
 * produce an empty list, because a tool that always finds something teaches you to ignore it.
 *
 * Runs when the Environment tab is opened, never during a profiled request. Reading indexer
 * state costs queries, and a profiler that adds queries to the page it is measuring is
 * lying about the page.
 */
class EnvironmentAudit
{
    /** Magento's own documented floors, so the numbers are not invented here. */
    private const OPCACHE_MEMORY_FLOOR_MB = 256;
    private const OPCACHE_FILES_FLOOR = 40000;
    private const REALPATH_FLOOR_BYTES = 5242880;
    private const REALPATH_TTL_FLOOR = 3600;

    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
        private readonly TypeListInterface $cacheTypeList,
        private readonly StateInterface $cacheState,
        private readonly IndexerConfig $indexerConfig,
        private readonly IndexerRegistry $indexerRegistry,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $findings = [];
        $php = $this->php($findings);
        $magento = $this->magento($findings);

        usort($findings, static function (array $a, array $b): int {
            $order = ['info' => 1, 'low' => 2, 'medium' => 3, 'high' => 4, 'critical' => 5];

            return $order[$b['severity']] <=> $order[$a['severity']];
        });

        return ['php' => $php, 'magento' => $magento, 'findings' => $findings];
    }

    /**
     * @param list<array<string, mixed>> $findings
     * @return array<string, mixed>
     */
    private function php(array &$findings): array
    {
        // opcache_get_status() emits a warning when OPcache is compiled in but disabled, and
        // returns false. Checking the flag first means there is nothing to silence.
        $status = function_exists('opcache_get_status') && ini_get('opcache.enable')
            ? opcache_get_status(false)
            : null;
        $php = [
            'version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'opcache_enabled' => (bool)ini_get('opcache.enable'),
            'opcache_memory_mb' => (int)ini_get('opcache.memory_consumption'),
            'opcache_max_files' => (int)ini_get('opcache.max_accelerated_files'),
            'opcache_validate_timestamps' => (bool)ini_get('opcache.validate_timestamps'),
            'realpath_size' => ini_get('realpath_cache_size'),
            'realpath_ttl' => (int)ini_get('realpath_cache_ttl'),
            'realpath_used' => function_exists('realpath_cache_size') ? realpath_cache_size() : null,
            'xdebug' => extension_loaded('xdebug'),
            'xdebug_mode' => ini_get('xdebug.mode') ?: null,
            'xdebug_start' => ini_get('xdebug.start_with_request') ?: null,
        ];

        if (!$php['opcache_enabled']) {
            $findings[] = $this->finding(
                'critical',
                'OPcache is off',
                'Every request recompiles all of Magento from source.',
                'Set opcache.enable=1 in the PHP configuration used by the web server.'
            );

            return $php;
        }

        if (is_array($status)) {
            $used = (int)($status['memory_usage']['used_memory'] ?? 0);
            $free = (int)($status['memory_usage']['free_memory'] ?? 0);
            $wasted = (int)($status['memory_usage']['wasted_memory'] ?? 0);
            $stats = $status['opcache_statistics'] ?? [];
            $restarts = (int)($stats['oom_restarts'] ?? 0) + (int)($stats['manual_restarts'] ?? 0);
            $cached = (int)($stats['num_cached_scripts'] ?? 0);
            $total = max(1, $used + $free + $wasted);

            $php += [
                'opcache_used_mb' => round($used / 1048576, 1),
                'opcache_free_mb' => round($free / 1048576, 1),
                'opcache_wasted_mb' => round($wasted / 1048576, 1),
                'opcache_hit_rate' => round((float)($stats['opcache_hit_rate'] ?? 0), 2),
                'opcache_cached_scripts' => $cached,
                'opcache_restarts' => $restarts,
                'opcache_full_pct' => round((($used + $wasted) / $total) * 100, 1),
            ];

            // Fullness is the reading that matters, not the configured size: an OPcache with
            // no room left restarts, and a restart throws away every compiled script.
            if ($php['opcache_full_pct'] >= 95) {
                $findings[] = $this->finding(
                    (int)$restarts > 0 ? 'critical' : 'high',
                    sprintf('OPcache is %s%% full', $php['opcache_full_pct']),
                    sprintf(
                        '%s MB used of %s MB, %s MB free%s. When it fills it restarts, and every '
                        . 'request after a restart recompiles Magento from source.',
                        $php['opcache_used_mb'],
                        $php['opcache_memory_mb'],
                        $php['opcache_free_mb'],
                        $restarts > 0 ? sprintf(', and it has already restarted %d times', $restarts) : ''
                    ),
                    sprintf(
                        'Raise opcache.memory_consumption — %d MB is below the %d MB Magento needs.',
                        $php['opcache_memory_mb'],
                        self::OPCACHE_MEMORY_FLOOR_MB
                    )
                );
            } elseif ($restarts > 0) {
                $findings[] = $this->finding(
                    'medium',
                    sprintf('OPcache has restarted %d times', $restarts),
                    'It is not full now, but each restart dropped every compiled script.',
                    'Raise opcache.memory_consumption, or check what is calling opcache_reset().'
                );
            }

            if ($cached >= $php['opcache_max_files'] * 0.9) {
                $findings[] = $this->finding(
                    'high',
                    'OPcache is near its file limit',
                    sprintf('%d scripts cached against a limit of %d.', $cached, $php['opcache_max_files']),
                    sprintf('Raise opcache.max_accelerated_files to at least %d.', self::OPCACHE_FILES_FLOOR)
                );
            }
        }

        $realpathBytes = $this->toBytes((string)$php['realpath_size']);

        if ($realpathBytes > 0 && $realpathBytes < self::REALPATH_FLOOR_BYTES) {
            $findings[] = $this->finding(
                'medium',
                'realpath cache is small',
                sprintf(
                    '%s with a %d second TTL. Magento resolves tens of thousands of paths per request.',
                    $php['realpath_size'],
                    $php['realpath_ttl']
                ),
                'Set realpath_cache_size=10M and realpath_cache_ttl=86400.'
            );
        } elseif ($php['realpath_ttl'] > 0 && $php['realpath_ttl'] < self::REALPATH_TTL_FLOOR) {
            $findings[] = $this->finding(
                'low',
                'realpath cache expires quickly',
                sprintf('TTL is %d seconds.', $php['realpath_ttl']),
                'Set realpath_cache_ttl=86400.'
            );
        }

        if ($php['xdebug']) {
            // PHP normalises boolean-ish ini values, so a php.ini saying
            // `xdebug.start_with_request=yes` reads back as the string "1". Matching only the
            // literal words missed precisely the configuration this is meant to catch.
            $start = strtolower(trim((string)$php['xdebug_start']));
            $always = !in_array($start, ['trigger', '0', 'no', 'off', 'false'], true);

            $findings[] = $this->finding(
                $always ? 'high' : 'info',
                $always ? 'Xdebug attaches to every request' : 'Xdebug is loaded',
                sprintf(
                    'mode=%s, start_with_request=%s.%s Every timing in this toolbar is inflated by it.',
                    $php['xdebug_mode'] ?? 'unset',
                    $php['xdebug_start'] ?? 'unset',
                    $always
                        ? ' In debug mode that means opening a connection to the debug client on'
                          . ' every request, including images and AJAX.'
                        : ''
                ),
                $always
                    ? 'Set xdebug.start_with_request=trigger. Step debugging still works, on demand.'
                    : 'Nothing to do — it only engages when triggered.'
            );
        }

        return $php;
    }

    /**
     * @param list<array<string, mixed>> $findings
     * @return array<string, mixed>
     */
    private function magento(array &$findings): array
    {
        $disabled = [];
        $stale = [];
        $realtime = [];

        try {
            foreach ($this->cacheTypeList->getTypes() as $type) {
                if (!$this->cacheState->isEnabled((string)$type->getId())) {
                    $disabled[] = (string)$type->getId();
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('Modracx_FrontendDevTools cache audit failed: ' . $e->getMessage());
        }

        try {
            foreach (array_keys($this->indexerConfig->getIndexers()) as $id) {
                $indexer = $this->indexerRegistry->get((string)$id);

                if (!$indexer->isScheduled()) {
                    $realtime[] = $indexer->getTitle() ?: (string)$id;
                }

                if ($indexer->isInvalid()) {
                    $stale[] = $indexer->getTitle() ?: (string)$id;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('Modracx_FrontendDevTools indexer audit failed: ' . $e->getMessage());
        }

        if ($disabled !== []) {
            $findings[] = $this->finding(
                'critical',
                sprintf('%d cache type(s) disabled', count($disabled)),
                implode(', ', $disabled) . '. A disabled cache type is rebuilt on every request.',
                'Re-enable them unless you are deliberately debugging without them.'
            );
        }

        if ($stale !== []) {
            $findings[] = $this->finding(
                'medium',
                sprintf('%d indexer(s) invalid', count($stale)),
                implode(', ', $stale) . '. Invalid indexers make the storefront fall back to slower paths.',
                'Run bin/magento indexer:reindex, or let cron catch up.'
            );
        }

        if ($realtime !== []) {
            $findings[] = $this->finding(
                'low',
                sprintf('%d indexer(s) on Update on Save', count($realtime)),
                implode(', ', $realtime) . '. Saving a product reindexes inside the request.',
                'Switch to Update by Schedule unless you need immediate reindexing.'
            );
        }

        return [
            'disabled_cache_types' => $disabled,
            'invalid_indexers' => $stale,
            'realtime_indexers' => $realtime,
            'db_prefix' => (string)$this->deploymentConfig->get('db/table_prefix'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function finding(string $severity, string $title, string $detail, string $fix): array
    {
        return ['severity' => $severity, 'title' => $title, 'detail' => $detail, 'fix' => $fix];
    }

    private function toBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int)$value;

        return match ($unit) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
