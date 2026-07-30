<?php
declare(strict_types=1);

namespace Modracx\FrontendDevTools\Controller\Panel;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\BlockFactory;
use Modracx\FrontendDevTools\Block\Panel;
use Modracx\FrontendDevTools\Model\AccessGate;
use Modracx\FrontendDevTools\Model\PanelProvider;

/**
 * Renders one tab.
 *
 * POST only, and behind the same gate as the toolbar itself. That matters more here than it
 * does in the admin equivalent: these panels will happily describe the database, the theme
 * layout and the installed modules of a public storefront, so the endpoint has to be as
 * closed as the bar that calls it.
 */
class View implements HttpPostActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly BlockFactory $blockFactory,
        private readonly PanelProvider $panels,
        private readonly AccessGate $gate,
        private readonly Json $json
    ) {
    }

    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();

        if (!$this->gate->isAllowed()) {
            return $result->setHttpResponseCode(403)
                ->setData(['success' => false, 'message' => 'Not available.']);
        }

        $panel = (string)$this->request->getParam('panel');

        if (!$this->panels->exists($panel)) {
            return $result->setHttpResponseCode(404)
                ->setData(['success' => false, 'message' => 'No such panel.']);
        }

        $token = preg_replace('/[^a-f0-9]/', '', (string)$this->request->getParam('token')) ?? '';

        try {
            $data = $this->panels->data($panel, $token, [
                'client' => $this->clientEvents(),
                // The browser's own view of the page, which no amount of PHP can see.
                'stack_snapshot' => $this->stackSnapshot(),
                'vitals_snapshot' => $this->jsonParam('vitals') ?? [],
            ]);

            /** @var Panel $block */
            $block = $this->blockFactory->createBlock(Panel::class, ['data' => $data]);
            $block->setTemplate($this->panels->template($panel));

            return $result->setData(['success' => true, 'html' => $block->toHtml()]);
        } catch (\Throwable $e) {
            return $result->setData([
                'success' => false,
                'message' => 'Panel failed: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * What the browser saw, handed back to be rendered server-side.
     *
     * The events are carried on the panel request rather than beaconed as they happen, so a
     * page throwing in a loop cannot turn a JavaScript bug into a request flood.
     *
     * @return list<array<string, string>>
     */
    private function clientEvents(): array
    {
        $decoded = $this->jsonParam('client');

        if ($decoded === null) {
            return [];
        }

        $events = [];

        foreach (array_slice($decoded, 0, 200) as $event) {
            if (!is_array($event)) {
                continue;
            }

            $events[] = [
                'type' => substr((string)($event['type'] ?? 'js'), 0, 20),
                'message' => substr((string)($event['message'] ?? ''), 0, 500),
                'detail' => substr((string)($event['detail'] ?? ''), 0, 500),
            ];
        }

        return $events;
    }

    /**
     * The browser's snapshot of the JavaScript stack, rendered server-side like every other
     * panel so the templates stay in one language.
     *
     * @return array<string, mixed>
     */
    private function stackSnapshot(): array
    {
        $decoded = $this->jsonParam('stack');

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Decode a JSON request parameter, or null if it is absent or malformed.
     *
     * Anything arriving this way was assembled by a script in a page we do not control, so it
     * is treated as untrusted input and every value is cast and clipped before use.
     */
    private function jsonParam(string $name): ?array
    {
        $raw = (string)$this->request->getParam($name);

        if ($raw === '' || strlen($raw) > 262144) {
            return null;
        }

        try {
            $decoded = $this->json->unserialize($raw);
        } catch (\Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
