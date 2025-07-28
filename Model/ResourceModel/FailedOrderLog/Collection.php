<?php

namespace Razorpay\Magento\Model\ResourceModel\FailedOrderLog;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Razorpay\Magento\Model\FailedOrderLog as Model;
use Razorpay\Magento\Model\ResourceModel\FailedOrderLog as ResourceModel;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init(Model::class, ResourceModel::class);
    }
}
