<?php
declare(strict_types=1);

namespace Modracx\FrontendDevTools\Controller\Access;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Modracx\FrontendDevTools\Model\AccessGate;

/**
 * Opts out — drops the access cookie and any template hint state with it.
 */
class Disable implements HttpPostActionInterface
{
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly AccessGate $gate
    ) {
    }

    public function execute(): ResultInterface
    {
        $this->gate->revoke();

        return $this->jsonFactory->create()->setData(['success' => true]);
    }
}
