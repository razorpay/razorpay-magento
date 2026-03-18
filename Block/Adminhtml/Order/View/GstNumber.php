<?php

namespace Razorpay\Magento\Block\Adminhtml\Order\View;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;

class GstNumber extends Template
{
    /**
     * @var Registry
     */
    protected $coreRegistry;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        array $data = []
    ) {
        $this->coreRegistry = $registry;
        parent::__construct($context, $data);
    }

    /**
     * Get GST number from order
     *
     * @return string|null
     */
    public function getGstNumber()
    {
        $order = $this->coreRegistry->registry('current_order');
        if (!$order) {
            $order = $this->coreRegistry->registry('sales_order');
        }

        return $order ? $order->getGstNumber() : null;
    }
}
