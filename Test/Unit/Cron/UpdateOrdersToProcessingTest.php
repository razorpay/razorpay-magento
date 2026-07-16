<?php

namespace Razorpay\Magento\Test\Unit\Cron;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\DB\Transaction;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
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
use Razorpay\Magento\Cron\UpdateOrdersToProcessing;
use Razorpay\Magento\Model\Config;

class UpdateOrdersToProcessingTest extends TestCase
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

        $sortOrder = $this->createMock(SortOrder::class);
        $this->sortOrderBuilder->method('setField')->willReturnSelf();
        $this->sortOrderBuilder->method('setDirection')->willReturnSelf();
        $this->sortOrderBuilder->method('create')->willReturn($sortOrder);

        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('setSortOrders')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')
            ->willReturn($this->createMock(SearchCriteriaInterface::class));

        // AuthorizeCommand/CaptureCommand are instantiated with `new` inside
        // the cron's constructor, and their own constructors fall back to
        // ObjectManager::getInstance()->get(StatusResolver::class).
        // getObjectManager()->get('Magento\Quote\Model\Quote') is also called
        // directly inside updateOrderStatus() to deactivate the quote.
        $statusResolver = $this->createMock(StatusResolver::class);
        $statusResolver->method('getOrderStatusByState')->willReturn('processing');

        $quote = $this->getMockBuilder(\Magento\Quote\Model\Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setIsActive', 'save'])
            ->getMock();
        $quote->method('setIsActive')->willReturnSelf();

        $quoteModel = $this->getMockBuilder(\Magento\Quote\Model\Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load'])
            ->getMock();
        $quoteModel->method('load')->willReturn($quote);

        $objectManagerMock = $this->createMock(\Magento\Framework\ObjectManagerInterface::class);
        $objectManagerMock->method('get')->willReturnCallback(
            function ($class) use ($statusResolver, $quoteModel) {
                if ($class === StatusResolver::class) {
                    return $statusResolver;
                }
                if ($class === 'Magento\Quote\Model\Quote') {
                    return $quoteModel;
                }
                return null;
            }
        );
        ObjectManager::setInstance($objectManagerMock);
    }

    private function buildCron(bool $v1Enabled = true): UpdateOrdersToProcessing
    {
        $this->config->method('getConfigData')->willReturn(null);
        $this->config->method('isCustomPaidOrderStatusEnabled')->willReturn(false);
        $this->config->method('canAutoGenerateInvoice')->willReturn(false);
        $this->config->method('isUpdateOrderCronV1Enabled')->willReturn($v1Enabled);

        return new UpdateOrdersToProcessing(
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
            $this->logger
        );
    }

    /**
     * v1 stores webhook data as magic getters directly on the Order entity
     * (getRzpWebhookData/getRzpUpdateOrderCronStatus), not via a separate
     * OrderLink lookup like V2 does.
     */
    private function mockOrder(
        int $entityId,
        string $paymentMethod = 'razorpay',
        ?string $rzpWebhookData = null,
        int $cronStatus = 0
    ): Order {
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
        $payment->method('setLastTransId')->willReturnSelf();
        $payment->method('setTransactionId')->willReturnSelf();
        $payment->method('setIsTransactionClosed')->willReturnSelf();
        $payment->method('setShouldCloseParentTransaction')->willReturnSelf();
        $payment->method('setParentTransactionId')->willReturnSelf();
        $payment->method('getTransactionId')->willReturn('pay_TESTTRANSID');
        $payment->method('addTransactionCommentsToOrder')->willReturnSelf();
        $payment->method('getExtensionAttributes')->willReturn(null);

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
                'setState',
                'setStatus',
                'addStatusHistoryComment',
                'canInvoice',
                'save',
                'getQuoteId',
            ])
            ->addMethods(['getRzpWebhookData', 'getRzpUpdateOrderCronStatus', 'setRzpUpdateOrderCronStatus'])
            ->getMock();
        $order->method('getPayment')->willReturn($payment);
        $order->method('getEntityId')->willReturn($entityId);
        $order->method('getIncrementId')->willReturn((string) (1000 + $entityId));
        $order->method('getGrandTotal')->willReturn(500.0);
        $order->method('getBaseCurrency')->willReturn($currency);
        $order->method('setState')->willReturnSelf();
        $order->method('setStatus')->willReturnSelf();
        $order->method('addStatusHistoryComment')->willReturnSelf();
        $order->method('canInvoice')->willReturn(false);
        $order->method('getQuoteId')->willReturn(500);
        $order->method('getRzpWebhookData')->willReturn($rzpWebhookData);
        $order->method('getRzpUpdateOrderCronStatus')->willReturn($cronStatus);
        $order->method('setRzpUpdateOrderCronStatus')->willReturnSelf();

        return $order;
    }

    private function mockOrderList(array $orders): void
    {
        $searchResult = $this->createMock(OrderSearchResultInterface::class);
        $searchResult->method('getItems')->willReturn($orders);
        $this->orderRepository->method('getList')->willReturn($searchResult);
    }

    public function testExecuteWhenCronV1DisabledThenOrderRepositoryIsNeverQueried(): void
    {
        $cron = $this->buildCron(v1Enabled: false);

        $this->orderRepository->expects($this->never())->method('getList');

        $cron->execute();
    }

    public function testExecuteWhenWebhookDataEmptyThenCronRunCountIncrementsWithoutProcessing(): void
    {
        $order = $this->mockOrder(entityId: 1, rzpWebhookData: null, cronStatus: 2);
        $order->expects($this->once())->method('setRzpUpdateOrderCronStatus')->with(3)->willReturnSelf();
        $order->expects($this->once())->method('save');

        $this->mockOrderList([$order]);

        $cron = $this->buildCron();
        $cron->execute();
    }

    public function testExecuteWhenPaymentAuthorizedWebhookDataPresentThenOrderIsAuthorized(): void
    {
        $webhookData = serialize([
            'payment.authorized' => ['payment_id' => 'pay_ABC', 'amount' => 50000],
        ]);
        $order = $this->mockOrder(entityId: 2, rzpWebhookData: $webhookData, cronStatus: 0);
        $order->expects($this->once())->method('setRzpUpdateOrderCronStatus')->with(1)->willReturnSelf();
        $order->expects($this->once())->method('save');

        $this->mockOrderList([$order]);

        $cron = $this->buildCron();
        $cron->execute();
    }

    public function testExecuteWhenOrderPaidWebhookDataPresentThenOrderIsCaptured(): void
    {
        $webhookData = serialize([
            'order.paid' => ['payment_id' => 'pay_XYZ', 'amount' => 75000],
        ]);
        $order = $this->mockOrder(entityId: 3, rzpWebhookData: $webhookData, cronStatus: 0);
        $order->expects($this->once())->method('setRzpUpdateOrderCronStatus')->with(1)->willReturnSelf();
        $order->expects($this->once())->method('save');

        $this->mockOrderList([$order]);

        $cron = $this->buildCron();
        $cron->execute();
    }

    public function testExecuteWhenPaymentMethodIsNotRazorpayThenOrderIsIgnored(): void
    {
        $order = $this->mockOrder(entityId: 4, paymentMethod: 'checkmo', rzpWebhookData: null, cronStatus: 0);
        $order->expects($this->never())->method('setRzpUpdateOrderCronStatus');
        $order->expects($this->never())->method('save');

        $this->mockOrderList([$order]);

        $cron = $this->buildCron();
        $cron->execute();
    }

    public function testExecuteWhenBothPaymentAuthorizedAndOrderPaidPresentThenBothAreProcessed(): void
    {
        // Both events landed in the serialized payload (e.g. webhook cron ran
        // twice before this cron fired) -- both must be applied, in order.
        $webhookData = serialize([
            'payment.authorized' => ['payment_id' => 'pay_AUTH', 'amount' => 50000],
            'order.paid' => ['payment_id' => 'pay_CAPTURE', 'amount' => 50000],
        ]);
        $order = $this->mockOrder(entityId: 5, rzpWebhookData: $webhookData, cronStatus: 0);
        $order->expects($this->exactly(2))->method('setRzpUpdateOrderCronStatus');
        $order->expects($this->exactly(2))->method('save');

        $this->mockOrderList([$order]);

        $cron = $this->buildCron();
        $cron->execute();
    }
}
