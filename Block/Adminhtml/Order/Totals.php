<?php

namespace Razorpay\Magento\Block\Adminhtml\Order;

use Magento\Sales\Block\Adminhtml\Order\Totals as MagentoOrderTotals;
use Magento\Framework\DataObject;

class Totals extends MagentoOrderTotals
{
    public function _initTotals()
    {
        parent::_initTotals();
        $order = $this->getOrder();
        $customAmount = $order->getData('razorpay_cod_fee'); // Use your custom field here
        if ($customAmount) {
            $this->addTotal(new DataObject([
                'code'  => 'razorpay_cod_fee',
                'label' => __('COD Fee'),
                'value' => $customAmount,
                'area'  => 'footer'
            ]), 'shipping');
        }

        return $this;
    }
}
