<?php

namespace Razorpay\Magento\Model;

use Magento\Sales\Model\Config\Source\Order\Status;

class Order extends Status
{
    protected $_stateStatuses = [
        \Magento\Sales\Model\Order::STATE_NEW,
        \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT,
        \Magento\Sales\Model\Order::STATE_PAYMENT_REVIEW,
        \Magento\Sales\Model\Order::STATE_PROCESSING,
        \Magento\Sales\Model\Order::STATE_COMPLETE,
        \Magento\Sales\Model\Order::STATE_CLOSED,
        \Magento\Sales\Model\Order::STATE_CANCELED,
        \Magento\Sales\Model\Order::STATE_HOLDED,
    ];
}
