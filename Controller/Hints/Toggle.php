<?php
declare(strict_types=1);

namespace Modracx\FrontendDevTools\Controller\Hints;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Modracx\FrontendDevTools\Model\AccessGate;

/**
 * Turns template hints on for this browser and nobody else's.
 */
class Toggle implements HttpPostActionInterface
{
    private const MODES = ['off', 'on', 'blocks'];

    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly AccessGate $gate
    ) {
    }

    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();

        if (!$this->gate->isAllowed()) {
            return $result->setHttpResponseCode(403)->setData(['success' => false]);
        }

        $mode = (string)$this->request->getParam('mode');

        if (!in_array($mode, self::MODES, true)) {
            return $result->setHttpResponseCode(400)->setData(['success' => false]);
        }

        $this->gate->setTemplateHints($mode);

        return $result->setData(['success' => true, 'mode' => $mode]);
    }
}
