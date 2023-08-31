<?php
namespace Razorpay\Magento\Model\ResourceModel\OrderLink;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;


class Collection extends AbstractCollection
{
    /**
     * Initialize resource collection
     *
     * @return void
     */
    public function _construct()
    {
        $this->_init('Razorpay\Magento\Model\OrderLink', 'Razorpay\Magento\Model\ResourceModel\OrderLink');
    }
}