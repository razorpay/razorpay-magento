<?php

namespace Razorpay\Magento\Model\Source;

class BillingInterval extends \Magento\Eav\Model\Entity\Attribute\Source\AbstractSource
{
    public function toOptionArray()
    {
        return [
            [
                'value' => 'month',
                'label' => 'Months',
                'order' => 10
            ],
            [
                'value' => 'week',
                'label' => 'Weeks',
                'order' => 20
            ],
            [
                'value' => 'year',
                'label' => 'Years',
                'order' => 30
            ],
            [
                'value' => 'day',
                'label' => 'Days',
                'order' => 40
            ]
        ];
    }

    public function getAllOptions()
    {
        if ($this->_options === null)
            $this->_options = $this->toOptionArray();

        return $this->_options;
    }
}
