<?php

namespace Razorpay\Magento\Test\Unit\Controller\Payment;

use Magento\Catalog\Model\Session as CatalogSession;
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
use Razorpay\Magento\Controller\Payment\Validate;
use Razorpay\Magento\Model\Config;
use Razorpay\Magento\Model\OrderLink;
use Razorpay\Magento\Model\PaymentMethod;
use Razorpay\Magento\Model\TrackPluginInstrumentation;

class ValidateTest extends TestCase
{
    private Config $config;
    private CatalogSession $catalogSession;
    private CheckoutSession $checkoutSession;
    private Order $orderModel;
    private LoggerInterface $logger;
    private TrackPluginInstrumentation $trackPluginInstrumentation;
    private OrderRepositoryInterface $orderRepository;
    private \Magento\Framework\ObjectManagerInterface $objectManagerMock;
    private OrderLink $orderLinkStub;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('getConfigData')->willReturn(null);
        $this->config->method('isCustomPaidOrderStatusEnabled')->willReturn(false);
        $this->config->method('canAutoGenerateInvoice')->willReturn(false);

        $this->catalogSession = $this->createMock(CatalogSession::class);
        $this->checkoutSession = $this->createMock(CheckoutSession::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->trackPluginInstrumentation = $this->createMock(TrackPluginInstrumentation::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);

        $statusResolver = $this->createMock(StatusResolver::class);
        $statusResolver->method('getOrderStatusByState')->willReturn('processing');

        $this->orderLinkStub = $this->getMockBuilder(OrderLink::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save'])
            ->addMethods(['setRzpPaymentId', 'setRzpUpdateOrderCronStatus'])
            ->getMock();
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

        $this->objectManagerMock = $this->createMock(\Magento\Framework\ObjectManagerInterface::class);
        $this->objectManagerMock->method('get')->willReturnCallback(
            function ($class) use ($orderLinkModel, $statusResolver, $quoteModel) {
                if ($class === 'Razorpay\Magento\Model\OrderLink') {
                    return $orderLinkModel;
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
                'getIncrementId',
                'setState',
                'setStatus',
                'getPayment',
                'getGrandTotal',
                'save',
                'getQuoteId',
                'getBaseCurrency',
                'addStatusHistoryComment',
                'getEntityId',
                'canInvoice',
            ])
            ->getMock();
        $this->orderModel->method('setState')->willReturnSelf();
        $this->orderModel->method('setStatus')->willReturnSelf();
        $this->orderModel->method('save')->willReturnSelf();
        $this->orderModel->method('getQuoteId')->willReturn(500);
        $this->orderModel->method('addStatusHistoryComment')->willReturnSelf();
        $this->orderModel->method('canInvoice')->willReturn(false);
    }

    /**
     * @param object $rzpApiStub Stub exposing ->utility->verifyPaymentSignature() and ->request->request().
     */
    private function buildController(object $rzpApiStub): Validate
    {
        $customerSession = $this->createMock(CustomerSession::class);

        $context = $this->createMock(Context::class);
        $context->method('getObjectManager')->willReturn($this->objectManagerMock);
        $context->method('getRequest')->willReturn($this->createMock(\Magento\Framework\App\RequestInterface::class));
        $context->method('getResponse')->willReturn($this->createMock(\Magento\Framework\App\ResponseInterface::class));
        $context->method('getMessageManager')->willReturn($this->createMock(\Magento\Framework\Message\ManagerInterface::class));
        $context->method('getRedirect')->willReturn($this->createMock(\Magento\Framework\App\Response\RedirectInterface::class));
        $context->method('getActionFlag')->willReturn($this->createMock(\Magento\Framework\App\ActionFlag::class));
        $context->method('getUrl')->willReturn($this->createMock(\Magento\Framework\UrlInterface::class));
        $context->method('getView')->willReturn($this->createMock(\Magento\Framework\App\ViewInterface::class));
        $context->method('getEventManager')->willReturn($this->createMock(\Magento\Framework\Event\ManagerInterface::class));
        $context->method('getResultRedirectFactory')->willReturn(
            $this->createMock(\Magento\Framework\Controller\Result\RedirectFactory::class)
        );

        $resultJson = $this->getMockBuilder(\Magento\Framework\Controller\Result\Json::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setData', 'setHttpResponseCode'])
            ->addMethods(['getData'])
            ->getMock();
        $capturedData = [];
        $resultJson->method('setData')->willReturnCallback(function ($data) use ($resultJson, &$capturedData) {
            $capturedData = $data;
            return $resultJson;
        });
        $resultJson->method('setHttpResponseCode')->willReturnSelf();
        $resultJson->method('getData')->willReturnCallback(function ($key = null) use (&$capturedData) {
            if ($key === null) {
                return $capturedData;
            }
            return $capturedData[$key] ?? null;
        });

        $resultFactory = $this->getMockBuilder(\Magento\Framework\Controller\ResultFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $resultFactory->method('create')->willReturn($resultJson);
        $context->method('getResultFactory')->willReturn($resultFactory);

        $controller = $this->getMockBuilder(Validate::class)
            ->setConstructorArgs([
                $context,
                $customerSession,
                $this->checkoutSession,
                $this->config,
                $this->catalogSession,
                $this->orderModel,
                $this->createMock(\Magento\Sales\Model\Service\InvoiceService::class),
                $this->createMock(\Magento\Framework\DB\Transaction::class),
                $this->createMock(\Magento\Sales\Model\Order\Email\Sender\InvoiceSender::class),
                $this->orderRepository,
                $this->createMock(\Magento\Sales\Model\Order\Email\Sender\OrderSender::class),
                $this->logger,
                $this->trackPluginInstrumentation,
            ])
            ->onlyMethods(['getPostData'])
            ->getMock();

        // BaseController::__construct() builds a real Api instance from empty
        // config keys; overwrite it via reflection with our test stub so
        // $this->rzp->utility->verifyPaymentSignature() never hits the network.
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

    public function testExecuteWhenSignatureInvalidThenReturnsFailureResponseAndReactivatesQuote(): void
    {
        // A forged/mismatched signature must not authorize the payment -- the
        // controller catches the SignatureVerificationError internally and
        // falls through to reactivating the quote (success=false response).
        $this->checkoutSession->method('getLastRealOrder')->willReturn($this->orderModel);

        $rzpApiStub = $this->buildRzpApiStub(
            new \Razorpay\Api\Errors\SignatureVerificationError('Invalid signature passed')
        );

        $controller = $this->buildController($rzpApiStub);
        $controller->method('getPostData')->willReturn([
            'razorpay_payment_id' => 'pay_FORGED',
            'razorpay_signature' => 'bad-signature',
        ]);

        $result = $controller->execute();

        $this->assertFalse($result->getData('success'));
    }

    public function testExecuteWhenPaymentActionIsAuthorizeCaptureThenOrderIsCapturedAndInvoiceSkippedWhenDisabled(): void
    {
        $this->buildPayment();
        $this->orderModel->method('getIncrementId')->willReturn('000000321');
        $this->orderModel->method('getEntityId')->willReturn(321);
        $this->checkoutSession->method('getLastRealOrder')->willReturn($this->orderModel);

        $this->config->method('getPaymentAction')->willReturn(PaymentMethod::ACTION_AUTHORIZE_CAPTURE);

        $this->orderLinkStub->expects($this->atLeastOnce())
            ->method('setRzpUpdateOrderCronStatus');
        $this->orderLinkStub->expects($this->once())->method('save');

        $rzpApiStub = $this->buildRzpApiStub(null, ['amount' => 50000, 'status' => 'captured']);

        $controller = $this->buildController($rzpApiStub);
        $controller->method('getPostData')->willReturn([
            'razorpay_payment_id' => 'pay_CAPTURE1',
            'razorpay_signature' => 'valid-signature',
        ]);

        $result = $controller->execute();

        $this->assertTrue($result->getData('success'));
        $this->assertSame('000000321', $result->getData('order_id'));
    }

    public function testExecuteWhenPaymentFailedErrorPresentThenThrowsAndReturnsFailureResponse(): void
    {
        // getPostData() returning an 'error' key (gateway-reported failure)
        // must short-circuit before any authorize/capture attempt.
        $this->checkoutSession->method('getLastRealOrder')->willReturn($this->orderModel);

        $rzpApiStub = $this->buildRzpApiStub();

        $controller = $this->buildController($rzpApiStub);
        $controller->method('getPostData')->willReturn([
            'error' => ['description' => 'Payment failed at gateway'],
        ]);

        $result = $controller->execute();

        $this->assertFalse($result->getData('success'));
    }
}
