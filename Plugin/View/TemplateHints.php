<?php
declare(strict_types=1);

namespace Modracx\FrontendDevTools\Plugin\View;

use Magento\Developer\Model\TemplateEngine\Decorator\DebugHintsFactory;
use Magento\Framework\View\TemplateEngineFactory;
use Magento\Framework\View\TemplateEngineInterface;
use Modracx\FrontendDevTools\Model\AccessGate;

/**
 * Template hints for one browser instead of one store.
 *
 * Magento's own switch lives at dev/debug/template_hints_storefront, which is store scoped:
 * turning it on to find a block also turns it on for every shopper on that store view, and
 * it survives being forgotten about. This decorates the template engine off the toolbar's
 * own cookie, so hints follow the developer rather than the store, expire on their own, and
 * are still behind the access gate — a visitor who cannot see the toolbar cannot turn them
 * on either.
 *
 * The decorator itself is Magento's, so the hints look exactly like the familiar ones.
 */
class TemplateHints
{
    public function __construct(
        private readonly AccessGate $gate,
        private readonly DebugHintsFactory $debugHintsFactory
    ) {
    }

    public function afterCreate(
        TemplateEngineFactory $subject,
        TemplateEngineInterface $result
    ): TemplateEngineInterface {
        if (!$this->gate->wantsTemplateHints()) {
            return $result;
        }

        return $this->debugHintsFactory->create([
            'subject' => $result,
            'showBlockHints' => $this->gate->wantsBlockHints(),
        ]);
    }
}
