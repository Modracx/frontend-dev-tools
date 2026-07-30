<?php
declare(strict_types=1);

namespace Modracx\FrontendDevTools\Model\Analyzer;

/**
 * Groups the recorded statements so repetition becomes visible.
 *
 * Two different problems hide behind "the same query ran 60 times", and they have different
 * fixes, so they are reported separately:
 *
 *   - **Duplicate** — same statement, same bound values. The answer was already known and
 *     was fetched again. Fixed by caching or by not asking twice.
 *   - **N+1** — same statement shape, a different id each time. Something is looping over a
 *     collection and loading each row on its own. Fixed by joining or by preloading.
 *
 * Both are found by normalising the SQL into a fingerprint and counting; whether the bound
 * values differ is what tells the two apart.
 */
class QueryAnalyzer
{
    /**
     * @param list<array<string, mixed>> $queries
     * @return array<string, array<string, mixed>>
     */
    public function group(array $queries): array
    {
        $groups = [];

        foreach ($queries as $query) {
            $fingerprint = (string)($query['fingerprint'] ?? $this->fingerprint((string)($query['sql'] ?? '')));

            if (!isset($groups[$fingerprint])) {
                $groups[$fingerprint] = [
                    'fingerprint' => $fingerprint,
                    'sql' => (string)($query['sql'] ?? ''),
                    'count' => 0,
                    'total_ms' => 0.0,
                    'max_ms' => 0.0,
                    'min_ms' => PHP_FLOAT_MAX,
                    'binds' => [],
                    'distinct_binds' => 0,
                    'origin' => $query['origin'] ?? null,
                    'trail' => $query['trail'] ?? [],
                    'is_userland' => (bool)($query['is_userland'] ?? false),
                ];
            }

            $group = &$groups[$fingerprint];
            $ms = (float)($query['ms'] ?? 0);

            $group['count']++;
            $group['total_ms'] += $ms;
            $group['max_ms'] = max($group['max_ms'], $ms);
            $group['min_ms'] = min($group['min_ms'], $ms);

            $bindKey = $this->bindKey($query['bind'] ?? []);

            if (!isset($group['binds'][$bindKey]) && count($group['binds']) < 20) {
                $group['binds'][$bindKey] = $query['bind'] ?? [];
            }

            // Counted separately from the kept sample so "12 distinct ids" stays true even
            // once we stop keeping examples.
            if (!isset($group['seen_binds'][$bindKey])) {
                $group['seen_binds'][$bindKey] = true;
                $group['distinct_binds']++;
            }

            // A userland origin anywhere in the group wins: one call site you can edit is
            // more useful than five you cannot.
            if (!$group['is_userland'] && ($query['is_userland'] ?? false)) {
                $group['origin'] = $query['origin'] ?? null;
                $group['trail'] = $query['trail'] ?? [];
                $group['is_userland'] = true;
            }

            unset($group);
        }

        foreach ($groups as &$group) {
            unset($group['seen_binds']);
            $group['binds'] = array_values($group['binds']);
            $group['avg_ms'] = $group['count'] > 0 ? $group['total_ms'] / $group['count'] : 0.0;

            if ($group['min_ms'] === PHP_FLOAT_MAX) {
                $group['min_ms'] = 0.0;
            }
        }

        uasort($groups, static fn (array $a, array $b): int => $b['total_ms'] <=> $a['total_ms']);

        return $groups;
    }

    /**
     * Normalise a statement down to its shape.
     *
     * Literals become ?, IN lists collapse to a single placeholder, and whitespace is
     * flattened — so the sixty selects that differ only by entity_id land in one bucket.
     */
    public function fingerprint(string $sql): string
    {
        $normalised = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;
        $normalised = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", '?', $normalised) ?? $normalised;
        $normalised = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '?', $normalised) ?? $normalised;
        $normalised = preg_replace('/\b\d+\b/', '?', $normalised) ?? $normalised;
        $normalised = preg_replace('/\bIN\s*\((?:\s*\?\s*,?)+\)/i', 'IN (?)', $normalised) ?? $normalised;

        return $normalised;
    }

    /**
     * True when the statement selects by equality on a single value — the shape an N+1 takes.
     *
     * Without this check a repeated aggregate over the whole table would be mislabelled an
     * N+1, and the suggested fix (preload the ids) would make no sense.
     */
    public function looksLikeLookup(string $fingerprint): bool
    {
        return (bool)preg_match('/\bWHERE\b.*?[`\w.]+\s*=\s*\?/i', $fingerprint)
            && !preg_match('/\bGROUP\s+BY\b/i', $fingerprint);
    }

    /**
     * @param array<mixed> $bind
     */
    private function bindKey(mixed $bind): string
    {
        if (!is_array($bind)) {
            return (string)$bind;
        }

        return hash('xxh128', json_encode($bind, JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '');
    }
}
