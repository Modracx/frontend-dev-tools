<?php
declare(strict_types=1);

namespace Modracx\FrontendDevTools\Controller\Adminhtml\Runs;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Stored storefront runs, listed in the admin.
 *
 * The toolbar's own History tab is the better place to read a run, because it can render
 * every panel. This exists for the case that one cannot serve: looking at what the storefront
 * has been doing without browsing the storefront — from a different machine, or for requests
 * made by somebody else.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Modracx_FrontendDevTools::runs';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Modracx_FrontendDevTools::runs');
        $page->getConfig()->getTitle()->prepend(__('Storefront Profiler Runs'));

        return $page;
    }
}
