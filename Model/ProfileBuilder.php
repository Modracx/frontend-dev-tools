<?php
declare(strict_types=1);

namespace Modracx\FrontendDevTools\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\App\State;
use Magento\Framework\Locale\ResolverInterface as LocaleResolver;
use Magento\Store\Model\StoreManagerInterface;
use Modracx\FrontendDevTools\Model\Analyzer\IssueDetector;
use Modracx\FrontendDevTools\Model\Analyzer\QueryAnalyzer;

/**
 * Assembles what the collectors recorded into the panels the toolbar shows.
 *
 * Everything expensive lives here rather than in the plugins: this runs once, after the
 * response is built, when spending a few milliseconds costs the visitor nothing because the
 * visitor is us.
 */
class ProfileBuilder
{
    public function __construct(
        private readonly ProfileContext $context,
        private readonly Config $config,
        private readonly QueryAnalyzer $queryAnalyzer,
        private readonly IssueDetector $issueDetector,
        private readonly StackTrace $stackTrace,
        private readonly HttpRequest $request,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ProductMetadataInterface $productMetadata,
        private readonly LocaleResolver $localeResolver,
        private readonly State $appState,
        private readonly ThemeStack $themeStack
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(?ResponseInterface $response = null): array
    {
        $queries = $this->backfillOrigins($this->context->all('queries'));
        $blocks = $this->context->all('blocks');
        $observers = $this->context->all('observers');
        $modules = $this->aggregateModules($blocks, $observers, $queries);

        $issues = $this->issueDetector->detect([
            'queries' => $queries,
            'blocks' => $blocks,
            'observers' => $observers,
            'modules' => $modules,
        ]);

        $summary = $this->summary($response, $queries, $blocks, $issues);

        return [
            'summary' => $summary,
            'queries' => $this->queriesPanel($queries),
            'blocks' => $this->blocksPanel($blocks),
            'observers' => $this->observersPanel($observers),
            'events' => $this->eventsPanel(),
            'layout' => $this->layoutPanel(),
            'theme' => $this->themePanel(),
            'cache' => $this->cachePanel($response),
            'modules' => $modules,
            'issues' => $issues,
            'environment' => $this->environmentPanel(),
        ];
    }

    /**
     * Give every statement the origin of its own shape.
     *
     * The collector only walks the stack for statements that are slow or that repeat, because
     * doing it for all of them costs more than the page being measured. Every execution of a
     * given shape came from the same place, though, so the one origin that was captured
     * describes the whole group — copying it across keeps per-module attribution honest
     * without paying for hundreds of stack walks.
     *
     * @param list<array<string, mixed>> $queries
     * @return list<array<string, mixed>>
     */
    private function backfillOrigins(array $queries): array
    {
        $origins = [];

        foreach ($queries as $query) {
            $fingerprint = (string)($query['fingerprint'] ?? '');

            if ($fingerprint !== '' && !isset($origins[$fingerprint]) && !empty($query['origin'])) {
                $origins[$fingerprint] = $query;
            }
        }

        foreach ($queries as &$query) {
            $fingerprint = (string)($query['fingerprint'] ?? '');

            if (!empty($query['origin']) || !isset($origins[$fingerprint])) {
                continue;
            }

            $source = $origins[$fingerprint];
            $query['origin'] = $source['origin'];
            $query['trail'] = $source['trail'];
            $query['is_userland'] = $source['is_userland'];
            $query['module'] = $source['module'];
            $query['origin_inferred'] = true;
        }

        return $queries;
    }

    /**
     * @param list<array<string, mixed>> $queries
     * @param list<array<string, mixed>> $blocks
     * @param array{userland: list<array<string, mixed>>, core: list<array<string, mixed>>} $issues
     * @return array<string, mixed>
     */
    private function summary(?ResponseInterface $response, array $queries, array $blocks, array $issues): array
    {
        $store = $this->safeStore();

        return [
            'token' => $this->context->token(),
            'url' => $this->request->getUriString(),
            'method' => $this->request->getMethod(),
            'full_action' => $this->fullActionName(),
            'graphql_operation' => $this->graphqlOperation(),
            'status_code' => $response?->getHttpResponseCode(),
            'is_ajax' => $this->request->isXmlHttpRequest(),
            'duration_ms' => round($this->context->elapsedMs(), 1),
            'memory_peak_kb' => (int)round(memory_get_peak_usage(true) / 1024),
            'query_count' => count($queries),
            'query_time_ms' => round((float)$this->context->meta('query_time_ms', 0.0), 1),
            'query_dropped' => $this->context->droppedCount('queries'),
            'block_count' => count($blocks),
            'event_count' => array_sum(array_column((array)$this->context->meta('event_totals', []), 'count')),
            'issue_count' => count($issues['userland']),
            'max_severity' => $this->maxSeverity($issues['userland']),
            'saving_ms' => round(array_sum(array_column($issues['userland'], 'saving_ms')), 1),
            'self' => $this->selfCost(),
            'theme' => $this->themeStack->activeThemePath(),
            'stack' => $this->themeStack->frontendStack(),
            'store' => $store?->getCode(),
            'store_id' => $store !== null ? (int)$store->getId() : null,
            'fpc' => $this->fpcStatus($response),
        ];
    }

    /**
     * What this module cost the page it is describing.
     *
     * Every number in this profile is inflated by the act of collecting it, and the honest
     * thing to do is say by how much rather than let the reader assume it is nothing. The
     * figure is the time spent inside this module's own bookkeeping — appending to arrays,
     * fingerprinting SQL, walking stacks — measured from inside each collector.
     *
     * Two things it deliberately does not claim to cover, both stated in the panel rather
     * than buried here:
     *
     *  - **Interception.** By the time this module's code runs, Magento has already paid the
     *    cost of routing the call through a plugin. Nothing measured from inside can see the
     *    cost of being called, so that overhead is real and is not in this number.
     *  - **Its own render.** The toolbar cannot time the drawing of the toolbar.
     *
     * @return array<string, mixed>
     */
    private function selfCost(): array
    {
        $inPage = $this->context->inPageOverheadMs();
        $elapsed = $this->context->elapsedMs();

        return [
            'in_page_ms' => round($inPage, 1),
            'pct' => $elapsed > 0 ? round(($inPage / $elapsed) * 100, 1) : 0.0,
            // What the page would have measured without this module's bookkeeping — still
            // not what it would cost uninstalled, for the interception reason above.
            'page_without_ms' => round(max(0.0, $elapsed - $inPage), 1),
            'buckets' => array_map(
                static fn (float $ms): float => round($ms, 2),
                $this->context->overhead()
            ),
        ];
    }

    /**
     * @param list<array<string, mixed>> $queries
     * @return array<string, mixed>
     */
    private function queriesPanel(array $queries): array
    {
        $grouped = array_values($this->queryAnalyzer->group($queries));

        // Sorted by cost, then cut: a page with 4,000 statements is not read top to bottom,
        // and the interesting ones are never at the end.
        $slowest = $queries;
        usort($slowest, static fn (array $a, array $b): int => ($b['ms'] ?? 0) <=> ($a['ms'] ?? 0));

        return [
            'total' => count($queries),
            'dropped' => $this->context->droppedCount('queries'),
            'total_ms' => round((float)$this->context->meta('query_time_ms', 0.0), 1),
            'slowest' => array_slice($slowest, 0, 300),
            'grouped' => array_slice($grouped, 0, 200),
            'threshold' => $this->config->threshold('slow_query'),
        ];
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return array<string, mixed>
     */
    private function blocksPanel(array $blocks): array
    {
        // Recorded on the way out, so the deepest block is first. Restoring the order they
        // were entered in is what makes the indented list read as a tree.
        usort($blocks, static fn (array $a, array $b): int => ($a['seq'] ?? 0) <=> ($b['seq'] ?? 0));

        $byTime = $blocks;
        usort($byTime, static fn (array $a, array $b): int => ($b['exclusive_ms'] ?? 0) <=> ($a['exclusive_ms'] ?? 0));

        return [
            'total' => count($blocks),
            'dropped' => $this->context->droppedCount('blocks'),
            'tree' => array_slice($blocks, 0, 500),
            'slowest' => array_slice($byTime, 0, 100),
            'uncacheable_blocks' => array_values(array_filter(
                $blocks,
                static fn (array $b): bool => ($b['cacheable'] ?? true) === false
            )),
            'threshold' => $this->config->threshold('slow_block'),
        ];
    }

    /**
     * @param list<array<string, mixed>> $observers
     * @return array<string, mixed>
     */
    private function observersPanel(array $observers): array
    {
        $totals = [];

        foreach ($observers as $observer) {
            $key = ($observer['name'] ?? '') . '@' . ($observer['event'] ?? '');
            $totals[$key] ??= [
                'name' => $observer['name'] ?? '',
                'event' => $observer['event'] ?? '',
                'class' => $observer['class'] ?? '',
                'module' => $observer['module'] ?? null,
                'count' => 0,
                'ms' => 0.0,
            ];
            $totals[$key]['count']++;
            $totals[$key]['ms'] += (float)($observer['ms'] ?? 0);
        }

        uasort($totals, static fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);

        return [
            'total' => count($observers),
            'grouped' => array_slice(array_values($totals), 0, 200),
            'threshold' => $this->config->threshold('slow_observer'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function eventsPanel(): array
    {
        $totals = (array)$this->context->meta('event_totals', []);

        uasort($totals, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return [
            'distinct' => count($totals),
            'total' => array_sum(array_column($totals, 'count')),
            'events' => array_slice(
                array_map(
                    static fn (string $name, array $row): array => $row + ['name' => $name],
                    array_keys($totals),
                    array_values($totals)
                ),
                0,
                300
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function layoutPanel(): array
    {
        return [
            'handles' => (array)$this->context->meta('handles', []),
            'xml_ms' => round((float)$this->context->meta('layout_xml_ms', 0.0), 1),
            'elements_ms' => round((float)$this->context->meta('layout_elements_ms', 0.0), 1),
            'uncacheable' => $this->context->all('uncacheable'),
            // Layouts explicitly constructed non-cacheable — the other half of the answer,
            // and the only half that exists when no block declared anything.
            'constructed_uncacheable' => $this->context->all('layout_optout'),
            'cacheable' => $this->context->meta('layout_cacheable'),
            'generated' => $this->context->layoutWasGenerated(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function themePanel(): array
    {
        $fallback = $this->context->all('fallback');
        $byType = [];

        foreach ($fallback as $entry) {
            $type = (string)($entry['type'] ?? 'other');
            $byType[$type][] = $entry;
        }

        $missing = array_values(array_filter($fallback, static fn (array $e): bool => !($e['found'] ?? true)));

        return [
            'chain' => $this->themeStack->inheritanceChain(),
            'active' => $this->themeStack->activeThemePath(),
            'stack' => $this->themeStack->frontendStack(),
            'resolved_count' => count($fallback),
            'by_type' => array_map(static fn (array $rows): array => array_slice($rows, 0, 300), $byType),
            'missing' => $missing,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cachePanel(?ResponseInterface $response): array
    {
        $uncacheable = $this->context->all('uncacheable');

        return [
            'status' => $this->fpcStatus($response),
            'cacheable' => $this->context->meta('layout_cacheable'),
            'application' => $this->fpcApplication(),
            'ttl' => $this->scopeConfig->getValue('system/full_page_cache/ttl'),
            // The X-Magento-Tags header is what invalidation is driven by, so seeing it is
            // how you find out why a product save cleared more than you expected.
            'tags' => $this->headerValue($response, 'X-Magento-Tags'),
            'cache_control' => $this->headerValue($response, 'Cache-Control'),
            'pragma' => $this->headerValue($response, 'Pragma'),
            'uncacheable_layout' => $uncacheable,
            'uncacheable_count' => count($uncacheable),
            'constructed_uncacheable' => $this->context->all('layout_optout'),
            'private_content_version' => $this->request->getCookie('private_content_version', null),
            'section_data_ids' => $this->request->getCookie('section_data_ids', null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function environmentPanel(): array
    {
        $store = $this->safeStore();

        return [
            'magento' => $this->productMetadata->getVersion(),
            'edition' => $this->productMetadata->getEdition(),
            'php' => PHP_VERSION,
            'mode' => $this->safeMode(),
            'locale' => $this->localeResolver->getLocale(),
            'store' => $store?->getCode(),
            'currency' => $store !== null ? $store->getCurrentCurrencyCode() : null,
            'theme' => $this->themeStack->activeThemePath(),
            'stack' => $this->themeStack->frontendStack(),
            'static_signing' => (bool)$this->scopeConfig->isSetFlag('dev/static/sign'),
            'js_minify' => (bool)$this->scopeConfig->isSetFlag('dev/js/minify_files'),
            'js_merge' => (bool)$this->scopeConfig->isSetFlag('dev/js/merge_files'),
            'js_bundling' => (bool)$this->scopeConfig->isSetFlag('dev/js/enable_js_bundling'),
            'css_minify' => (bool)$this->scopeConfig->isSetFlag('dev/css/minify_files'),
            'css_merge' => (bool)$this->scopeConfig->isSetFlag('dev/css/merge_css_files'),
            'template_hints_core' => (bool)$this->scopeConfig->isSetFlag('dev/debug/template_hints_storefront'),
            'memory_limit' => ini_get('memory_limit'),
            'xdebug' => extension_loaded('xdebug'),
            'opcache' => function_exists('opcache_get_status') && ini_get('opcache.enable'),
        ];
    }

    /**
     * Attribute time and queries to the module they came from.
     *
     * This is the view that answers "we installed four extensions last month and the
     * category page doubled" — a per-module total makes the culprit obvious in a way a flat
     * list of slow blocks never does.
     *
     * @param list<array<string, mixed>> $blocks
     * @param list<array<string, mixed>> $observers
     * @param list<array<string, mixed>> $queries
     * @return array<string, array<string, mixed>>
     */
    private function aggregateModules(array $blocks, array $observers, array $queries): array
    {
        $modules = [];

        $add = static function (array &$modules, ?string $module, string $key, float $value): void {
            if ($module === null || $module === '') {
                return;
            }

            $modules[$module] ??= [
                'block_ms' => 0.0,
                'observer_ms' => 0.0,
                'query_ms' => 0.0,
                'query_count' => 0,
                'block_count' => 0,
                'total_ms' => 0.0,
            ];
            $modules[$module][$key] += $value;
        };

        foreach ($blocks as $block) {
            $add($modules, $block['module'] ?? null, 'block_ms', (float)($block['exclusive_ms'] ?? 0));
            $add($modules, $block['module'] ?? null, 'block_count', 1);
        }

        foreach ($observers as $observer) {
            $add($modules, $observer['module'] ?? null, 'observer_ms', (float)($observer['ms'] ?? 0));
        }

        foreach ($queries as $query) {
            $module = $query['module'] ?? $this->stackTrace->moduleFor($query['origin'] ?? null);
            $add($modules, $module, 'query_ms', (float)($query['ms'] ?? 0));
            $add($modules, $module, 'query_count', 1);
        }

        foreach ($modules as &$stats) {
            $stats['total_ms'] = $stats['block_ms'] + $stats['observer_ms'] + $stats['query_ms'];
        }

        uasort($modules, static fn (array $a, array $b): int => $b['total_ms'] <=> $a['total_ms']);

        return $modules;
    }

    /**
     * @param list<array<string, mixed>> $issues
     */
    private function maxSeverity(array $issues): ?string
    {
        $order = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];
        $max = null;

        foreach ($issues as $issue) {
            if ($max === null || $order[$issue['severity']] > $order[$max]) {
                $max = $issue['severity'];
            }
        }

        return $max;
    }

    /**
     * Whether this response came out of the full page cache.
     *
     * A hit is inferred from layout never having run, rather than from a header, because
     * X-Magento-Cache-Debug only appears when Magento's own cache debugging is switched on —
     * and a toolbar that needs another setting turned on before it can answer is not much of
     * a toolbar.
     *
     * Whether a built page will be cached is not inferred at all: it is Layout::isCacheable(),
     * the same flag PageCache reads to decide whether to send public headers. "Miss" and
     * "will never be cached" call for very different reactions, so it is worth taking the
     * authoritative answer rather than a clever one.
     */
    private function fpcStatus(?ResponseInterface $response): string
    {
        if (!$this->context->layoutWasGenerated()) {
            return 'hit';
        }

        $cacheable = $this->context->meta('layout_cacheable');

        if ($cacheable === false) {
            return 'uncacheable';
        }

        if ($cacheable === null) {
            // Layout could not be asked. The response headers are the only remaining signal.
            $header = $this->headerValue($response, 'Cache-Control');

            return $header !== null && str_contains(strtolower($header), 'no-store')
                ? 'uncacheable'
                : 'unknown';
        }

        return 'miss';
    }

    private function fpcApplication(): string
    {
        return match ((string)$this->scopeConfig->getValue('system/full_page_cache/caching_application')) {
            '2' => 'varnish',
            '1' => 'builtin',
            default => 'unknown',
        };
    }

    private function headerValue(?ResponseInterface $response, string $name): ?string
    {
        if ($response === null || !method_exists($response, 'getHeader')) {
            return null;
        }

        $header = $response->getHeader($name);

        return $header ? (string)$header->getFieldValue() : null;
    }

    /**
     * Route identifier for this request.
     *
     * On a full page cache hit the router never runs, so there is no route, controller or
     * action to report — and a History list where every cached page is a blank row is not
     * worth having. The URL path is the only thing that survives a hit, so it stands in,
     * marked as a path so nobody mistakes it for an action name.
     */
    private function fullActionName(): string
    {
        $action = implode('_', array_filter([
            $this->request->getRouteName(),
            $this->request->getControllerName(),
            $this->request->getActionName(),
        ]));

        if ($action !== '') {
            return $action;
        }

        $path = trim((string)parse_url((string)$this->request->getRequestUri(), PHP_URL_PATH), '/');

        return $path === '' ? 'path:/' : 'path:/' . $path;
    }

    /**
     * The GraphQL operation, when this was a GraphQL request.
     *
     * Every GraphQL call shares one route, so without the operation name a History list of
     * them is a column of identical rows.
     */
    private function graphqlOperation(): ?string
    {
        if (!str_contains((string)$this->request->getRequestUri(), 'graphql')) {
            return null;
        }

        $name = $this->request->getParam('operationName');

        if (is_string($name) && $name !== '') {
            return $name;
        }

        try {
            $body = json_decode((string)$this->request->getContent(), true);
        } catch (\Throwable) {
            return null;
        }

        if (is_array($body) && !empty($body['operationName'])) {
            return (string)$body['operationName'];
        }

        // Unnamed operations still carry their shape in the first line of the document.
        if (is_array($body) && !empty($body['query']) && is_string($body['query'])
            && preg_match('/\b(query|mutation)\s+(\w+)/', $body['query'], $m)
        ) {
            return $m[2];
        }

        return null;
    }

    private function safeStore(): ?\Magento\Store\Api\Data\StoreInterface
    {
        try {
            return $this->storeManager->getStore();
        } catch (\Throwable) {
            return null;
        }
    }

    private function safeMode(): string
    {
        try {
            return $this->appState->getMode();
        } catch (\Throwable) {
            return 'unknown';
        }
    }
}
