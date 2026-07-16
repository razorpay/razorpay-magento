<?php
/**
 * Fixture: a real sales_order + quote, paid via the "razorpay" payment method,
 * plus its razorpay_sales_order (OrderLink) row -- the minimum real-DB state
 * the Cron/CancelPendingOrders and Cron/UpdateOrdersToProcessing* classes act on.
 *
 * Loaded via @magentoDataFixture Razorpay_Magento::Test/Integration/_files/order_with_razorpay_payment.php
 * Rolled back automatically by @magentoDataFixture's transaction wrapper
 * (registry key below marks it eligible for standard order/quote cleanup).
 */

use Magento\Framework\Registry;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use Magento\TestFramework\Helper\Bootstrap;
use Razorpay\Magento\Model\OrderLink;

/** @var \Magento\Framework\ObjectManagerInterface $objectManager */
$objectManager = Bootstrap::getObjectManager();

/** @var Quote $quote */
$quote = $objectManager->create(Quote::class);
$quote->setStoreId(1)
    ->setIsActive(false)
    ->setIsMultiShipping(0)
    ->setReservedOrderId('razorpay_test_order_1')
    ->setCustomerIsGuest(1)
    ->setCustomerEmail('razorpay-integration-test@example.com')
    ->setCheckoutMethod('guest');
$objectManager->get(\Magento\Quote\Api\CartRepositoryInterface::class)->save($quote);

/** @var Order $order */
$order = $objectManager->create(Order::class);
$order->setIncrementId('900000001')
    ->setState(Order::STATE_PENDING_PAYMENT)
    ->setStatus('pending')
    ->setQuoteId($quote->getId())
    ->setStoreId(1)
    ->setCustomerIsGuest(1)
    ->setCustomerEmail('razorpay-integration-test@example.com')
    ->setBaseGrandTotal(100.00)
    ->setGrandTotal(100.00)
    ->setBaseCurrencyCode('INR')
    ->setOrderCurrencyCode('INR');

$payment = $objectManager->create(Order\Payment::class);
$payment->setMethod('razorpay');
$order->setPayment($payment);

$objectManager->get(\Magento\Sales\Api\OrderRepositoryInterface::class)->save($order);

/** @var OrderLink $orderLink */
$orderLink = $objectManager->create(OrderLink::class);
$orderLink->setOrderId($order->getEntityId())
    ->setRzpOrderId('order_INTEGRATIONTEST1')
    ->save();

$registry = $objectManager->get(Registry::class);
$registry->unregister('_fixture/Razorpay_Magento_Order_With_Payment');
$registry->register('_fixture/Razorpay_Magento_Order_With_Payment', $order);
