<?php

declare(strict_types=1);

namespace Razorpay\Magento\Test\Integration\Cron;

use Magento\Framework\Registry;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use Razorpay\Magento\Cron\UpdateOrdersToProcessingV2;
use Razorpay\Magento\Model\OrderLink;

/**
 * Second half of the webhook -> order-status-transition flow: once
 * Controller/Payment/Webhook has persisted rzp_webhook_data and
 * rzp_webhook_notified_at (see Test/Integration/Controller/Payment/WebhookTest.php),
 * this cron is what actually reads that data and moves the real order to
 * "processing" against the live database.
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation disabled
 */
class UpdateOrdersToProcessingV2Test extends TestCase
{
    private \Magento\Framework\ObjectManagerInterface $objectManager;
    private OrderRepositoryInterface $orderRepository;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->orderRepository = $this->objectManager->get(OrderRepositoryInterface::class);
    }

    private function loadFixtureOrder(): Order
    {
        $registry = $this->objectManager->get(Registry::class);
        $order = $registry->registry('_fixture/Razorpay_Magento_Order_With_Payment');
        return $this->objectManager->create(Order::class)->load($order->getEntityId());
    }

    private function loadOrderLink(int $orderId): OrderLink
    {
        return $this->objectManager->create(OrderLink::class)
            ->getCollection()
            ->addFilter('order_id', $orderId)
            ->getFirstItem();
    }

    /**
     * Backdates rzp_webhook_notified_at directly via SQL so it falls outside
     * the cron's PROCESS_ORDER_WAIT_TIME (5 minute) grace window -- the cron
     * intentionally skips orders whose webhook just arrived, to give the
     * webhook-driven flow a chance to settle first.
     */
    private function backdateWebhookNotifiedAt(OrderLink $orderLink, int $secondsAgo): void
    {
        $orderLink->setRzpWebhookNotifiedAt(time() - $secondsAgo)->save();
    }

    /**
     * @magentoDataFixture Razorpay_Magento::Test/Integration/_files/order_with_razorpay_payment.php
     * @magentoConfigFixture current_store payment/razorpay/auto_invoice 0
     */
    public function testExecuteWhenOrderPaidWebhookDataPresentThenRealOrderTransitionsToProcessing(): void
    {
        $order = $this->loadFixtureOrder();
        // Cron only picks up orders in 'new' or 'processing' state.
        $order->setState(Order::STATE_NEW)->setStatus('pending');
        $this->orderRepository->save($order);

        $orderLink = $this->loadOrderLink($order->getEntityId());
        $webhookData = serialize([
            'order.paid' => ['payment_id' => 'pay_V2INTEGRATION', 'amount' => 10000],
        ]);
        $orderLink->setRzpWebhookData($webhookData)->save();
        $this->backdateWebhookNotifiedAt($orderLink, 600);

        /** @var UpdateOrdersToProcessingV2 $cron */
        $cron = $this->objectManager->create(UpdateOrdersToProcessingV2::class);
        $cron->execute();

        $reloadedOrder = $this->orderRepository->get($order->getEntityId());
        $this->assertSame(Order::STATE_PROCESSING, $reloadedOrder->getState());

        $reloadedOrderLink = $this->loadOrderLink($order->getEntityId());
        $this->assertSame('pay_V2INTEGRATION', $reloadedOrderLink->getRzpPaymentId());
    }

    /**
     * @magentoDataFixture Razorpay_Magento::Test/Integration/_files/order_with_razorpay_payment.php
     */
    public function testExecuteWhenWebhookNotifiedRecentlyThenOrderIsNotYetProcessed(): void
    {
        // Inside the 5-minute grace window -- cron must leave the order alone
        // on this run so the webhook-driven Validate/Callback flow (if any)
        // has a chance to complete first without racing the cron.
        $order = $this->loadFixtureOrder();
        $order->setState(Order::STATE_NEW)->setStatus('pending');
        $this->orderRepository->save($order);

        $orderLink = $this->loadOrderLink($order->getEntityId());
        $webhookData = serialize([
            'order.paid' => ['payment_id' => 'pay_RECENT', 'amount' => 10000],
        ]);
        $orderLink->setRzpWebhookData($webhookData)->save();
        $this->backdateWebhookNotifiedAt($orderLink, 30);

        /** @var UpdateOrdersToProcessingV2 $cron */
        $cron = $this->objectManager->create(UpdateOrdersToProcessingV2::class);
        $cron->execute();

        $reloadedOrder = $this->orderRepository->get($order->getEntityId());
        $this->assertNotSame(Order::STATE_PROCESSING, $reloadedOrder->getState());
    }

    /**
     * @magentoDataFixture Razorpay_Magento::Test/Integration/_files/order_with_razorpay_payment.php
     */
    public function testExecuteWhenNoWebhookDataYetThenOrderIsSkipped(): void
    {
        $order = $this->loadFixtureOrder();
        $order->setState(Order::STATE_NEW)->setStatus('pending');
        $this->orderRepository->save($order);

        $orderLink = $this->loadOrderLink($order->getEntityId());
        $this->backdateWebhookNotifiedAt($orderLink, 600);
        // rzp_webhook_data deliberately left empty.

        /** @var UpdateOrdersToProcessingV2 $cron */
        $cron = $this->objectManager->create(UpdateOrdersToProcessingV2::class);
        $cron->execute();

        $reloadedOrder = $this->orderRepository->get($order->getEntityId());
        $this->assertNotSame(Order::STATE_PROCESSING, $reloadedOrder->getState());
    }
}
