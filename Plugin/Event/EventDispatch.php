<?php
declare(strict_types=1);

namespace Modracx\FrontendDevTools\Plugin\Event;

use Magento\Framework\Event\ManagerInterface;
use Modracx\FrontendDevTools\Model\AccessGate;
use Modracx\FrontendDevTools\Model\Config;
use Modracx\FrontendDevTools\Model\ProfileContext;

/**
 * Counts and times event dispatches.
 *
 * Kept separate from the observer collector because they answer different questions: this
 * one shows an event fired 4,000 times in one request — usually a loop nobody meant to
 * write — which stays invisible when only the observers behind it are measured.
 *
 * Dispatches are aggregated by name rather than listed. A storefront page fires several
 * thousand, and a list of them is a wall of text, not a finding.
 */
class EventDispatch
{
    /** @var array<string, array{count: int, ms: float}> */
    private array $totals = [];

    private ?bool $active = null;

    public function __construct(
        private readonly ProfileContext $context,
        private readonly AccessGate $gate,
        private readonly Config $config
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function aroundDispatch(
        ManagerInterface $subject,
        callable $proceed,
        $eventName,
        array $data = []
    ): mixed {
        if (!$this->isActive()) {
            return $proceed($eventName, $data);
        }

        $startedAt = microtime(true);

        try {
            return $proceed($eventName, $data);
        } finally {
            $selfStart = microtime(true);
            $name = (string)$eventName;

            $this->totals[$name] ??= ['count' => 0, 'ms' => 0.0];
            $this->totals[$name]['count']++;
            // Inclusive: an observer that dispatches its own event has that time counted
            // under both names. The panel says so, because the alternative — attributing
            // nested time to nobody — hides the cost entirely.
            $this->totals[$name]['ms'] += (microtime(true) - $startedAt) * 1000;

            $this->context->setMeta('event_totals', $this->totals);
            $this->context->addOverhead('events', (microtime(true) - $selfStart) * 1000);
        }
    }

    private function isActive(): bool
    {
        if ($this->context->isFrozen()) {
            return false;
        }

        return $this->active ??= $this->config->isEnabled()
            && $this->config->collects('events')
            && $this->gate->isAllowed();
    }
}
