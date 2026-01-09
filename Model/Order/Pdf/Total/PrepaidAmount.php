<?php

namespace Razorpay\Magento\Model\Order\Pdf\Total;

use Magento\Sales\Model\Order\Pdf\Total\DefaultTotal;

class PrepaidAmount extends DefaultTotal
{
    /**
     * Get Total amount from source
     *
     * @return float
     */
    public function getAmount()
    {
        $order = $this->getOrder();
        $prepaidAmount = $order->getData('razorpay_prepaid_amount');
        return $prepaidAmount ? (float)$prepaidAmount : 0;
    }
}

