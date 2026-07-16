<?php

namespace Razorpay\Magento\Test\Unit\Cron;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\DB\Transaction;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction as PaymentTransaction;
use Magento\Sales\Model\Order\StatusResolver;
use Magento\Sales\Model\Service\InvoiceService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Razorpay\Magento\Constants\OrderCronStatus;
use Razorpay\Magento\Cron\UpdateOrdersToProcessingV2;
use Razorpay\Magento\Model\Config;
use Razorpay\Magento\Model\OrderLink;
use Razorpay\Magento\Model\Util\DebugUtils;

class UpdateOrdersToProcessingV2Test extends TestCase
{
    private OrderRepositoryInterface $orderRepository;
    private SearchCriteriaBuilder $searchCriteriaBuilder;
    private SortOrderBuilder $sortOrderBuilder;
    private CheckoutSession $checkoutSession;
    private OrderManagementInterface $orderManagement;
    private InvoiceService $invoiceService;
    private Transaction $transaction;
    private InvoiceSender $invoiceSender;
    private OrderSender $orderSender;
    private Config $config;
    private LoggerInterface $logger;
    private DebugUtils $debug;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->sortOrderBuilder = $this->createMock(SortOrderBuilder::class);
        $this->checkoutSession = $this->createMock(CheckoutSession::class);
        $this->orderManagement = $this->createMock(OrderManagementInterface::class);
        $this->invoiceService = $this->createMock(InvoiceService::class);
        $this->transaction = $this->createMock(Transaction::class);
        $this->invoiceSender = $this->createMock(InvoiceSender::class);
        $this->orderSender = $this->createMock(OrderSender::class);
        $this->config = $this->createMock(Config::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->debug = $this->createMock(DebugUtils::class);
    }

    /**
     * Primes the static ObjectManager instance the cron reads via
     * getObjectManager()->get('Razorpay\Magento\Model\OrderLink').
     *
     * That call happens once for the "list pending links" query, then once
     * more per order inside the loop for the "single order's link row" query
     * -- same class, two different collection shapes. $singleOrderLink is
     * reused for every per-order lookup, which is fine since these tests only
     * ever exercise a single order per run.
     *
     * @param array<int,array<string,mixed>> $listRows Rows returned by the
     *        outer collection's getData().
     */
    private function primeObjectManager(array $listRows, ?OrderLink $singleOrderLink = null): void
    {
        $statusResolver = $this->createMock(StatusResolver::class);
        $statusResolver->method('getOrderStatusByState')->willReturn('processing');

        $listCollection = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['addFieldToFilter', 'setOrder', 'setPageSize', 'getData'])
            ->getMock();
        $listCollection->method('addFieldToFilter')->willReturnSelf();
        $listCollection->method('setOrder')->willReturnSelf();
        $listCollection->method('setPageSize')->willReturnSelf();
        $listCollection->method('getData')->willReturn($listRows);

        $singleCollection = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['addFilter', 'getFirstItem'])
            ->getMock();
        $singleCollection->method('addFilter')->willReturnSelf();
        $singleCollection->method('getFirstItem')->willReturn($singleOrderLink);

        $orderLinkModel = $this->getMockBuilder(OrderLink::class)
            ->disableOriginalConstructor()
            ->getMock();
        $getCollectionCallCount = 0;
        $orderLinkModel->method('getCollection')->willReturnCallback(
            function () use (&$getCollectionCallCount, $listCollection, $singleCollection) {
                $getCollectionCallCount++;
                return $getCollectionCallCount === 1 ? $listCollection : $singleCollection;
            }
        );

        $objectManagerMock = $this->createMock(\Magento\Framework\ObjectManagerInterface::class);
        $objectManagerMock->method('get')->willReturnCallback(
            function ($class) use ($orderLinkModel, $statusResolver) {
                if ($class === 'Razorpay\Magento\Model\OrderLink') {
                    return $orderLinkModel;
                }
                if ($class === StatusResolver::class) {
                    return $statusResolver;
                }
                return null;
            }
        );

        ObjectManager::setInstance($objectManagerMock);
    }

    private function mockSingleOrderLink(int $cronStatus): OrderLink
    {
        $singleOrderLink = $this->getMockBuilder(OrderLink::class)
            ->disableOriginalConstructor()
            ->addMethods(['getRzpUpdateOrderCronStatus', 'setRzpUpdateOrderCronStatus', 'setRzpPaymentId'])
            ->onlyMethods(['save'])
            ->getMock();
        $singleOrderLink->method('getRzpUpdateOrderCronStatus')->willReturn($cronStatus);
        $singleOrderLink->method('setRzpUpdateOrderCronStatus')->willReturnSelf();
        $singleOrderLink->method('setRzpPaymentId')->willReturnSelf();

        return $singleOrderLink;
    }

    private function buildCron(): UpdateOrdersToProcessingV2
    {
        $this->config->method('getConfigData')->willReturn(null);
        $this->config->method('isCustomPaidOrderStatusEnabled')->willReturn(false);
        $this->config->method('canAutoGenerateInvoice')->willReturn(false);

        return new UpdateOrdersToProcessingV2(
            $this->orderRepository,
            $this->searchCriteriaBuilder,
            $this->sortOrderBuilder,
            $this->checkoutSession,
            $this->orderManagement,
            $this->invoiceService,
            $this->transaction,
            $this->invoiceSender,
            $this->orderSender,
            $this->config,
            $this->logger,
            $this->debug
        );
    }

    private function mockOrder(int $entityId, string $state, string $paymentMethod = 'razorpay'): Order
    {
        $currency = $this->createMock(\Magento\Directory\Model\Currency::class);
        $currency->method('formatTxt')->willReturn('$5.00');

        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getMethod',
                'setLastTransId',
                'setTransactionId',
                'setIsTransactionClosed',
                'setShouldCloseParentTransaction',
                'setParentTransactionId',
                'getTransactionId',
                'addTransactionCommentsToOrder',
                'addTransaction',
                'getExtensionAttributes',
            ])
            ->getMock();
        $payment->method('getMethod')->willReturn($paymentMethod);
        // CaptureCommand/AuthorizeCommand call getExtensionAttributes() to look
        // for a custom notification message; null means "use the default message".
        $payment->method('getExtensionAttributes')->willReturn(null);
        $payment->method('setLastTransId')->willReturnSelf();
        $payment->method('setTransactionId')->willReturnSelf();
        $payment->method('setIsTransactionClosed')->willReturnSelf();
        $payment->method('setShouldCloseParentTransaction')->willReturnSelf();
        $payment->method('setParentTransactionId')->willReturnSelf();
        $payment->method('getTransactionId')->willReturn('pay_TESTTRANSID');
        $payment->method('addTransactionCommentsToOrder')->willReturnSelf();

        $transactionMock = $this->getMockBuilder(PaymentTransaction::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setIsClosed', 'save'])
            ->getMock();
        $transactionMock->method('setIsClosed')->willReturnSelf();
        $payment->method('addTransaction')->willReturn($transactionMock);

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getPayment',
                'getEntityId',
                'getIncrementId',
                'getGrandTotal',
                'getBaseCurrency',
                'getState',
                'setState',
                'setStatus',
                'addStatusHistoryComment',
                'canInvoice',
                'save',
                'getQuoteId',
            ])
            ->getMock();
        $order->method('getPayment')->willReturn($payment);
        $order->method('getEntityId')->willReturn($entityId);
        $order->method('getIncrementId')->willReturn((string) (1000 + $entityId));
        $order->method('getGrandTotal')->willReturn(500.0);
        $order->method('getBaseCurrency')->willReturn($currency);
        $order->method('getState')->willReturn($state);
        $order->method('setState')->willReturnSelf();
        $order->method('setStatus')->willReturnSelf();
        $order->method('addStatusHistoryComment')->willReturnSelf();
        $order->method('canInvoice')->willReturn(false);
        $order->method('getQuoteId')->willReturn(500);

        return $order;
    }

    public function testExecuteWhenOrderPaidWebhookDataPresentThenOrderIsCapturedAndCronStatusAdvances(): void
    {
        $order = $this->mockOrder(entityId: 42, state: 'processing');
        $order->expects($this->once())->method('save');

        $this->orderRepository->method('get')->with(42)->willReturn($order);

        $webhookData = serialize([
            'order.paid' => ['payment_id' => 'pay_ABC123', 'amount' => 50000],
        ]);

        // canInvoice() is false in mockOrder(), so after being marked
        // PAYMENT_AUTHORIZED_COMPLETED the cron immediately re-marks it
        // INVOICE_GENERATION_NOT_POSSIBLE -- both calls are expected.
        $recordedStatuses = [];
        $singleOrderLink = $this->mockSingleOrderLink(OrderCronStatus::NEW_ORDER);
        $singleOrderLink->expects($this->exactly(2))
            ->method('setRzpUpdateOrderCronStatus')
            ->willReturnCallback(function ($status) use (&$recordedStatuses, $singleOrderLink) {
                $recordedStatuses[] = $status;
                return $singleOrderLink;
            });

        $this->primeObjectManager(
            [['order_id' => 42, 'rzp_webhook_data' => $webhookData]],
            $singleOrderLink
        );

        $cron = $this->buildCron();
        $cron->execute();

        $this->assertSame(
            [OrderCronStatus::PAYMENT_AUTHORIZED_COMPLETED, OrderCronStatus::INVOICE_GENERATION_NOT_POSSIBLE],
            $recordedStatuses
        );
    }

    public function testExecuteWhenNoOrderLinksPendingThenOrderRepositoryIsNeverQueried(): void
    {
        $this->primeObjectManager([]);

        $this->orderRepository->expects($this->never())->method('get');

        $cron = $this->buildCron();
        $cron->execute();
    }

    public function testExecuteWhenPaymentMethodIsNotRazorpayThenOrderIsSkipped(): void
    {
        $order = $this->mockOrder(entityId: 7, state: 'processing', paymentMethod: 'checkmo');
        $order->expects($this->never())->method('save');

        $this->orderRepository->method('get')->with(7)->willReturn($order);

        $webhookData = serialize([
            'order.paid' => ['payment_id' => 'pay_ABC123', 'amount' => 50000],
        ]);

        $singleOrderLink = $this->mockSingleOrderLink(OrderCronStatus::NEW_ORDER);
        $singleOrderLink->expects($this->never())->method('setRzpUpdateOrderCronStatus');

        $this->primeObjectManager(
            [['order_id' => 7, 'rzp_webhook_data' => $webhookData]],
            $singleOrderLink
        );

        $cron = $this->buildCron();
        $cron->execute();
    }

    public function testExecuteWhenWebhookDataEmptyThenOrderIsSkipped(): void
    {
        // rzp_webhook_data column not yet populated means the webhook cron
        // hasn't run for this order -- must not attempt to unserialize empty data.
        $order = $this->mockOrder(entityId: 9, state: 'processing');
        $order->expects($this->never())->method('save');

        $this->orderRepository->method('get')->with(9)->willReturn($order);

        $singleOrderLink = $this->mockSingleOrderLink(OrderCronStatus::NEW_ORDER);
        $singleOrderLink->expects($this->never())->method('setRzpUpdateOrderCronStatus');

        $this->primeObjectManager(
            [['order_id' => 9, 'rzp_webhook_data' => '']],
            $singleOrderLink
        );

        $cron = $this->buildCron();
        $cron->execute();
    }

    public function testExecuteWhenPaymentAuthorizedCronAlreadyCompletedThenRepeatIsFlaggedWithoutReprocessing(): void
    {
        // A payment.authorized event that arrives again after the cron already
        // advanced past PAYMENT_AUTHORIZED_COMPLETED must be recorded as a
        // repeat, not reprocessed as a fresh authorization.
        $order = $this->mockOrder(entityId: 15, state: 'processing');
        $order->expects($this->never())->method('save');

        $this->orderRepository->method('get')->with(15)->willReturn($order);

        $webhookData = serialize([
            'payment.authorized' => ['payment_id' => 'pay_REPEAT', 'amount' => 50000],
        ]);

        $singleOrderLink = $this->mockSingleOrderLink(OrderCronStatus::PAYMENT_AUTHORIZED_COMPLETED);
        $singleOrderLink->expects($this->once())
            ->method('setRzpUpdateOrderCronStatus')
            ->with(OrderCronStatus::PAYMENT_AUTHORIZED_CRON_REPEAT)
            ->willReturnSelf();
        $singleOrderLink->expects($this->once())->method('save');

        $this->primeObjectManager(
            [['order_id' => 15, 'rzp_webhook_data' => $webhookData]],
            $singleOrderLink
        );

        $cron = $this->buildCron();
        $cron->execute();
    }
}
