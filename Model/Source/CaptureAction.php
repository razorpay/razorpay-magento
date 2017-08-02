<?php 

namespace Razorpay\Magento\Model\Source;

use Razorpay\Magento\Model\PaymentMethod;

class CaptureAction implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * Possible actions on payment capture
     */
    public function toOptionArray()
    {
        return [
            [
                'value' => PaymentMethod::CAPTURE_ON_INVOICE,
                'label' => __('Invoice'),
            ],
            [
                'value' => PaymentMethod::CAPTURE_ON_SHIPMENT,
                'label' => __('Shipment'),
            ],
        ];
    }
}