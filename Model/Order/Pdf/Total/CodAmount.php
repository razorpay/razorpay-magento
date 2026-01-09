<?php

namespace Razorpay\Magento\Model\Order\Pdf\Total;

use Magento\Sales\Model\Order\Pdf\Total\DefaultTotal;

class CodAmount extends DefaultTotal
{
    /**
     * Get Total amount from source
     *
     * @return float
     */
    public function getAmount()
    {
        $order = $this->getOrder();
        $codAmount = $order->getData('razorpay_cod_amount');
        return $codAmount ? (float)$codAmount : 0;
    }
}

