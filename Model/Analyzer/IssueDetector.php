<?php
declare(strict_types=1);

namespace Modracx\FrontendDevTools\Model\Analyzer;

use Modracx\FrontendDevTools\Model\Config;

/**
 * Turns measurements into findings.
 *
 * A list of 900 queries sorted by duration is data, not an answer. This class applies the
 * configured thresholds, works out roughly how much time each finding is worth, and says
 * what to do about it — then splits the result in two, because a slow core query is
 * information and a slow query in your own module is a task.
 */
class IssueDetector
{
    private const SEVERITY_ORDER = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

    public function __construct(
        private readonly Config $config,
        private readonly QueryAnalyzer $queryAnalyzer
    ) {
    }

    /**
     * @param array<string, mixed> $profile
     * @return array{userland: list<array<string, mixed>>, core: list<array<string, mixed>>}
     */
    public function detect(array $profile): array
    {
        $issues = array_merge(
            $this->fromQueries($profile['queries'] ?? []),
            $this->fromBlocks($profile['blocks'] ?? []),
            $this->fromObservers($profile['observers'] ?? []),
            $this->fromModules($profile['modules'] ?? [])
        );

        usort($issues, function (array $a, array $b): int {
            return [self::SEVERITY_ORDER[$b['severity']], $b['saving_ms']]
                <=> [self::SEVERITY_ORDER[$a['severity']], $a['saving_ms']];
        });

        $userland = [];
        $core = [];

        foreach ($issues as $issue) {
            if ($issue['is_core']) {
                $core[] = $issue;
            } else {
                $userland[] = $issue;
            }
        }

        return ['userland' => $userland, 'core' => $core];
    }

    /**
     * @param list<array<string, mixed>> $queries
     * @return list<array<string, mixed>>
     */
    private function fromQueries(array $queries): array
    {
        $issues = [];
        $slowThreshold = $this->config->threshold('slow_query');
        $duplicateLimit = (int)$this->config->threshold('duplicate_limit');
        $nPlusOneLimit = (int)$this->config->threshold('nplus1_limit');

        foreach ($this->queryAnalyzer->group($queries) as $group) {
            $count = (int)$group['count'];
            $isRepeat = false;

            if ($count >= $nPlusOneLimit
                && $group['distinct_binds'] > 1
                && $this->queryAnalyzer->looksLikeLookup($group['fingerprint'])
            ) {
                $isRepeat = true;
                $issues[] = $this->issue(
                    'n_plus_one',
                    sprintf('N+1: the same lookup ran %d times with %d different values', $count, $group['distinct_binds']),
                    $group,
                    // Collapsing an N+1 leaves one query behind, so the saving is everything
                    // but the first execution.
                    $group['total_ms'] - $group['avg_ms'],
                    $count / max($nPlusOneLimit, 1),
                    'Something is loading rows one at a time inside a loop. Load them in a '
                    . 'single call — addFieldToFilter(\'entity_id\', [\'in\' => $ids]) or a join — '
                    . 'and index the result by id.'
                );
            } elseif ($count >= $duplicateLimit && $group['distinct_binds'] === 1) {
                $isRepeat = true;
                $issues[] = $this->issue(
                    'duplicate_query',
                    sprintf('The identical statement ran %d times', $count),
                    $group,
                    $group['total_ms'] - $group['avg_ms'],
                    $count / max($duplicateLimit, 1),
                    'Same statement, same bound values, same answer every time. Hold the '
                    . 'result on the object, in a registry or in the cache instead of asking again.'
                );
            }

            // A query can be both slow and repeated; report the slowness only when it is not
            // already accounted for above, so one problem does not appear twice in the list.
            if (!$isRepeat && $group['max_ms'] >= $slowThreshold) {
                $issues[] = $this->issue(
                    'slow_query',
                    sprintf('Slow query — %.1f ms', $group['max_ms']),
                    $group,
                    $group['max_ms'] - $slowThreshold,
                    $group['max_ms'] / max($slowThreshold, 1),
                    'Run EXPLAIN on this statement. Most storefront offenders are a missing '
                    . 'index on a filtered column, or a filesort caused by ordering on an '
                    . 'unindexed expression.'
                );
            }
        }

        return $issues;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    private function fromBlocks(array $blocks): array
    {
        $issues = [];
        $threshold = $this->config->threshold('slow_block');

        foreach ($blocks as $block) {
            // Exclusive time, otherwise every ancestor of one slow block is reported too and
            // the root container is always the worst offender.
            $ms = (float)($block['exclusive_ms'] ?? $block['ms'] ?? 0);

            if ($ms < $threshold) {
                continue;
            }

            $issues[] = [
                'type' => 'slow_block',
                'title' => sprintf('Block rendered in %.1f ms', $ms),
                'subject' => (string)($block['name'] ?? $block['class'] ?? 'unknown'),
                'detail' => (string)($block['template'] ?? $block['class'] ?? ''),
                'origin' => $block['template'] ?? null,
                'trail' => [],
                'count' => 1,
                'saving_ms' => max(0.0, $ms - $threshold),
                'severity' => $this->severity($ms / max($threshold, 1)),
                'is_core' => $this->isCore((string)($block['module'] ?? '')),
                'module' => $block['module'] ?? null,
                'recommendation' => 'Time here is spent in the template or in the block\'s own '
                    . 'data methods, exclusive of children. If the content is the same for '
                    . 'everyone, give the block a cache key and lifetime; if it is personal, '
                    . 'move it behind section data so the page itself stays cacheable.',
            ];
        }

        return $issues;
    }

    /**
     * @param list<array<string, mixed>> $observers
     * @return list<array<string, mixed>>
     */
    private function fromObservers(array $observers): array
    {
        $issues = [];
        $threshold = $this->config->threshold('slow_observer');
        $totals = [];

        foreach ($observers as $observer) {
            $key = ($observer['event'] ?? '') . '|' . ($observer['name'] ?? '');
            $totals[$key] ??= ['ms' => 0.0, 'count' => 0, 'row' => $observer];
            $totals[$key]['ms'] += (float)($observer['ms'] ?? 0);
            $totals[$key]['count']++;
        }

        foreach ($totals as $total) {
            if ($total['ms'] < $threshold) {
                continue;
            }

            $observer = $total['row'];

            $issues[] = [
                'type' => 'slow_observer',
                'title' => sprintf(
                    'Observer took %.1f ms%s',
                    $total['ms'],
                    $total['count'] > 1 ? sprintf(' across %d dispatches', $total['count']) : ''
                ),
                'subject' => (string)($observer['name'] ?? 'unknown'),
                'detail' => sprintf('%s on %s', $observer['class'] ?? '', $observer['event'] ?? ''),
                'origin' => $observer['class'] ?? null,
                'trail' => [],
                'count' => $total['count'],
                'saving_ms' => max(0.0, $total['ms'] - $threshold),
                'severity' => $this->severity($total['ms'] / max($threshold, 1)),
                'is_core' => $this->isCore((string)($observer['module'] ?? '')),
                'module' => $observer['module'] ?? null,
                'recommendation' => 'Observers run inside the request that dispatched them. '
                    . 'If this work does not have to finish before the response, move it to a '
                    . 'message queue or a cron job.',
            ];
        }

        return $issues;
    }

    /**
     * @param array<string, array<string, mixed>> $modules
     * @return list<array<string, mixed>>
     */
    private function fromModules(array $modules): array
    {
        $issues = [];
        $threshold = $this->config->threshold('heavy_module');

        foreach ($modules as $name => $stats) {
            $ms = (float)($stats['total_ms'] ?? 0);

            if ($ms < $threshold) {
                continue;
            }

            $issues[] = [
                'type' => 'heavy_module',
                'title' => sprintf('%s accounts for %.0f ms of this request', $name, $ms),
                'subject' => (string)$name,
                'detail' => sprintf(
                    '%.0f ms in blocks, %.0f ms in observers, %d queries',
                    $stats['block_ms'] ?? 0,
                    $stats['observer_ms'] ?? 0,
                    $stats['query_count'] ?? 0
                ),
                'origin' => null,
                'trail' => [],
                'count' => 1,
                'saving_ms' => max(0.0, $ms - $threshold),
                'severity' => $this->severity($ms / max($threshold, 1)),
                'is_core' => $this->isCore((string)$name),
                'module' => $name,
                'recommendation' => 'Aggregated across everything this module did. Open the '
                    . 'Modules tab to see which of its blocks, observers or queries the time '
                    . 'went to.',
            ];
        }

        return $issues;
    }

    /**
     * @param array<string, mixed> $group
     * @return array<string, mixed>
     */
    private function issue(
        string $type,
        string $title,
        array $group,
        float $saving,
        float $ratio,
        string $recommendation
    ): array {
        return [
            'type' => $type,
            'title' => $title,
            'subject' => $this->shorten((string)$group['sql']),
            'detail' => (string)$group['sql'],
            'origin' => $group['origin'] ?? null,
            'trail' => $group['trail'] ?? [],
            'count' => (int)$group['count'],
            'saving_ms' => max(0.0, $saving),
            'severity' => $this->severity($ratio),
            // Without a call site we can edit, this is something core did to itself.
            'is_core' => !($group['is_userland'] ?? false),
            'module' => null,
            'recommendation' => $recommendation,
        ];
    }

    private function severity(float $ratio): string
    {
        return match (true) {
            $ratio >= 8 => 'critical',
            $ratio >= 4 => 'high',
            $ratio >= 2 => 'medium',
            default => 'low',
        };
    }

    private function isCore(string $module): bool
    {
        return $module === '' || str_starts_with($module, 'Magento_') || str_starts_with($module, 'Hyva_');
    }

    private function shorten(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;

        return strlen($sql) > 120 ? substr($sql, 0, 117) . '…' : $sql;
    }
}
