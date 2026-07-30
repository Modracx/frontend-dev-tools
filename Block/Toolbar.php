<?php
declare(strict_types=1);

namespace Modracx\FrontendDevTools\Block;

use Magento\Framework\App\Area;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Locale\ResolverInterface as LocaleResolver;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Design\ThemeInterface;
use Magento\Framework\View\DesignInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Modracx\FrontendDevTools\Model\AccessGate;
use Modracx\FrontendDevTools\Model\Config;

/**
 * The shell that gets appended to the page.
 *
 * Only the launcher, the tab strip and a JSON blob of headline numbers are rendered inline.
 * Every panel body is fetched when its tab is opened, so a page that is already slow enough
 * to be worth profiling is not made slower by the thing measuring it.
 */
class Toolbar extends Template
{
    /**
     * @var array<string, array{label: string, title: string}>
     */
    private const PANELS = [
        'overview' => ['label' => 'Overview', 'title' => 'Headline numbers and the worst findings'],
        'issues' => ['label' => 'Issues', 'title' => 'What to fix, worst first'],
        'vitals' => ['label' => 'Vitals', 'title' => 'LCP, CLS and INP as the browser measured them'],
        'queries' => ['label' => 'SQL', 'title' => 'Every statement, grouped and timed'],
        'blocks' => ['label' => 'Blocks', 'title' => 'Block tree with exclusive render times'],
        'layout' => ['label' => 'Layout', 'title' => 'Handles and what made the page uncacheable'],
        'theme' => ['label' => 'Theme', 'title' => 'Fallback chain and which file won'],
        'cache' => ['label' => 'Cache', 'title' => 'Full page cache status, tags and private content'],
        'events' => ['label' => 'Events', 'title' => 'Dispatched events and observers'],
        'modules' => ['label' => 'Modules', 'title' => 'Time and queries attributed per module'],
        'client' => ['label' => 'Client', 'title' => 'JS errors, failed requests and CSP violations'],
        'stack' => ['label' => 'Stack', 'title' => 'Alpine, Breeze or RequireJS state as the browser sees it'],
        'environment' => ['label' => 'Env', 'title' => 'Mode, versions and frontend build settings'],
        'history' => ['label' => 'History', 'title' => 'Recent runs, including AJAX and GraphQL'],
    ];

    public function __construct(
        Context $context,
        private readonly Json $json,
        private readonly FormKey $formKey,
        private readonly AccessGate $gate,
        private readonly Config $config,
        private readonly DesignInterface $design,
        private readonly LocaleResolver $localeResolver,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * URL for one of this module's own static files.
     *
     * The toolbar is rendered after the response is built, which on a full page cache hit
     * means the design was never loaded: built-in FPC short-circuits dispatch before Magento
     * applies a theme, so DesignInterface is still holding an empty one. Every asset URL
     * derived from it then degrades to `frontend/_view/en_US/…` — Magento's placeholder for
     * "no theme" — which 404s, and takes the toolbar's stylesheet and script with it on
     * exactly the pages you browse most.
     *
     * The cure is to make Magento resolve the theme the way it would have during a normal
     * dispatch, then hand the resolved model straight to the asset repository. Passing a
     * theme *string* is not enough: an empty string is silently accepted and falls back to
     * the same unresolved model, which is how the first attempt at this fix still produced
     * `_view`.
     */
    public function assetUrl(string $fileId): string
    {
        try {
            return $this->_assetRepo->getUrlWithParams($fileId, [
                'area' => Area::AREA_FRONTEND,
                'themeModel' => $this->resolvedTheme(),
                'locale' => $this->localeResolver->getLocale(),
                '_secure' => $this->isSecure(),
            ]);
        } catch (\Throwable $e) {
            $this->_logger->debug('Modracx_FrontendDevTools could not build an asset URL: ' . $e->getMessage());

            return '';
        }
    }

    /**
     * The applied theme, applying the configured default first if nothing applied one.
     */
    private function resolvedTheme(): ThemeInterface
    {
        $theme = $this->design->getDesignTheme();

        if (!$theme->getThemePath() && !$theme->getId()) {
            // Exactly what a normal frontend request does when it loads the design area.
            $this->design->setDefaultDesignTheme();
            $theme = $this->design->getDesignTheme();
        }

        return $theme;
    }

    /**
     * @param array<string, mixed> $profile
     */
    public function setProfile(array $profile): self
    {
        return $this->setData('profile', $profile);
    }

    /**
     * @return array<string, mixed>
     */
    public function getSummary(): array
    {
        $profile = (array)$this->getData('profile');

        return (array)($profile['summary'] ?? []);
    }

    /**
     * @return array<string, array{label: string, title: string}>
     */
    public function getPanels(): array
    {
        return self::PANELS;
    }

    /**
     * Everything the toolbar's JavaScript needs, handed over as data rather than as code.
     *
     * A JSON script tag is inert, so this survives a restrictive content security policy —
     * which a storefront on 2.4.8 may well have — where an inline script would not.
     */
    public function getBootJson(): string
    {
        $summary = $this->getSummary();

        return $this->json->serialize([
            'token' => $summary['token'] ?? null,
            'panelUrl' => $this->getUrl('modracx_fdt/panel/view', ['_secure' => $this->isSecure()]),
            'hintsUrl' => $this->getUrl('modracx_fdt/hints/toggle', ['_secure' => $this->isSecure()]),
            'clientUrl' => $this->getUrl('modracx_fdt/client/collect', ['_secure' => $this->isSecure()]),
            'disableUrl' => $this->getUrl('modracx_fdt/access/disable', ['_secure' => $this->isSecure()]),
            'formKey' => $this->formKey->getFormKey(),
            'summary' => $summary,
            'panels' => self::PANELS,
            'hints' => $this->getHintsMode(),
            'collectClient' => $this->config->collects('client'),
            'stack' => $summary['stack']['id'] ?? 'unknown',
        ]);
    }

    public function getHintsMode(): string
    {
        return match (true) {
            $this->gate->wantsBlockHints() => 'blocks',
            $this->gate->wantsTemplateHints() => 'on',
            default => 'off',
        };
    }

    /**
     * Severity of the whole run, used to colour the launcher.
     *
     * The point of putting it on the launcher is that a problem you have to open something
     * to discover is one you will not discover.
     */
    public function getSeverityClass(): string
    {
        $summary = $this->getSummary();

        return 'mdx-fdt--' . ($summary['max_severity'] ?? 'clean');
    }

    private function isSecure(): bool
    {
        return $this->_request->isSecure();
    }
}
