<?php
declare(strict_types=1);

namespace Modracx\FrontendDevTools\Plugin\App;

use Magento\Framework\App\Http;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\View\Element\BlockFactory;
use Modracx\FrontendDevTools\Block\Toolbar;
use Modracx\FrontendDevTools\Model\AccessGate;
use Modracx\FrontendDevTools\Model\Config;
use Modracx\FrontendDevTools\Model\ProfileBuilder;
use Modracx\FrontendDevTools\Model\ProfileContext;
use Modracx\FrontendDevTools\Model\Storage\ProfileRepository;
use Psr\Log\LoggerInterface;

/**
 * Puts the toolbar on the page — without the page having to know about it.
 *
 * Injecting from a layout handle would mean shipping layout XML for Luma, for Hyvä and for
 * Breeze, and would still miss any theme that resets the base layout. Appending to the
 * finished response instead means the markup arrives on every storefront page of every
 * theme, including ones written after this module.
 *
 * The timing matters as much as the position. Built-in full page cache stores the response
 * during dispatch, which has already happened by the time launch() returns — so the copy
 * that goes into the cache never contains the toolbar, and no visitor is ever served a
 * cached page with a developer bar bolted to it.
 */
class ToolbarInjector
{
    /**
     * Our own endpoints answer the toolbar; profiling them would be a hall of mirrors.
     */
    private const OWN_ROUTE = 'modracx_fdt';

    public function __construct(
        private readonly ProfileContext $context,
        private readonly AccessGate $gate,
        private readonly Config $config,
        private readonly ProfileBuilder $builder,
        private readonly ProfileRepository $repository,
        private readonly BlockFactory $blockFactory,
        private readonly HttpRequest $request,
        private readonly RemoteAddress $remoteAddress,
        private readonly LoggerInterface $logger
    ) {
    }

    public function afterLaunch(Http $subject, ResponseInterface $result): ResponseInterface
    {
        if (!$this->shouldRun()) {
            return $result;
        }

        try {
            // Nothing recorded from here on belongs to the page — the numbers would start
            // describing the profiler's own work.
            $this->context->freeze();

            $token = $this->context->token();

            // Timed even though it happens after the page is built: it still delays the
            // response reaching the browser, and a profiler that hides its own cost has no
            // business reporting anybody else's.
            $startedAt = microtime(true);
            $profile = $this->builder->build($result);
            $this->context->addOverhead('build', (microtime(true) - $startedAt) * 1000);

            $startedAt = microtime(true);
            $this->repository->save($token, $profile, $this->remoteAddress->getRemoteAddress() ?: null);
            $this->context->addOverhead('store', (microtime(true) - $startedAt) * 1000);

            if (method_exists($result, 'setHeader')) {
                // Lets the toolbar pick up profiles for requests that have no page of their
                // own: section loads, add-to-cart, GraphQL.
                $result->setHeader('X-Modracx-Profile', $token, true);
            }

            if ($this->isInjectable($result)) {
                $startedAt = microtime(true);
                $this->inject($result, $profile);
                $this->context->addOverhead('render', (microtime(true) - $startedAt) * 1000);
            }
        } catch (\Throwable $e) {
            $this->logger->debug('Modracx_FrontendDevTools could not render the toolbar: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function inject(ResponseInterface $result, array $profile): void
    {
        $body = (string)$result->getBody();
        $position = strripos($body, '</body>');

        if ($position === false) {
            return;
        }

        /** @var Toolbar $block */
        $block = $this->blockFactory->createBlock(Toolbar::class);
        $block->setTemplate('Modracx_FrontendDevTools::toolbar.phtml');
        $block->setProfile($profile);

        $html = $block->toHtml();

        if ($html === '') {
            return;
        }

        $result->setBody(substr($body, 0, $position) . $html . substr($body, $position));
    }

    private function shouldRun(): bool
    {
        if (!$this->config->isEnabled() || $this->request->getRouteName() === self::OWN_ROUTE) {
            return false;
        }

        return $this->gate->isAllowed();
    }

    /**
     * Only complete HTML documents get a toolbar.
     *
     * Appending markup to a JSON section-data response, an XML sitemap or an image would
     * corrupt it. Those requests are still profiled and still stored — they are read from
     * the History tab, where they do not have to be part of a document to be useful.
     */
    private function isInjectable(ResponseInterface $result): bool
    {
        if ($this->request->isXmlHttpRequest() || $this->request->isPost()) {
            return false;
        }

        if (method_exists($result, 'getHttpResponseCode')) {
            $code = (int)$result->getHttpResponseCode();

            // A redirect has a body nobody sees, and injecting into an error page tends to
            // add a second confusing failure to the first one.
            if ($code >= 300) {
                return false;
            }
        }

        $contentType = method_exists($result, 'getHeader') ? $result->getHeader('Content-Type') : null;

        if ($contentType && !str_contains(strtolower((string)$contentType->getFieldValue()), 'text/html')) {
            return false;
        }

        $body = (string)$result->getBody();

        return $body !== '' && stripos($body, '</body>') !== false;
    }
}
