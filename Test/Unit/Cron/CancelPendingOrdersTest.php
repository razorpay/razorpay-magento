<?php

namespace Razorpay\Magento\Test\Unit\Cron;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\App\ObjectManager;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Razorpay\Magento\Cron\CancelPendingOrders;
use Razorpay\Magento\Model\Config;
use Razorpay\Magento\Model\OrderLink;
use Razorpay\Magento\Model\Util\DebugUtils;

class CancelPendingOrdersTest extends TestCase
{
    private OrderRepositoryInterface $orderRepository;
    private SearchCriteriaBuilder $searchCriteriaBuilder;
    private SortOrderBuilder $sortOrderBuilder;
    private OrderManagementInterface $orderManagement;
    private Config $config;
    private LoggerInterface $logger;
    private DebugUtils $debug;
    private \Magento\Framework\ObjectManagerInterface $objectManagerMock;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->sortOrderBuilder = $this->createMock(SortOrderBuilder::class);
        $this->orderManagement = $this->createMock(OrderManagementInterface::class);
        $this->config = $this->createMock(Config::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->debug = $this->createMock(DebugUtils::class);

        $sortOrder = $this->createMock(SortOrder::class);
        $this->sortOrderBuilder->method('setField')->willReturnSelf();
        $this->sortOrderBuilder->method('setDirection')->willReturnSelf();
        $this->sortOrderBuilder->method('create')->willReturn($sortOrder);

        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('setSortOrders')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')
            ->willReturn($this->createMock(SearchCriteriaInterface::class));

        // Cron/CancelPendingOrders.php calls ObjectManager::getInstance()->get(OrderLink::class)
        // directly inside isOrderAlreadyPaid(), so the static instance must be primed per test.
        $this->objectManagerMock = $this->createMock(\Magento\Framework\ObjectManagerInterface::class);
        ObjectManager::setInstance($this->objectManagerMock);
    }

    private function buildCron(
        bool $pendingCronEnabled = true,
        int $pendingTimeout = 30,
        int $pendingAge = 0,
        bool $resetCartCronEnabled = false,
        int $resetCartTimeout = 30
    ): CancelPendingOrders {
        $this->config->method('isCancelPendingOrderCronEnabled')->willReturn($pendingCronEnabled);
        $this->config->method('getPendingOrderTimeout')->willReturn($pendingTimeout);
        $this->config->method('getPendingOrderAge')->willReturn($pendingAge);
        $this->config->method('isCancelResetCartOrderCronEnabled')->willReturn($resetCartCronEnabled);
        $this->config->method('getResetCartOrderTimeout')->willReturn($resetCartTimeout);

        return new CancelPendingOrders(
            $this->orderRepository,
            $this->searchCriteriaBuilder,
            $this->sortOrderBuilder,
            $this->orderManagement,
            $this->config,
            $this->logger,
            $this->debug
        );
    }

    private function mockOrderLinkLookup(?int $webhookNotifiedAt): void
    {
        // getRzpWebhookNotifiedAt() is a magic getter via AbstractModel::__call(),
        // not a declared method, so it must be added explicitly to the mock.
        $orderLink = $this->getMockBuilder(OrderLink::class)
            ->disableOriginalConstructor()
            ->addMethods(['getRzpWebhookNotifiedAt'])
            ->getMock();
        $orderLink->method('getRzpWebhookNotifiedAt')->willReturn($webhookNotifiedAt);

        $collection = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['addFilter', 'getFirstItem'])
            ->getMock();
        $collection->method('addFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($orderLink);

        $orderLinkModel = $this->createMock(OrderLink::class);
        $orderLinkModel->method('getCollection')->willReturn($collection);

        $this->objectManagerMock->method('get')
            ->with('Razorpay\Magento\Model\OrderLink')
            ->willReturn($orderLinkModel);
    }

    private function mockOrder(bool $canCancel, string $paymentMethod = 'razorpay'): Order
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn($paymentMethod);

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('canCancel')->willReturn($canCancel);
        $order->method('getEntityId')->willReturn(101);
        $order->method('getIncrementId')->willReturn('000000101');

        return $order;
    }

    public function testExecuteWhenPendingOrderCronDisabledThenNoOrdersFetched(): void
    {
        $cron = $this->buildCron(pendingCronEnabled: false, resetCartCronEnabled: false);

        $this->orderRepository->expects($this->never())->method('getList');

        $cron->execute();
    }

    public function testExecuteWhenPendingOrderNotAlreadyPaidThenOrderIsCancelled(): void
    {
        $this->mockOrderLinkLookup(null);

        $order = $this->mockOrder(canCancel: true);
        $order->expects($this->once())->method('cancel')->willReturnSelf();
        $order->expects($this->once())
            ->method('setState')
            ->with(Order::STATE_CANCELED, Order::STATE_CANCELED, 'Payment Failed', false)
            ->willReturnSelf();
        $order->expects($this->once())->method('save');

        $searchResult = $this->createMock(OrderSearchResultInterface::class);
        $searchResult->method('getItems')->willReturn([$order]);
        $this->orderRepository->method('getList')->willReturn($searchResult);

        $cron = $this->buildCron(pendingCronEnabled: true, resetCartCronEnabled: false);
        $cron->execute();
    }

    public function testExecuteWhenOrderAlreadyPaidThenOrderIsNotCancelled(): void
    {
        // rzp_webhook_notified_at is set, meaning the webhook already confirmed payment;
        // the cron must not cancel an order that Razorpay has already marked as paid.
        $this->mockOrderLinkLookup(time());

        $order = $this->mockOrder(canCancel: true);
        $order->expects($this->never())->method('cancel');
        $order->expects($this->never())->method('save');

        $searchResult = $this->createMock(OrderSearchResultInterface::class);
        $searchResult->method('getItems')->willReturn([$order]);
        $this->orderRepository->method('getList')->willReturn($searchResult);

        $cron = $this->buildCron(pendingCronEnabled: true, resetCartCronEnabled: false);
        $cron->execute();
    }

    public function testExecuteWhenOrderCannotBeCancelledThenOrderIsSkipped(): void
    {
        // canCancel() === false (e.g. order already invoiced/shipped) must short-circuit
        // cancellation even if payment was never confirmed via webhook.
        $this->mockOrderLinkLookup(null);

        $order = $this->mockOrder(canCancel: false);
        $order->expects($this->never())->method('cancel');
        $order->expects($this->never())->method('save');

        $searchResult = $this->createMock(OrderSearchResultInterface::class);
        $searchResult->method('getItems')->willReturn([$order]);
        $this->orderRepository->method('getList')->willReturn($searchResult);

        $cron = $this->buildCron(pendingCronEnabled: true, resetCartCronEnabled: false);
        $cron->execute();
    }

    public function testExecuteWhenPaymentMethodIsNotRazorpayThenOrderIsIgnored(): void
    {
        $order = $this->mockOrder(canCancel: true, paymentMethod: 'checkmo');
        $order->expects($this->never())->method('cancel');

        $searchResult = $this->createMock(OrderSearchResultInterface::class);
        $searchResult->method('getItems')->willReturn([$order]);
        $this->orderRepository->method('getList')->willReturn($searchResult);

        $cron = $this->buildCron(pendingCronEnabled: true, resetCartCronEnabled: false);
        $cron->execute();
    }
}
