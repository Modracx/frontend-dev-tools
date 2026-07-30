<?php
declare(strict_types=1);

namespace Modracx\FrontendDevTools\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Modracx\FrontendDevTools\Model\Storage\ProfileRepository;

/**
 * Read-only list of stored runs.
 *
 * A plain block rather than a UI component grid: there is no collection, no ORM model and no
 * editing to do, and a ui_component listing would be several hundred lines of XML to render
 * one table nobody sorts.
 */
class Runs extends Template
{
    protected $_template = 'Modracx_FrontendDevTools::runs.phtml';

    public function __construct(
        Context $context,
        private readonly ProfileRepository $repository,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRuns(): array
    {
        return $this->repository->recent(200);
    }

    public function ms(mixed $value): string
    {
        $ms = (float)$value;

        return $ms >= 1000 ? number_format($ms / 1000, 2) . ' s' : number_format($ms, 1) . ' ms';
    }
}
