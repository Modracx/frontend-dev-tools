<?php
declare(strict_types=1);

namespace Modracx\FrontendDevTools\Model;

use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\View\DesignInterface;
use Magento\Framework\View\Design\ThemeInterface;

/**
 * Everything about which theme is in play and what kind of storefront it builds.
 *
 * The three storefront stacks this module supports do not just look different, they debug
 * differently: Luma renders through RequireJS and Knockout, Hyvä through Alpine with the
 * whole section-data blob loaded at once, and Breeze re-implements Luma's markup on its own
 * JavaScript with PJAX navigation between pages. Panels that guess wrong give confidently
 * useless answers, so the stack is detected once, here, from the theme inheritance chain and
 * the module list rather than from the markup.
 */
class ThemeStack
{
    public const STACK_HYVA = 'hyva';
    public const STACK_BREEZE = 'breeze';
    public const STACK_LUMA = 'luma';
    public const STACK_UNKNOWN = 'unknown';

    /** Breeze's own authority on whether Breeze is active for this request. */
    private const BREEZE_HELPER = \Swissup\Breeze\Helper\Data::class;

    private ?string $stack = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $chain = null;

    public function __construct(
        private readonly DesignInterface $design,
        private readonly ModuleManager $moduleManager,
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    public function activeTheme(): ?ThemeInterface
    {
        try {
            $theme = $this->design->getDesignTheme();

            return $theme->getId() !== null || $theme->getThemePath() ? $theme : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function activeThemePath(): ?string
    {
        $theme = $this->activeTheme();

        return $theme !== null ? ($theme->getThemePath() ?: $theme->getCode()) : null;
    }

    /**
     * Active theme first, then each ancestor it inherits from.
     *
     * This is the order the fallback walks, so reading it top to bottom tells you exactly
     * which copy of a file wins and which ones are being shadowed.
     *
     * @return list<array<string, mixed>>
     */
    public function inheritanceChain(): array
    {
        if ($this->chain !== null) {
            return $this->chain;
        }

        $chain = [];
        $theme = $this->activeTheme();

        while ($theme !== null) {
            $chain[] = [
                'path' => $theme->getThemePath() ?: $theme->getCode(),
                'title' => $theme->getThemeTitle(),
                'area' => $theme->getArea(),
                'id' => $theme->getId(),
            ];

            try {
                $theme = $theme->getParentTheme();
            } catch (\Throwable) {
                $theme = null;
            }

            // A theme that lists itself as its own parent is a misconfiguration we should
            // report, not hang on.
            if ($theme !== null && count($chain) > 10) {
                break;
            }
        }

        return $this->chain = $chain;
    }

    /**
     * @return array{id: string, label: string, detail: string}
     */
    public function frontendStack(): array
    {
        $id = $this->stack ??= $this->detectStack();

        return match ($id) {
            self::STACK_HYVA => [
                'id' => $id,
                'label' => 'Hyvä',
                'detail' => 'Alpine.js and Tailwind. Section data loads as one blob with a one hour TTL.',
            ],
            self::STACK_BREEZE => [
                'id' => $id,
                'label' => 'Breeze',
                'detail' => 'Luma markup on Breeze\'s own JavaScript, with PJAX page transitions.',
            ],
            self::STACK_LUMA => [
                'id' => $id,
                'label' => 'Luma',
                'detail' => 'RequireJS and Knockout. Customer sections invalidate individually.',
            ],
            default => [
                'id' => $id,
                'label' => 'Unknown',
                'detail' => 'No recognised frontend stack — treating this as plain Magento markup.',
            ],
        };
    }

    private function detectStack(): string
    {
        $paths = array_map(
            static fn (array $theme): string => strtolower((string)($theme['path'] ?? '')),
            $this->inheritanceChain()
        );

        foreach ($paths as $path) {
            if (str_contains($path, 'hyva')) {
                return self::STACK_HYVA;
            }
        }

        if ($this->breezeIsRunning()) {
            return self::STACK_BREEZE;
        }

        foreach ($paths as $path) {
            if (str_contains($path, 'luma') || str_contains($path, 'blank') || str_contains($path, 'breeze')) {
                // A Breeze-named theme with Breeze switched off still renders Luma's markup
                // on Magento's own JavaScript, so that is what it is.
                return self::STACK_LUMA;
            }
        }

        return $paths === [] ? self::STACK_UNKNOWN : self::STACK_LUMA;
    }

    /**
     * Ask Breeze whether Breeze is running.
     *
     * Having the module installed proves nothing: `design/breeze/enabled` defaults to
     * `theme`, which means "only for themes that opt in", so a stock Luma storefront with
     * Breeze installed is still a Luma storefront. Breeze also stands down for excluded URLs,
     * unsupported pages, AMP requests and a `?breeze=0` debug override — none of which is
     * knowable from the module list or the theme name.
     *
     * Reimplementing that logic here is how this got it wrong the first time, so it is not
     * reimplemented: the module ships a helper whose entire job is to answer this question,
     * and the answer is taken from it. Resolved through the object manager rather than a
     * constructor argument because the class only exists when Breeze is installed.
     */
    private function breezeIsRunning(): bool
    {
        if (!$this->moduleManager->isEnabled('Swissup_Breeze')
            || !class_exists(self::BREEZE_HELPER)
        ) {
            return false;
        }

        try {
            return (bool)$this->objectManager->get(self::BREEZE_HELPER)->isEnabled();
        } catch (\Throwable) {
            // A helper that cannot answer is not grounds for claiming a stack.
            return false;
        }
    }
}
