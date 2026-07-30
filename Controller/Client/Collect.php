<?php
declare(strict_types=1);

namespace Modracx\FrontendDevTools\Controller\Client;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Modracx\FrontendDevTools\Model\AccessGate;
use Modracx\FrontendDevTools\Model\Storage\ProfileRepository;

/**
 * Receives what the browser saw, as the page is being left.
 *
 * Client-side errors are buffered in the page and normally travel with the next panel
 * request, which is free but loses everything if you navigate away without opening the
 * toolbar — and the errors worth catching are exactly the ones on pages you clicked
 * straight through. This endpoint exists for a `sendBeacon` on `pagehide`, so they end up
 * attached to the stored run either way.
 *
 * CSRF validation is waived deliberately: a beacon cannot carry a form key reliably during
 * page teardown, and the endpoint is behind the access gate, writes nothing but a text blob
 * onto a run that already exists, and is bounded in size. There is no state here worth
 * forging.
 */
class Collect implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly AccessGate $gate,
        private readonly ProfileRepository $repository,
        private readonly Json $json
    ) {
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();

        if (!$this->gate->isAllowed()) {
            return $result->setHttpResponseCode(403)->setData(['success' => false]);
        }

        $token = preg_replace('/[^a-f0-9]/', '', (string)$this->request->getParam('token')) ?? '';
        $raw = (string)$this->request->getParam('events');

        if ($token === '' || $raw === '' || strlen($raw) > 262144) {
            return $result->setData(['success' => false]);
        }

        try {
            $decoded = $this->json->unserialize($raw);
        } catch (\Throwable) {
            return $result->setData(['success' => false]);
        }

        if (!is_array($decoded)) {
            return $result->setData(['success' => false]);
        }

        $events = [];

        foreach (array_slice($decoded, 0, 200) as $event) {
            if (is_array($event)) {
                $events[] = [
                    'type' => substr((string)($event['type'] ?? 'js'), 0, 20),
                    'message' => substr((string)($event['message'] ?? ''), 0, 500),
                    'detail' => substr((string)($event['detail'] ?? ''), 0, 500),
                ];
            }
        }

        $this->repository->appendClientEvents($token, $events);

        return $result->setData(['success' => true, 'stored' => count($events)]);
    }
}
