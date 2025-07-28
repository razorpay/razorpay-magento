<?php

namespace Razorpay\Magento\Model;

use Magento\Framework\Model\AbstractModel;

class FailedOrderLog extends AbstractModel
{
    protected function _construct()
    {
        $this->_init(\Razorpay\Magento\Model\ResourceModel\FailedOrderLog::class);
    }
}
