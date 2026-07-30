<?php
declare(strict_types=1);

namespace Modracx\FrontendDevTools\Model\Storage;

use Magento\Framework\App\ResourceConnection;
use Modracx\FrontendDevTools\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * Persists finished runs so they can be looked at after the fact.
 *
 * Storage exists for two reasons the inline toolbar cannot cover. An AJAX request has no
 * page to draw a toolbar on — section loads, add-to-cart, GraphQL — and those are exactly
 * the requests people need to profile. And a run you have already navigated away from is
 * often the one you wanted.
 *
 * The summary row carries only what the History list sorts and filters on; each panel's
 * payload is a separate row, so opening the toolbar does not drag half a megabyte of query
 * log out of the database to render a badge.
 */
class ProfileRepository
{
    private const TABLE = 'modracx_fdt_profile';
    private const TABLE_DATA = 'modracx_fdt_profile_data';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $profile
     */
    public function save(string $token, array $profile, ?string $clientIp = null): void
    {
        if (!$this->config->persists()) {
            return;
        }

        $summary = $profile['summary'] ?? [];

        try {
            $connection = $this->resource->getConnection();

            $connection->insertOnDuplicate($this->resource->getTableName(self::TABLE), [
                'token' => $token,
                'url' => substr((string)($summary['url'] ?? ''), 0, 2048),
                'method' => substr((string)($summary['method'] ?? 'GET'), 0, 10),
                'full_action' => substr((string)($summary['full_action'] ?? ''), 0, 255),
                'status_code' => $summary['status_code'] ?? null,
                'theme' => $summary['theme'] ?? null,
                'store_id' => $summary['store_id'] ?? null,
                'is_ajax' => !empty($summary['is_ajax']) ? 1 : 0,
                'fpc_status' => $summary['fpc'] ?? null,
                'duration_ms' => $summary['duration_ms'] ?? null,
                'memory_peak_kb' => $summary['memory_peak_kb'] ?? null,
                'query_count' => $summary['query_count'] ?? 0,
                'query_time_ms' => $summary['query_time_ms'] ?? 0,
                'block_count' => $summary['block_count'] ?? 0,
                'event_count' => $summary['event_count'] ?? 0,
                'issue_count' => $summary['issue_count'] ?? 0,
                'max_severity' => $summary['max_severity'] ?? null,
                'client_ip' => $clientIp !== null ? substr($clientIp, 0, 45) : null,
            ]);

            $rows = [];

            foreach ($profile as $collector => $payload) {
                $encoded = json_encode($payload, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);

                if ($encoded === false) {
                    continue;
                }

                $rows[] = [
                    'token' => $token,
                    'collector' => substr((string)$collector, 0, 32),
                    'payload' => $encoded,
                ];
            }

            if ($rows !== []) {
                $connection->insertOnDuplicate($this->resource->getTableName(self::TABLE_DATA), $rows, ['payload']);
            }
        } catch (\Throwable $e) {
            // Losing a profile is an inconvenience. Losing the page it was profiling because
            // the profiler could not write to its own table is not acceptable.
            $this->logger->debug('Modracx_FrontendDevTools could not store a profile: ' . $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadCollector(string $token, string $collector): ?array
    {
        try {
            $connection = $this->resource->getConnection();
            $select = $connection->select()
                ->from($this->resource->getTableName(self::TABLE_DATA), ['payload'])
                ->where('token = ?', $token)
                ->where('collector = ?', $collector)
                ->limit(1);

            $payload = $connection->fetchOne($select);

            if (!is_string($payload) || $payload === '') {
                return null;
            }

            $decoded = json_decode($payload, true);

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            $this->logger->debug('Modracx_FrontendDevTools could not read a profile: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Merge client-side events into a stored run.
     *
     * Sent by the browser as it leaves the page, so errors that happened while nobody had the
     * toolbar open are not simply lost when the tab navigates away.
     *
     * @param list<array<string, string>> $events
     */
    public function appendClientEvents(string $token, array $events): void
    {
        if (!$this->config->persists() || $events === []) {
            return;
        }

        try {
            $existing = $this->loadCollector($token, 'client') ?? [];
            $merged = array_slice(array_merge($existing, $events), -200);

            $this->resource->getConnection()->insertOnDuplicate(
                $this->resource->getTableName(self::TABLE_DATA),
                [[
                    'token' => $token,
                    'collector' => 'client',
                    'payload' => json_encode($merged, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                ]],
                ['payload']
            );
        } catch (\Throwable $e) {
            $this->logger->debug('Modracx_FrontendDevTools could not store client events: ' . $e->getMessage());
        }
    }

    /**
     * The previous run of the same route, for comparison.
     *
     * Restricted to the same action *and* the same cache status, because comparing a page
     * that was built against one that came out of the cache produces a headline number that
     * is pure noise — the interesting question is always "same work, did it get slower".
     *
     * @return array<string, mixed>|null
     */
    public function previousRun(string $token, ?string $fullAction, ?string $fpcStatus): ?array
    {
        if ($fullAction === null || $fullAction === '') {
            return null;
        }

        try {
            $connection = $this->resource->getConnection();
            $select = $connection->select()
                ->from($this->resource->getTableName(self::TABLE))
                ->where('full_action = ?', $fullAction)
                ->where('token != ?', $token)
                ->order('profile_id DESC')
                ->limit(1);

            if ($fpcStatus !== null) {
                $select->where('fpc_status = ?', $fpcStatus);
            }

            $row = $connection->fetchRow($select);

            return $row ?: null;
        } catch (\Throwable $e) {
            $this->logger->debug('Modracx_FrontendDevTools could not load the previous run: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 50): array
    {
        try {
            $connection = $this->resource->getConnection();
            $select = $connection->select()
                ->from($this->resource->getTableName(self::TABLE))
                ->order('profile_id DESC')
                ->limit(max(1, min($limit, 200)));

            return $connection->fetchAll($select);
        } catch (\Throwable $e) {
            $this->logger->debug('Modracx_FrontendDevTools could not list profiles: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Drop everything older than the retention window.
     *
     * Storing a full query log for every storefront request an allowed IP makes adds up fast,
     * which is why the default window is a day rather than the sixty this module's admin
     * counterpart keeps its audit trail for. These are diagnostics, not a record.
     */
    public function prune(): int
    {
        try {
            $connection = $this->resource->getConnection();
            $cutoff = new \DateTimeImmutable(sprintf('-%d hours', $this->config->retentionHours()));

            $tokens = $connection->fetchCol(
                $connection->select()
                    ->from($this->resource->getTableName(self::TABLE), ['token'])
                    ->where('created_at < ?', $cutoff->format('Y-m-d H:i:s'))
                    ->limit(5000)
            );

            if ($tokens === []) {
                return 0;
            }

            $connection->delete(
                $this->resource->getTableName(self::TABLE_DATA),
                ['token IN (?)' => $tokens]
            );

            return (int)$connection->delete(
                $this->resource->getTableName(self::TABLE),
                ['token IN (?)' => $tokens]
            );
        } catch (\Throwable $e) {
            $this->logger->debug('Modracx_FrontendDevTools could not prune profiles: ' . $e->getMessage());

            return 0;
        }
    }
}
