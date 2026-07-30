<?php
declare(strict_types=1);

namespace Modracx\FrontendDevTools\Model;

use Magento\Framework\Filesystem\DirectoryList as RootDirectoryList;

/**
 * Turns a PHP backtrace into the one line a developer actually wants.
 *
 * "This query took 340ms" is not actionable; "this query took 340ms and your own
 * Vendor/Module/Block/Listing.php:88 asked for it" is. The rule is to walk outwards from
 * the framework until we reach code somebody on this project could edit, and report that
 * frame as the origin — with the frames in between kept as a trail, because sometimes the
 * interesting one is two hops further out.
 */
class StackTrace
{
    /**
     * Frames belonging to these paths are never reported as an origin. Ours is on the list
     * because a profiler that blames itself is of no use to anyone.
     */
    private const FRAMEWORK_MARKERS = [
        '/vendor/magento/framework/',
        '/vendor/magento/zendframework1/',
        '/vendor/laminas/',
        '/generated/code/',
        '/app/code/Modracx/FrontendDevTools/',
        '/lib/internal/',
    ];

    private const USERLAND_MARKERS = [
        '/app/code/',
        '/app/design/',
    ];

    private string $root;

    public function __construct(
        RootDirectoryList $directoryList
    ) {
        $this->root = rtrim($directoryList->getRoot(), '/') . '/';
    }

    /**
     * @param list<array<string, mixed>> $trace
     * @return array{origin: string|null, is_userland: bool, trail: list<string>}
     */
    public function summarise(array $trace, int $trailLength = 5): array
    {
        $trail = [];
        $origin = null;
        $isUserland = false;

        foreach ($trace as $frame) {
            $file = (string)($frame['file'] ?? '');

            if ($file === '' || $this->isFramework($file)) {
                continue;
            }

            $formatted = $this->relative($file) . ':' . (int)($frame['line'] ?? 0)
                . ($this->callee($frame) !== '' ? ' — ' . $this->callee($frame) : '');

            if ($origin === null || (!$isUserland && $this->isUserland($file))) {
                // Prefer real project code over a third-party vendor frame: a slow query
                // fired from a theme override is the theme's, even though the vendor library
                // is nearer the top of the stack.
                $origin = $formatted;
                $isUserland = $this->isUserland($file);
            }

            $trail[] = $formatted;

            if (count($trail) >= $trailLength) {
                break;
            }
        }

        return ['origin' => $origin, 'is_userland' => $isUserland, 'trail' => $trail];
    }

    /**
     * Best-effort module name for a path, used to aggregate time per module.
     */
    public function moduleFor(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (preg_match('#(?:app/code|vendor/[^/]+)/([A-Z][A-Za-z0-9]+)/([A-Z][A-Za-z0-9]+)/#', $path, $m)) {
            return $m[1] . '_' . $m[2];
        }

        if (preg_match('#app/design/(frontend|adminhtml)/([^/]+)/([^/]+)/#', $path, $m)) {
            return 'theme:' . $m[2] . '/' . $m[3];
        }

        return null;
    }

    public function moduleForClass(?string $class): ?string
    {
        if ($class === null || $class === '') {
            return null;
        }

        // Interceptors and proxies carry the real class in front of the suffix.
        $class = preg_replace('/\\\\(Interceptor|Proxy|Factory)$/', '', $class) ?? $class;
        $parts = explode('\\', $class);

        return count($parts) >= 2 ? $parts[0] . '_' . $parts[1] : null;
    }

    public function relative(string $path): string
    {
        return str_starts_with($path, $this->root) ? substr($path, strlen($this->root)) : $path;
    }

    private function isFramework(string $file): bool
    {
        foreach (self::FRAMEWORK_MARKERS as $marker) {
            if (str_contains($file, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function isUserland(string $file): bool
    {
        foreach (self::USERLAND_MARKERS as $marker) {
            if (str_contains($file, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $frame
     */
    private function callee(array $frame): string
    {
        $function = (string)($frame['function'] ?? '');

        if ($function === '') {
            return '';
        }

        $class = (string)($frame['class'] ?? '');

        return $class !== '' ? $this->shortClass($class) . '::' . $function . '()' : $function . '()';
    }

    private function shortClass(string $class): string
    {
        $parts = explode('\\', $class);

        return count($parts) > 2 ? $parts[0] . '\\…\\' . end($parts) : $class;
    }
}
