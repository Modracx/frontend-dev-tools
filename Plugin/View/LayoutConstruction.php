<?php
declare(strict_types=1);

namespace Modracx\FrontendDevTools\Plugin\View;

use Magento\Framework\View\LayoutFactory;
use Magento\Framework\View\LayoutInterface;
use Modracx\FrontendDevTools\Model\AccessGate;
use Modracx\FrontendDevTools\Model\Config;
use Modracx\FrontendDevTools\Model\ProfileContext;
use Modracx\FrontendDevTools\Model\StackTrace;

/**
 * Catches the other way a page becomes uncacheable.
 *
 * `Layout::isCacheable()` returns false for two quite different reasons: a generated block
 * declared `cacheable="false"`, or the layout object was simply *constructed* non-cacheable
 * before any block was considered. Only the first leaves a trace in layout XML, which is why
 * a page can report itself uncacheable while the layout panel truthfully says no block is
 * responsible — an answer that is correct and completely useless.
 *
 * `cacheable` is a constructor argument with no setter, so the only place to catch the second
 * case is where layouts are built. Core does it in several places (the cart coupon block, the
 * wishlist sharing block, the email template filter), and so do plenty of third-party
 * modules; recording the call site turns "something did this" into a file and a line.
 */
class LayoutConstruction
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
     * @param array<string, mixed> $data
     */
    public function afterCreate(
        LayoutFactory $subject,
        LayoutInterface $result,
        array $data = []
    ): LayoutInterface {
        if (!array_key_exists('cacheable', $data) || $data['cacheable'] || !$this->isActive()) {
            return $result;
        }

        try {
            $trace = $this->stackTrace->summarise(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20));

            $this->context->push('layout_optout', [
                'origin' => $trace['origin'],
                'trail' => $trace['trail'],
                'is_userland' => $trace['is_userland'],
                'module' => $this->stackTrace->moduleFor($trace['origin']),
            ]);
        } catch (\Throwable) {
            // Diagnostics are never worth a failed page.
        }

        return $result;
    }

    private function isActive(): bool
    {
        if ($this->context->isFrozen()) {
            return false;
        }

        return $this->active ??= $this->config->isEnabled()
            && $this->config->collects('layout')
            && $this->gate->isAllowed();
    }
}
