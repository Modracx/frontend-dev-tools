<?php
declare(strict_types=1);

namespace Modracx\FrontendDevTools\Plugin\Event;

use Magento\Framework\Event\Invoker\InvokerDefault;
use Magento\Framework\Event\Observer;
use Modracx\FrontendDevTools\Model\AccessGate;
use Modracx\FrontendDevTools\Model\Config;
use Modracx\FrontendDevTools\Model\ProfileContext;
use Modracx\FrontendDevTools\Model\StackTrace;

/**
 * Times each observer individually.
 *
 * Timing the event instead would only say that catalog_product_load_after is slow, which is
 * never the actionable form — a dozen modules subscribe to the popular events and exactly
 * one of them is usually the problem. The invoker is the narrowest point where the observer
 * that is about to run is still known by name.
 */
class ObserverInvoker
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
     * @param array<string, mixed> $configuration
     */
    public function aroundDispatch(
        InvokerDefault $subject,
        callable $proceed,
        array $configuration,
        Observer $observer
    ): mixed {
        if (!$this->isActive()) {
            return $proceed($configuration, $observer);
        }

        $startedAt = microtime(true);

        try {
            return $proceed($configuration, $observer);
        } finally {
            $selfStart = microtime(true);
            $class = (string)($configuration['instance'] ?? '');

            $this->context->push('observers', [
                'name' => (string)($configuration['name'] ?? ''),
                'class' => $class,
                'method' => (string)($configuration['method'] ?? 'execute'),
                'module' => $this->stackTrace->moduleForClass($class),
                'event' => $this->eventName($observer),
                'ms' => round((microtime(true) - $startedAt) * 1000, 3),
            ]);

            $this->context->addOverhead('observers', (microtime(true) - $selfStart) * 1000);
        }
    }

    private function eventName(Observer $observer): string
    {
        try {
            return (string)$observer->getEvent()->getName();
        } catch (\Throwable) {
            return '';
        }
    }

    private function isActive(): bool
    {
        if ($this->context->isFrozen()) {
            return false;
        }

        return $this->active ??= $this->config->isEnabled()
            && $this->config->collects('observers')
            && $this->gate->isAllowed();
    }
}
