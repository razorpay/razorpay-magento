<?php 

namespace Razorpay\Magento\Model;

use \Magento\Framework\Option\ArrayInterface;

class WebhookEvents implements ArrayInterface
{
    /**
     * {@inheritdoc}
     */
    public function toOptionArray()
    {
        return [
            [
                'value' => "order.paid",
                'label' => __('order.paid'),
            ],
            [
                'value' => "payment.authorized",
                'label' => __('payment.authorized'),
            ],
        ];
    }
}