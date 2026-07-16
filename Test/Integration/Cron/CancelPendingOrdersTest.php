<?php

declare(strict_types=1);

namespace Razorpay\Magento\Test\Integration\Cron;

use Magento\Framework\Registry;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use Razorpay\Magento\Cron\CancelPendingOrders;
use Razorpay\Magento\Model\OrderLink;

/**
 * @magentoAppArea adminhtml
 * @magentoDbIsolation disabled
 */
class CancelPendingOrdersTest extends TestCase
{
    private \Magento\Framework\ObjectManagerInterface $objectManager;
    private OrderRepositoryInterface $orderRepository;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->orderRepository = $this->objectManager->get(OrderRepositoryInterface::class);
    }

    /**
     * Backdates an order's updated_at column directly via SQL, since
     * Order::save() always stamps the current time -- the cron's age filter
     * needs a real "old" row to select against.
     */
    private function backdateOrder(Order $order, int $minutesAgo): void
    {
        $connection = $order->getResource()->getConnection();
        $timestamp = date('Y-m-d H:i:s', strtotime("-{$minutesAgo} minutes"));
        $connection->update(
            $order->getResource()->getMainTable(),
            ['updated_at' => $timestamp],
            ['entity_id = ?' => $order->getEntityId()]
        );
    }

    private function loadFixtureOrder(): Order
    {
        /** @var Registry $registry */
        $registry = $this->objectManager->get(Registry::class);
        $order = $registry->registry('_fixture/Razorpay_Magento_Order_With_Payment');
        // Re-load through the repository so we see the DB state fresh, not
        // the in-memory fixture object mutated by earlier assertions.
        return $this->objectManager->create(Order::class)->load($order->getEntityId());
    }

    /**
     * @magentoDataFixture Razorpay_Magento::Test/Integration/_files/order_with_razorpay_payment.php
     * @magentoConfigFixture current_store payment/razorpay/enable_pending_orders_cron 1
     * @magentoConfigFixture current_store payment/razorpay/pending_orders_timeout 30
     */
    public function testExecuteWhenPendingOrderIsOlderThanTimeoutAndNotPaidThenOrderIsCancelled(): void
    {
        $order = $this->loadFixtureOrder();
        $order->setStatus('pending');
        $this->orderRepository->save($order);
        $this->backdateOrder($order, 60);

        /** @var CancelPendingOrders $cron */
        $cron = $this->objectManager->create(CancelPendingOrders::class);
        $cron->execute();

        $cancelledOrder = $this->orderRepository->get($order->getEntityId());
        $this->assertSame(Order::STATE_CANCELED, $cancelledOrder->getState());
    }

    /**
     * @magentoDataFixture Razorpay_Magento::Test/Integration/_files/order_with_razorpay_payment.php
     * @magentoConfigFixture current_store payment/razorpay/enable_pending_orders_cron 1
     * @magentoConfigFixture current_store payment/razorpay/pending_orders_timeout 30
     */
    public function testExecuteWhenOrderAlreadyConfirmedPaidViaWebhookThenOrderIsNotCancelled(): void
    {
        $order = $this->loadFixtureOrder();
        $order->setStatus('pending');
        $this->orderRepository->save($order);
        $this->backdateOrder($order, 60);

        // Real DB write of rzp_webhook_notified_at -- this is the exact
        // signal isOrderAlreadyPaid() checks against.
        $orderLinkCollection = $this->objectManager->create(OrderLink::class)
            ->getCollection()
            ->addFilter('order_id', $order->getEntityId());
        /** @var OrderLink $orderLink */
        foreach ($orderLinkCollection as $orderLink) {
            $orderLink->setRzpWebhookNotifiedAt(time())->save();
        }

        /** @var CancelPendingOrders $cron */
        $cron = $this->objectManager->create(CancelPendingOrders::class);
        $cron->execute();

        $reloadedOrder = $this->orderRepository->get($order->getEntityId());
        $this->assertNotSame(Order::STATE_CANCELED, $reloadedOrder->getState());
    }

    /**
     * @magentoDataFixture Razorpay_Magento::Test/Integration/_files/order_with_razorpay_payment.php
     * @magentoConfigFixture current_store payment/razorpay/enable_pending_orders_cron 1
     * @magentoConfigFixture current_store payment/razorpay/pending_orders_timeout 30
     */
    public function testExecuteWhenPendingOrderIsYoungerThanTimeoutThenOrderIsNotCancelled(): void
    {
        $order = $this->loadFixtureOrder();
        $order->setStatus('pending');
        $this->orderRepository->save($order);
        // Only 5 minutes old -- well inside the 30 minute timeout window.
        $this->backdateOrder($order, 5);

        /** @var CancelPendingOrders $cron */
        $cron = $this->objectManager->create(CancelPendingOrders::class);
        $cron->execute();

        $reloadedOrder = $this->orderRepository->get($order->getEntityId());
        $this->assertNotSame(Order::STATE_CANCELED, $reloadedOrder->getState());
    }

    /**
     * @magentoDataFixture Razorpay_Magento::Test/Integration/_files/order_with_razorpay_payment.php
     * @magentoConfigFixture current_store payment/razorpay/enable_pending_orders_cron 0
     */
    public function testExecuteWhenCronDisabledThenOrderIsNotCancelledEvenIfOldAndUnpaid(): void
    {
        $order = $this->loadFixtureOrder();
        $order->setStatus('pending');
        $this->orderRepository->save($order);
        $this->backdateOrder($order, 120);

        /** @var CancelPendingOrders $cron */
        $cron = $this->objectManager->create(CancelPendingOrders::class);
        $cron->execute();

        $reloadedOrder = $this->orderRepository->get($order->getEntityId());
        $this->assertNotSame(Order::STATE_CANCELED, $reloadedOrder->getState());
    }
}
