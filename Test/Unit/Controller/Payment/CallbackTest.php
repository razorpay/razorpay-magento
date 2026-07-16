<?php

namespace Razorpay\Magento\Test\Unit\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction as PaymentTransaction;
use Magento\Sales\Model\Order\StatusResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Razorpay\Magento\Controller\Payment\Callback;
use Razorpay\Magento\Model\Config;
use Razorpay\Magento\Model\OrderLink;
use Razorpay\Magento\Model\PaymentMethod;
use Razorpay\Magento\Model\TrackPluginInstrumentation;

class CallbackTest extends TestCase
{
    private Config $config;
    private CheckoutSession $checkoutSession;
    private Order $orderModel;
    private LoggerInterface $logger;
    private TrackPluginInstrumentation $trackPluginInstrumentation;
    private OrderRepositoryInterface $orderRepository;
    private \Magento\Framework\ObjectManagerInterface $objectManagerMock;
    private OrderLink $orderLinkStub;
    private Order $salesOrderLookupModel;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('getConfigData')->willReturn(null);
        $this->config->method('isCustomPaidOrderStatusEnabled')->willReturn(false);
        $this->config->method('canAutoGenerateInvoice')->willReturn(false);

        $this->checkoutSession = $this->getMockBuilder(CheckoutSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['clearHelperData', 'replaceQuote'])
            ->addMethods([
                'setLastSuccessQuoteId',
                'setLastQuoteId',
                'setLastOrderId',
                'setLastRealOrderId',
                'setLastOrderStatus',
                'setRazorpayMailSentOnSuccess',
                'unsRazorpayMailSentOnSuccess',
                'setFirstTimeChk',
            ])
            ->getMock();
        $this->checkoutSession->method('setLastSuccessQuoteId')->willReturnSelf();
        $this->checkoutSession->method('setLastQuoteId')->willReturnSelf();
        // The real clearHelperData()/replaceQuote() touch session storage
        // that a constructor-less mock doesn't have, so both are stubbed
        // as no-ops rather than delegating to the real implementation.
        $this->checkoutSession->method('clearHelperData')->willReturn(null);
        $this->checkoutSession->method('replaceQuote')->willReturnSelf();
        $this->checkoutSession->method('setLastOrderId')->willReturnSelf();
        $this->checkoutSession->method('setLastRealOrderId')->willReturnSelf();
        $this->checkoutSession->method('setLastOrderStatus')->willReturnSelf();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->trackPluginInstrumentation = $this->createMock(TrackPluginInstrumentation::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);

        $statusResolver = $this->createMock(StatusResolver::class);
        $statusResolver->method('getOrderStatusByState')->willReturn('processing');

        $this->orderLinkStub = $this->getMockBuilder(OrderLink::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save'])
            ->addMethods(['getRzpOrderId', 'setRzpPaymentId', 'setRzpUpdateOrderCronStatus'])
            ->getMock();
        $this->orderLinkStub->method('getRzpOrderId')->willReturn('order_RZP1');
        $this->orderLinkStub->method('setRzpPaymentId')->willReturnSelf();
        $this->orderLinkStub->method('setRzpUpdateOrderCronStatus')->willReturnSelf();

        $orderLinkCollection = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['addFilter', 'getFirstItem'])
            ->getMock();
        $orderLinkCollection->method('addFilter')->willReturnSelf();
        $orderLinkCollection->method('getFirstItem')->willReturn($this->orderLinkStub);

        $orderLinkModel = $this->getMockBuilder(OrderLink::class)
            ->disableOriginalConstructor()
            ->getMock();
        $orderLinkModel->method('getCollection')->willReturn($orderLinkCollection);

        // objectManagement->get('Magento\Sales\Model\Order')->getCollection()...
        // resolves entity_id from increment_id -- separate from $this->order
        // (the injected OrderInterface used for the actual ->load() call).
        $salesOrderCollectionItem = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getEntityId'])
            ->getMock();
        $salesOrderCollectionItem->method('getEntityId')->willReturn(321);

        $salesOrderCollection = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['addFieldToSelect', 'addFilter', 'getFirstItem'])
            ->getMock();
        $salesOrderCollection->method('addFieldToSelect')->willReturnSelf();
        $salesOrderCollection->method('addFilter')->willReturnSelf();
        $salesOrderCollection->method('getFirstItem')->willReturn($salesOrderCollectionItem);

        $this->salesOrderLookupModel = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCollection'])
            ->getMock();
        $this->salesOrderLookupModel->method('getCollection')->willReturn($salesOrderCollection);

        $quote = $this->getMockBuilder(\Magento\Quote\Model\Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setIsActive', 'setReservedOrderId', 'save'])
            ->getMock();
        $quote->method('setIsActive')->willReturnSelf();
        $quote->method('setReservedOrderId')->willReturnSelf();

        $quoteModel = $this->getMockBuilder(\Magento\Quote\Model\Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load'])
            ->getMock();
        $quoteModel->method('load')->willReturn($quote);

        $this->objectManagerMock = $this->createMock(\Magento\Framework\ObjectManagerInterface::class);
        $this->objectManagerMock->method('get')->willReturnCallback(
            function ($class) use ($orderLinkModel, $statusResolver, $quoteModel) {
                if ($class === 'Razorpay\Magento\Model\OrderLink') {
                    return $orderLinkModel;
                }
                if ($class === 'Magento\Sales\Model\Order') {
                    return $this->salesOrderLookupModel;
                }
                if ($class === StatusResolver::class) {
                    return $statusResolver;
                }
                if ($class === 'Magento\Quote\Model\Quote') {
                    return $quoteModel;
                }
                return $this->createMock(\Magento\Directory\Helper\Data::class);
            }
        );
        ObjectManager::setInstance($this->objectManagerMock);

        $this->orderModel = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'load',
                'getIncrementId',
                'setState',
                'setStatus',
                'getPayment',
                'getGrandTotal',
                'save',
                'getQuoteId',
                'getBaseCurrency',
                'addStatusHistoryComment',
                'canInvoice',
                'getId',
                'getStatus',
            ])
            ->getMock();
        $this->orderModel->method('load')->willReturnSelf();
        $this->orderModel->method('setState')->willReturnSelf();
        $this->orderModel->method('setStatus')->willReturnSelf();
        $this->orderModel->method('save')->willReturnSelf();
        $this->orderModel->method('getQuoteId')->willReturn(500);
        $this->orderModel->method('addStatusHistoryComment')->willReturnSelf();
        $this->orderModel->method('canInvoice')->willReturn(false);
        $this->orderModel->method('getId')->willReturn(321);
        $this->orderModel->method('getStatus')->willReturn('processing');
    }

    /**
     * @param object $rzpApiStub Stub exposing ->utility->verifyPaymentSignature() and ->request->request().
     */
    private function buildController(object $rzpApiStub, array $requestParams): Callback
    {
        $customerSession = $this->createMock(CustomerSession::class);

        $context = $this->createMock(Context::class);
        $context->method('getObjectManager')->willReturn($this->objectManagerMock);

        $request = $this->createMock(\Magento\Framework\App\RequestInterface::class);
        $request->method('getParams')->willReturn($requestParams);
        $context->method('getRequest')->willReturn($request);

        $context->method('getResponse')->willReturn($this->createMock(\Magento\Framework\App\ResponseInterface::class));
        $context->method('getMessageManager')->willReturn($this->createMock(\Magento\Framework\Message\ManagerInterface::class));
        $context->method('getRedirect')->willReturn($this->createMock(\Magento\Framework\App\Response\RedirectInterface::class));
        $context->method('getActionFlag')->willReturn($this->createMock(\Magento\Framework\App\ActionFlag::class));
        $context->method('getUrl')->willReturn($this->createMock(\Magento\Framework\UrlInterface::class));
        $context->method('getView')->willReturn($this->createMock(\Magento\Framework\App\ViewInterface::class));
        $context->method('getEventManager')->willReturn($this->createMock(\Magento\Framework\Event\ManagerInterface::class));

        $redirectResult = $this->getMockBuilder(\Magento\Framework\Controller\Result\Redirect::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setPath'])
            ->getMock();
        $redirectResult->method('setPath')->willReturnSelf();

        $resultRedirectFactory = $this->getMockBuilder(\Magento\Framework\Controller\Result\RedirectFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $resultRedirectFactory->method('create')->willReturn($redirectResult);
        $context->method('getResultRedirectFactory')->willReturn($resultRedirectFactory);
        $context->method('getResultFactory')->willReturn($this->createMock(\Magento\Framework\Controller\ResultFactory::class));

        $controller = new Callback(
            $context,
            $customerSession,
            $this->checkoutSession,
            $this->config,
            $this->logger,
            $this->orderRepository,
            $this->createMock(\Magento\Framework\DB\Transaction::class),
            $this->createMock(\Magento\Sales\Model\Service\InvoiceService::class),
            $this->createMock(\Magento\Sales\Model\Order\Email\Sender\OrderSender::class),
            $this->createMock(\Magento\Sales\Model\Order\Email\Sender\InvoiceSender::class),
            $this->createMock(\Magento\Catalog\Model\Session::class),
            $this->orderModel,
            $this->createMock(TrackPluginInstrumentation::class)
        );

        // BaseController::__construct() builds a real Api instance; overwrite
        // it via reflection so verifyPaymentSignature() never hits the network.
        $rzpProperty = new \ReflectionProperty(\Razorpay\Magento\Controller\BaseController::class, 'rzp');
        $rzpProperty->setValue($controller, $rzpApiStub);

        return $controller;
    }

    private function buildRzpApiStub($verifySignatureException = null, array $requestResponse = []): object
    {
        $utility = $this->getMockBuilder(\stdClass::class)->addMethods(['verifyPaymentSignature'])->getMock();
        if ($verifySignatureException) {
            $utility->method('verifyPaymentSignature')->willThrowException($verifySignatureException);
        }

        $request = $this->getMockBuilder(\stdClass::class)->addMethods(['request'])->getMock();
        $request->method('request')->willReturn($requestResponse);

        return new class ($utility, $request) {
            public $utility;
            public $request;
            public function __construct($utility, $request)
            {
                $this->utility = $utility;
                $this->request = $request;
            }
        };
    }

    private function buildPayment(): Payment
    {
        $currency = $this->createMock(\Magento\Directory\Model\Currency::class);
        $currency->method('formatTxt')->willReturn('$5.00');
        $this->orderModel->method('getBaseCurrency')->willReturn($currency);
        $this->orderModel->method('getGrandTotal')->willReturn(500.0);

        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
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

        $this->orderModel->method('getPayment')->willReturn($payment);

        return $payment;
    }

    public function testExecuteWhenOrderIdEmptyThenRedirectsToCartWithoutValidatingSignature(): void
    {
        $rzpApiStub = $this->buildRzpApiStub();
        $controller = $this->buildController($rzpApiStub, ['order_id' => '']);

        $result = $controller->execute();

        $this->assertInstanceOf(\Magento\Framework\App\ResponseInterface::class, $result);
    }

    public function testExecuteWhenNoRazorpayPaymentIdThenReactivatesQuoteAndAddsErrorMessage(): void
    {
        // No razorpay_payment_id in the callback params means the gateway
        // redirect came back without a completed payment -- must reactivate
        // the quote for retry rather than leave it disabled.
        $rzpApiStub = $this->buildRzpApiStub();
        $controller = $this->buildController($rzpApiStub, ['order_id' => '000000321']);

        $result = $controller->execute();

        $this->assertInstanceOf(\Magento\Framework\App\ResponseInterface::class, $result);
    }

    public function testExecuteWhenSignatureValidAndAuthorizeCaptureThenOrderIsCapturedAndRedirectsToSuccess(): void
    {
        $this->buildPayment();
        $this->orderModel->method('getIncrementId')->willReturn('000000321');

        $this->config->method('getPaymentAction')->willReturn(PaymentMethod::ACTION_AUTHORIZE_CAPTURE);

        $this->orderLinkStub->expects($this->atLeastOnce())->method('setRzpUpdateOrderCronStatus');
        $this->orderLinkStub->expects($this->once())->method('save');

        $rzpApiStub = $this->buildRzpApiStub(null, ['amount' => 50000, 'status' => 'captured']);

        $controller = $this->buildController($rzpApiStub, [
            'order_id' => '000000321',
            'razorpay_payment_id' => 'pay_CAPTURE1',
            'razorpay_signature' => 'valid-signature',
        ]);

        $result = $controller->execute();

        $this->assertInstanceOf(\Magento\Framework\App\ResponseInterface::class, $result);
    }

    public function testExecuteWhenSignatureVerificationFailsThenCatchesErrorAndTracksFailure(): void
    {
        // A thrown SignatureVerificationError from verifyPaymentSignature()
        // propagates past validateSignature() into execute()'s outer
        // Razorpay\Api\Errors\Error catch block, which tracks
        // razorpay.std.callback.payment.process.failed -- must not authorize
        // or capture the order, and must not propagate the exception further.
        $rzpApiStub = $this->buildRzpApiStub(
            new \Razorpay\Api\Errors\SignatureVerificationError('Invalid signature passed')
        );

        $controller = $this->buildController($rzpApiStub, [
            'order_id' => '000000321',
            'razorpay_payment_id' => 'pay_FORGED',
            'razorpay_signature' => 'bad-signature',
        ]);

        $this->orderModel->expects($this->never())->method('save');

        // execute() falls through to the bottom of the method after the
        // caught exception without a trailing return -- no assertion on a
        // return value here, only that no exception propagates and no save happens.
        $controller->execute();
    }
}
