<?php

namespace Razorpay\Magento\Model;

use Magento\Cron\Exception;
use Magento\Framework\Model\AbstractModel;

class OrderLink extends AbstractModel
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(\Razorpay\Magento\Model\ResourceModel\OrderLink::class);
    }
    
}
