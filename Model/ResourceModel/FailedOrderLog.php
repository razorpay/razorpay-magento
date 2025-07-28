<?php

namespace Razorpay\Magento\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class FailedOrderLog extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('razorpay_failed_order_log', 'id'); // Table name and primary key
    }
}
