<?php
declare(strict_types=1);

namespace Modracx\FrontendDevTools\Plugin\View;

use Magento\Framework\View\Element\AbstractBlock;
use Magento\Framework\View\Element\Template;
use Modracx\FrontendDevTools\Model\AccessGate;
use Modracx\FrontendDevTools\Model\Config;
use Modracx\FrontendDevTools\Model\ProfileContext;
use Modracx\FrontendDevTools\Model\StackTrace;

/**
 * Times every block, and keeps the shape of the tree while doing it.
 *
 * Wrapping toHtml() gives inclusive time, which is nearly useless on its own: the root
 * container always "takes" the whole page. Each frame therefore reports the time it spent
 * back to its parent, so what gets recorded is exclusive time — the milliseconds that block
 * and its template are actually responsible for.
 *
 * The output size is recorded too. A block that renders 400 KB of HTML costs the visitor
 * more than the profiler can see in milliseconds.
 */
class BlockRender
{
    /** @var list<float> */
    private array $childMs = [];

    /** @var list<string> */
    private array $names = [];

    private ?bool $active = null;

    private int $sequence = 0;

    public function __construct(
        private readonly ProfileContext $context,
        private readonly AccessGate $gate,
        private readonly Config $config,
        private readonly StackTrace $stackTrace
    ) {
    }

    public function aroundToHtml(AbstractBlock $subject, callable $proceed): string
    {
        if (!$this->isActive()) {
            return (string)$proceed();
        }

        $depth = count($this->childMs);
        $parent = $depth > 0 ? $this->names[$depth - 1] : null;
        $name = $subject->getNameInLayout() ?: $this->shortClass($subject);

        $this->childMs[] = 0.0;
        $this->names[] = (string)$name;

        $startedAt = microtime(true);
        $startMemory = memory_get_usage();

        try {
            $html = (string)$proceed();
        } finally {
            $totalMs = (microtime(true) - $startedAt) * 1000;
            $ownChildMs = (float)array_pop($this->childMs);
            array_pop($this->names);

            if ($this->childMs !== []) {
                $this->childMs[count($this->childMs) - 1] += $totalMs;
            }
        }

        // Charged from here, after the block's own work is done, so what is measured is this
        // module's bookkeeping and not the render it was watching.
        $selfStart = microtime(true);
        $class = $this->realClass($subject);

        $this->context->push('blocks', [
            'seq' => $this->sequence++,
            'name' => (string)$name,
            'parent' => $parent,
            'depth' => $depth,
            'class' => $class,
            'module' => $this->stackTrace->moduleForClass($class),
            'template' => $subject instanceof Template ? (string)$subject->getTemplate() : null,
            'ms' => round($totalMs, 3),
            'exclusive_ms' => round(max(0.0, $totalMs - $ownChildMs), 3),
            'bytes' => strlen($html),
            'memory' => memory_get_usage() - $startMemory,
            'cacheable' => $this->isCacheable($subject),
            'cache_lifetime' => $subject->getCacheLifetime(),
            'cache_key' => $this->safeCacheKey($subject),
        ]);

        $this->context->addOverhead('blocks', (microtime(true) - $selfStart) * 1000);

        return $html;
    }

    /**
     * A block declared cacheable="false" in layout is the single most common reason a page
     * that ought to be cached is not, so it is recorded per block rather than inferred once
     * for the page.
     */
    private function isCacheable(AbstractBlock $subject): bool
    {
        $data = $subject->getData('cacheable');

        return $data === null || (bool)$data;
    }

    private function safeCacheKey(AbstractBlock $subject): ?string
    {
        try {
            // getCacheKey() builds the key from block data, and third-party blocks are free
            // to throw from it. Asking is optional; rendering the page is not.
            return $subject->getCacheLifetime() !== null ? (string)$subject->getCacheKey() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function realClass(AbstractBlock $subject): string
    {
        return preg_replace('/\\\\Interceptor$/', '', get_class($subject)) ?? get_class($subject);
    }

    private function shortClass(AbstractBlock $subject): string
    {
        $parts = explode('\\', $this->realClass($subject));

        return (string)end($parts);
    }

    private function isActive(): bool
    {
        if ($this->context->isFrozen()) {
            return false;
        }

        return $this->active ??= $this->config->isEnabled()
            && $this->config->collects('blocks')
            && $this->gate->isAllowed();
    }
}
