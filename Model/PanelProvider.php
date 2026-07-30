<?php
declare(strict_types=1);

namespace Modracx\FrontendDevTools\Model;

use Modracx\FrontendDevTools\Model\Storage\ProfileRepository;

/**
 * Maps a tab to the template that draws it and the data it needs.
 *
 * Panels read from the stored run rather than from the live request, which is what lets the
 * History tab reuse every one of them without a second implementation: an overview of the
 * page you are on and an overview of a section-load from four minutes ago are the same
 * template pointed at a different token.
 */
class PanelProvider
{
    /**
     * Which stored collectors each panel needs. Loading only these is the difference between
     * opening a tab and dragging the entire query log out of the database to draw a heading.
     *
     * @var array<string, array{template: string, collectors: list<string>}>
     */
    private const PANELS = [
        'overview' => ['template' => 'panel/overview.phtml', 'collectors' => ['summary', 'issues', 'modules', 'cache']],
        'issues' => ['template' => 'panel/issues.phtml', 'collectors' => ['issues', 'summary']],
        'queries' => ['template' => 'panel/queries.phtml', 'collectors' => ['queries']],
        'blocks' => ['template' => 'panel/blocks.phtml', 'collectors' => ['blocks']],
        'layout' => ['template' => 'panel/layout.phtml', 'collectors' => ['layout']],
        'theme' => ['template' => 'panel/theme.phtml', 'collectors' => ['theme']],
        'cache' => ['template' => 'panel/cache.phtml', 'collectors' => ['cache', 'blocks']],
        'events' => ['template' => 'panel/events.phtml', 'collectors' => ['events', 'observers']],
        'modules' => ['template' => 'panel/modules.phtml', 'collectors' => ['modules', 'summary']],
        'environment' => ['template' => 'panel/environment.phtml', 'collectors' => ['environment', 'summary']],
        'client' => ['template' => 'panel/client.phtml', 'collectors' => ['client']],
        'vitals' => ['template' => 'panel/vitals.phtml', 'collectors' => ['summary']],
        'stack' => ['template' => 'panel/stack.phtml', 'collectors' => ['summary', 'cache']],
        'history' => ['template' => 'panel/history.phtml', 'collectors' => ['summary']],
    ];

    public function __construct(
        private readonly ProfileRepository $repository,
        private readonly ThemeStack $themeStack,
        private readonly Config $config,
        private readonly EnvironmentAudit $audit
    ) {
    }

    public function exists(string $panel): bool
    {
        return isset(self::PANELS[$panel]);
    }

    public function template(string $panel): string
    {
        return 'Modracx_FrontendDevTools::' . self::PANELS[$panel]['template'];
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public function data(string $panel, string $token, array $extra = []): array
    {
        $data = ['token' => $token, 'panel' => $panel, 'persisted' => $this->config->persists()];

        foreach (self::PANELS[$panel]['collectors'] as $collector) {
            $data[$collector] = $this->repository->loadCollector($token, $collector) ?? [];
        }

        if ($panel === 'history') {
            $data['runs'] = $this->repository->recent(60);
        }

        if ($panel === 'overview') {
            // The same route, last time it ran the same way. Answers the only question a
            // single set of numbers cannot: did this just get slower?
            $summary = $data['summary'] ?? [];
            $data['previous'] = $this->repository->previousRun(
                $token,
                $summary['full_action'] ?? null,
                $summary['fpc'] ?? null
            );
        }

        if ($panel === 'environment') {
            // Only here, never during a profiled request: the indexer checks cost queries,
            // and a profiler that adds queries to the page it measures is lying about it.
            $data['audit'] = $this->audit->run();
        }

        if ($panel === 'theme') {
            // The chain is read live rather than from the run: an override you have just
            // created should show up when you reopen the tab, not after another page load.
            $data['live_chain'] = $this->themeStack->inheritanceChain();
        }

        if ($panel === 'client') {
            // Two sources for the same list: what a previous page beaconed as it unloaded,
            // and what the page open right now is holding. Neither is complete on its own.
            $data['client'] = $this->dedupe(array_merge(
                is_array($data['client'] ?? null) ? $data['client'] : [],
                is_array($extra['client'] ?? null) ? $extra['client'] : []
            ));
            unset($extra['client']);
        }

        return $data + $extra;
    }

    /**
     * A page that throws in a loop reports the same error hundreds of times; showing it once
     * with a count is the difference between a panel and a wall.
     *
     * @param list<array<string, string>> $events
     * @return list<array<string, mixed>>
     */
    private function dedupe(array $events): array
    {
        $unique = [];

        foreach ($events as $event) {
            $key = ($event['type'] ?? '') . '|' . ($event['message'] ?? '') . '|' . ($event['detail'] ?? '');

            if (isset($unique[$key])) {
                $unique[$key]['count']++;
                continue;
            }

            $unique[$key] = $event + ['count' => 1];
        }

        return array_values($unique);
    }
}
