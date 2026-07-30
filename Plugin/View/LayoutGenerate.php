<?php
declare(strict_types=1);

namespace Modracx\FrontendDevTools\Plugin\View;

use Magento\Framework\View\Layout;
use Modracx\FrontendDevTools\Model\AccessGate;
use Modracx\FrontendDevTools\Model\Config;
use Modracx\FrontendDevTools\Model\ProfileContext;

/**
 * Times the two layout phases and, more usefully, finds out what made the page uncacheable.
 *
 * Magento will tell you a page was not cached. It will not tell you which block did it, and
 * finding that by hand means grepping every layout file that could have applied to the
 * handles in play. The merged update XML already has the answer — one xpath over it lists
 * every node that declared cacheable="false", which on a storefront is nearly always the
 * whole explanation for a full page cache miss.
 */
class LayoutGenerate
{
    private ?bool $active = null;

    public function __construct(
        private readonly ProfileContext $context,
        private readonly AccessGate $gate,
        private readonly Config $config
    ) {
    }

    public function aroundGenerateXml(Layout $subject, callable $proceed): mixed
    {
        if (!$this->isActive()) {
            return $proceed();
        }

        $startedAt = microtime(true);
        $result = $proceed();
        $this->context->addMs('layout_xml_ms', (microtime(true) - $startedAt) * 1000);
        $this->context->markLayoutGenerated();

        $this->recordHandles($subject);

        return $result;
    }

    public function aroundGenerateElements(Layout $subject, callable $proceed): mixed
    {
        if (!$this->isActive()) {
            return $proceed();
        }

        $startedAt = microtime(true);
        $result = $proceed();
        $this->context->addMs('layout_elements_ms', (microtime(true) - $startedAt) * 1000);

        // Magento's own answer, taken at the same point Magento takes it: PageCache's
        // LayoutPlugin reads isCacheable() right here to decide whether to send public
        // headers. Inferring it instead from cacheable="false" nodes in the merged XML is
        // wrong — that XML contains declarations inside handles and references that never
        // produced a generated element, so pages that Magento happily caches come out
        // labelled uncacheable.
        try {
            $this->context->setMeta('layout_cacheable', $subject->isCacheable());
        } catch (\Throwable) {
            $this->context->setMeta('layout_cacheable', null);
        }

        // Recorded here rather than after generateXml because the structure does not exist
        // until elements have been generated, and whether a declaration is *in the structure*
        // is the entire difference between a node that made the page uncacheable and one that
        // merely exists in a handle.
        $this->recordUncacheable($subject);

        return $result;
    }

    private function recordHandles(Layout $subject): void
    {
        try {
            $this->context->setMeta('handles', array_values($subject->getUpdate()->getHandles()));
        } catch (\Throwable) {
            $this->context->setMeta('handles', []);
        }
    }

    /**
     * Find the declarations that opt out of full page caching.
     *
     * The xpath deliberately matches Magento's own in Layout::isCacheable() — `//block` with
     * `cacheable="false"`, then only counted if the named block is in the structure. Matching
     * `//*` instead, as an earlier version did, lists nodes Magento never considers and
     * produces a panel that contradicts the status beside it.
     */
    private function recordUncacheable(Layout $subject): void
    {
        try {
            $xml = $subject->getUpdate()->asSimplexml();
            $nodes = $xml->xpath('//block[@cacheable="false"]') ?: [];

            foreach ($nodes as $node) {
                $attributes = $node->attributes();
                $name = (string)($attributes['name'] ?? '');

                $this->context->push('uncacheable', [
                    'element' => $node->getName(),
                    'name' => $name,
                    'class' => (string)($attributes['class'] ?? ''),
                    'template' => (string)($attributes['template'] ?? ''),
                    // False means declared somewhere in the merged layout but never generated,
                    // so it is not why this page is uncacheable.
                    'in_play' => $name !== '' && $subject->hasElement($name),
                ]);
            }
        } catch (\Throwable) {
            // Layout XML that cannot be re-read is not worth failing a page render over.
        }
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
