<?php

namespace Razorpay\Magento\Block\Adminhtml\Order\Invoice;

use Magento\Sales\Block\Adminhtml\Order\Invoice\Totals as MagentoInvoiceTotals;
use Magento\Framework\DataObject;

class Totals extends MagentoInvoiceTotals
{
    /**
     * Initialize order totals array
     *
     * @return $this
     */
    protected function _initTotals()
    {
        parent::_initTotals();
        
        $invoice = $this->getInvoice();
        if ($invoice) {
            $order = $invoice->getOrder();
            
            // Display prepaid and COD amounts separately for partial COD orders at invoice summary section
            $prepaidAmount = $order->getData('razorpay_prepaid_amount');
            $codAmount = $order->getData('razorpay_cod_amount');
            
            if ($prepaidAmount && $prepaidAmount > 0) {
                $this->addTotal(new DataObject([
                    'code'  => 'razorpay_prepaid_amount',
                    'label' => __('Partial COD - Partially Paid'),
                    'value' => $prepaidAmount,
                    'area'  => 'footer'
                ]), 'paid');
            }
            
            if ($codAmount && $codAmount > 0) {
                $this->addTotal(new DataObject([
                    'code'  => 'razorpay_cod_amount',
                    'label' => __('Partial COD - COD Amount'),
                    'value' => $codAmount,
                    'area'  => 'footer'
                ]), 'due');
            }
        }

        return $this;
    }
}

