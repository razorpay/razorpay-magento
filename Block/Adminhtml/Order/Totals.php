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

        // Display prepaid and COD amounts separately for partial COD orders at order summary section
        // $prepaidAmount = $order->getData('razorpay_prepaid_amount');
        // $codAmount = $order->getData('razorpay_cod_amount');
        
        // if ($prepaidAmount && $prepaidAmount > 0) {
        //     $this->addTotal(new DataObject([
        //         'code'  => 'razorpay_prepaid_amount',
        //         'label' => __('Partial COD - Partially Paid'),
        //         'value' => $prepaidAmount,
        //         'area'  => 'footer'
        //     ]), 'paid');
        // }
        
        // if ($codAmount && $codAmount > 0) {
        //     $this->addTotal(new DataObject([
        //         'code'  => 'razorpay_cod_amount',
        //         'label' => __('Partial COD - COD Amount'),
        //         'value' => $codAmount,
        //         'area'  => 'footer'
        //     ]), 'due');
        // }

        return $this;
    }
}
