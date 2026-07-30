<?php
declare(strict_types=1);

namespace Modracx\FrontendDevTools\Controller\Access;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Modracx\FrontendDevTools\Model\AccessGate;

/**
 * Opts this browser in.
 *
 * A GET, because the whole point is that it can be pasted into an address bar — including on
 * a phone, or in the browser profile that is reproducing the bug. The token it checks is
 * derived from the installation's encryption key and printed by
 * `bin/magento modracx:frontend:token`, so it is never stored anywhere it could be read out
 * of, and rotating the key invalidates every link that was ever handed out.
 *
 * The IP allow list still applies afterwards. This grants the second of the three
 * conditions, not all of them.
 */
class Enable implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RedirectFactory $redirectFactory,
        private readonly AccessGate $gate
    ) {
    }

    public function execute(): ResultInterface
    {
        if ($this->gate->tokenMatches((string)$this->request->getParam('token'))) {
            $this->gate->grant();
        }

        // The same redirect either way. A wrong token should not be able to tell the person
        // holding it that they were close.
        return $this->redirectFactory->create()->setPath('/');
    }
}
