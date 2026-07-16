<?php
/**
 * Rollback for order_with_razorpay_payment.php.
 */

use Magento\Framework\Registry;
use Magento\TestFramework\Helper\Bootstrap;
use Razorpay\Magento\Model\OrderLink;

/** @var \Magento\Framework\ObjectManagerInterface $objectManager */
$objectManager = Bootstrap::getObjectManager();

/** @var Registry $registry */
$registry = $objectManager->get(Registry::class);

/** @var \Magento\Sales\Model\Order|null $order */
$order = $registry->registry('_fixture/Razorpay_Magento_Order_With_Payment');

if ($order && $order->getId()) {
    $orderLinkCollection = $objectManager->create(OrderLink::class)
        ->getCollection()
        ->addFilter('order_id', $order->getEntityId());
    foreach ($orderLinkCollection as $orderLink) {
        $orderLink->delete();
    }

    $quoteId = $order->getQuoteId();

    $order->delete();

    if ($quoteId) {
        try {
            $quote = $objectManager->create(\Magento\Quote\Model\Quote::class);
            $quote->load($quoteId);
            if ($quote->getId()) {
                $quote->delete();
            }
        } catch (\Exception $e) {
            // Quote already removed by another rollback script -- ignore.
        }
    }
}

$registry->unregister('_fixture/Razorpay_Magento_Order_With_Payment');
