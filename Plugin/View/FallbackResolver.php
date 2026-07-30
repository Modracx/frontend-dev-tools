<?php
declare(strict_types=1);

namespace Modracx\FrontendDevTools\Plugin\View;

use Magento\Framework\View\Design\FileResolution\Fallback\ResolverInterface;
use Magento\Framework\View\Design\ThemeInterface;
use Modracx\FrontendDevTools\Model\AccessGate;
use Modracx\FrontendDevTools\Model\Config;
use Modracx\FrontendDevTools\Model\ProfileContext;
use Modracx\FrontendDevTools\Model\StackTrace;

/**
 * Records which physical file won every fallback lookup on this page.
 *
 * Template hints answer "which template rendered this block". They do not answer the
 * question that actually costs an afternoon, which is "why is it *that* copy of the
 * template" — the one in the parent theme, or the module's own, rather than the override
 * that was just written. Every resolve() call passes through here, so the Theme tab can
 * show the requested file, the theme it came from, and the paths an override could take.
 */
class FallbackResolver
{
    private ?bool $active = null;

    public function __construct(
        private readonly ProfileContext $context,
        private readonly AccessGate $gate,
        private readonly Config $config,
        private readonly StackTrace $stackTrace
    ) {
    }

    /**
     * @param string $type
     * @param string $file
     * @param string|null $area
     * @param string|null $locale
     * @param string|null $module
     * @param string|false $result
     * @return string|false
     */
    public function afterResolve(
        ResolverInterface $subject,
        $result,
        $type,
        $file,
        $area = null,
        ?ThemeInterface $theme = null,
        $locale = null,
        $module = null
    ) {
        if (!$this->isActive()) {
            return $result;
        }

        $selfStart = microtime(true);

        try {
            $resolved = is_string($result) && $result !== '' ? $result : null;

            $this->context->push('fallback', [
                'type' => (string)$type,
                'file' => (string)$file,
                'module' => $module !== null ? (string)$module : null,
                'area' => $area !== null ? (string)$area : null,
                'requested_theme' => $theme?->getCode() ?: ($theme?->getThemePath() ?: null),
                'locale' => $locale !== null ? (string)$locale : null,
                'resolved' => $resolved !== null ? $this->stackTrace->relative($resolved) : null,
                // Which theme actually served it, which is the whole point of the panel.
                'resolved_theme' => $resolved !== null ? $this->themeOf($resolved) : null,
                'found' => $resolved !== null,
            ]);
        } catch (\Throwable) {
            // Never let bookkeeping stop a file from being served.
        } finally {
            $this->context->addOverhead('fallback', (microtime(true) - $selfStart) * 1000);
        }

        return $result;
    }

    /**
     * Read the owning theme straight out of the resolved path.
     *
     * app/design and vendor themes both encode Vendor/theme in the path, and a resolved file
     * that matches neither came from a module's own view directory — which is itself the
     * answer when someone expects a theme override to be in play.
     */
    private function themeOf(string $path): string
    {
        if (preg_match('#/(?:app/design|vendor/[^/]+/[^/]+/)?(?:frontend|adminhtml)/([A-Za-z0-9_]+)/([A-Za-z0-9_-]+)/#', $path, $m)) {
            return $m[1] . '/' . $m[2];
        }

        if (preg_match('#/view/(frontend|adminhtml|base)/#', $path)) {
            return 'module';
        }

        return 'unknown';
    }

    private function isActive(): bool
    {
        if ($this->context->isFrozen()) {
            return false;
        }

        return $this->active ??= $this->config->isEnabled()
            && $this->config->collects('fallback')
            && $this->gate->isAllowed();
    }
}
