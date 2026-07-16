<?php

namespace Razorpay\Magento\Test\Unit\Model;

use Magento\Directory\Model\RegionFactory;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Payment\Model\Method\Logger;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\ResourceModel\Order\Payment\Transaction\CollectionFactory as TransactionCollectionFactory;
use PHPUnit\Framework\TestCase;
use Razorpay\Magento\Controller\Payment\Order as OrderController;
use Razorpay\Magento\Model\Config;
use Razorpay\Magento\Model\PaymentMethod;
use Razorpay\Magento\Model\TrackPluginInstrumentation;

class PaymentMethodTest extends TestCase
{
    private Config $config;
    private PaymentHelper $paymentHelper;
    private Logger $paymentLogger;
    private HttpRequest $request;
    private TransactionCollectionFactory $transactionCollectionFactory;
    private ProductMetadataInterface $productMetaData;
    private RegionFactory $regionFactory;
    private OrderRepositoryInterface $orderRepository;
    private OrderController $orderController;
    private TrackPluginInstrumentation $trackPluginInstrumentation;

    protected function setUp(): void
    {
        // AbstractMethod::__construct() falls back to
        // ObjectManager::getInstance()->get(DirectoryHelper::class) when no
        // $directory arg is passed, so the static instance must be primed.
        $objectManagerMock = $this->createMock(\Magento\Framework\ObjectManagerInterface::class);
        $objectManagerMock->method('get')->willReturn(
            $this->createMock(\Magento\Directory\Helper\Data::class)
        );
        \Magento\Framework\App\ObjectManager::setInstance($objectManagerMock);

        $this->config = $this->createMock(Config::class);
        $this->paymentHelper = $this->createMock(PaymentHelper::class);
        $this->paymentLogger = $this->createMock(Logger::class);
        $this->request = $this->createMock(HttpRequest::class);
        $this->transactionCollectionFactory = $this->createMock(TransactionCollectionFactory::class);
        $this->productMetaData = $this->createMock(ProductMetadataInterface::class);
        $this->regionFactory = $this->createMock(RegionFactory::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->orderController = $this->createMock(OrderController::class);
        $this->trackPluginInstrumentation = $this->createMock(TrackPluginInstrumentation::class);
    }

    /**
     * Builds a PaymentMethod with setAndGetRzpApiInstance() overridden so refund()
     * never performs a real HTTP call against the Razorpay API.
     *
     * @param object $rzpApiStub Stub exposing ->payment->fetch($id)->refund($data)
     */
    private function buildPaymentMethod(object $rzpApiStub): PaymentMethod
    {
        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')
            ->willReturn($this->createMock(\Magento\Framework\Event\ManagerInterface::class));
        $context->method('getCacheManager')
            ->willReturn($this->createMock(\Magento\Framework\App\CacheInterface::class));
        $context->method('getAppState')
            ->willReturn($this->createMock(\Magento\Framework\App\State::class));
        $context->method('getActionValidator')
            ->willReturn($this->createMock(\Magento\Framework\Model\ActionValidator\RemoveAction::class));
        $context->method('getLogger')->willReturn($this->createMock(\Psr\Log\LoggerInterface::class));

        /** @var PaymentMethod&\PHPUnit\Framework\MockObject\MockObject $paymentMethod */
        $paymentMethod = $this->getMockBuilder(PaymentMethod::class)
            ->setConstructorArgs([
                $context,
                $this->createMock(Registry::class),
                $this->createMock(ExtensionAttributesFactory::class),
                $this->createMock(AttributeValueFactory::class),
                $this->paymentHelper,
                $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class),
                $this->paymentLogger,
                $this->config,
                $this->request,
                $this->transactionCollectionFactory,
                $this->productMetaData,
                $this->regionFactory,
                $this->orderRepository,
                $this->orderController,
                $this->trackPluginInstrumentation,
            ])
            ->onlyMethods(['setAndGetRzpApiInstance'])
            ->getMock();

        $paymentMethod->method('setAndGetRzpApiInstance')->willReturn($rzpApiStub);

        return $paymentMethod;
    }

    private function mockOrderPayment(string $transactionId): Payment
    {
        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('000000123');

        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getOrder',
                'getTransactionId',
                'setAmountPaid',
                'setLastTransId',
                'setTransactionId',
                'setIsTransactionClosed',
                'setShouldCloseParentTransaction',
            ])
            ->getMock();
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getTransactionId')->willReturn($transactionId);
        $payment->method('setAmountPaid')->willReturnSelf();
        $payment->method('setLastTransId')->willReturnSelf();
        $payment->method('setTransactionId')->willReturnSelf();
        $payment->method('setIsTransactionClosed')->willReturnSelf();
        $payment->method('setShouldCloseParentTransaction')->willReturnSelf();

        return $payment;
    }

    public function testRefundWhenApiSucceedsThenPaymentIsUpdatedWithRefundId(): void
    {
        // refund() derives the Razorpay payment id by stripping the 7-char
        // transaction-id suffix Magento appends (e.g. "-refund" style suffixes).
        $transactionId = 'pay_ABCDEFGHIJ1234';
        $expectedPaymentId = substr($transactionId, 0, -7);

        $refundEntity = (object) ['id' => 'rfnd_XYZ999'];

        $paymentEntity = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['fetch', 'refund'])
            ->getMock();
        $paymentEntity->expects($this->once())
            ->method('fetch')
            ->with($expectedPaymentId)
            ->willReturnSelf();
        $paymentEntity->expects($this->once())
            ->method('refund')
            ->willReturn($refundEntity);

        $rzpApiStub = new class ($paymentEntity) {
            public $payment;
            public function __construct($paymentEntity)
            {
                $this->payment = $paymentEntity;
            }
            public function setHeader($header, $value)
            {
            }
        };

        $this->request->method('getPost')->with('creditmemo')->willReturn([
            'comment_text' => 'Refunded due to customer request',
        ]);
        $this->productMetaData->method('getEdition')->willReturn('Community');
        $this->productMetaData->method('getVersion')->willReturn('2.4.7');

        $payment = $this->mockOrderPayment($transactionId);
        $payment->expects($this->once())->method('setAmountPaid')->with(500.0)->willReturnSelf();
        $payment->expects($this->once())->method('setLastTransId')->with('rfnd_XYZ999')->willReturnSelf();
        $payment->expects($this->once())->method('setTransactionId')->with('rfnd_XYZ999')->willReturnSelf();

        $paymentMethod = $this->buildPaymentMethod($rzpApiStub);

        $result = $paymentMethod->refund($payment, 500.0);

        $this->assertSame($paymentMethod, $result);
    }

    public function testRefundWhenApiThrowsRazorpayErrorThenLocalizedExceptionIsThrown(): void
    {
        $transactionId = 'pay_ABCDEFGHIJ1234';

        $paymentEntity = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['fetch', 'refund'])
            ->getMock();
        $paymentEntity->method('fetch')->willReturnSelf();
        $paymentEntity->method('refund')->willThrowException(
            new \Razorpay\Api\Errors\Error('Refund amount exceeds captured amount', null, 400)
        );

        $rzpApiStub = new class ($paymentEntity) {
            public $payment;
            public function __construct($paymentEntity)
            {
                $this->payment = $paymentEntity;
            }
            public function setHeader($header, $value)
            {
            }
        };

        $this->request->method('getPost')->willReturn([]);
        $this->productMetaData->method('getEdition')->willReturn('Community');
        $this->productMetaData->method('getVersion')->willReturn('2.4.7');

        $payment = $this->mockOrderPayment($transactionId);

        $paymentMethod = $this->buildPaymentMethod($rzpApiStub);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/Refund amount exceeds captured amount/');

        $paymentMethod->refund($payment, 500.0);
    }

    public function testRefundWhenNoCreditmemoCommentThenDefaultReasonIsUsed(): void
    {
        $transactionId = 'pay_ABCDEFGHIJ1234';

        $paymentEntity = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['fetch', 'refund'])
            ->getMock();
        $paymentEntity->method('fetch')->willReturnSelf();
        $paymentEntity->expects($this->once())
            ->method('refund')
            ->with($this->callback(function (array $data) {
                return $data['notes']['reason'] === 'Refunded by site admin';
            }))
            ->willReturn((object) ['id' => 'rfnd_DEFAULT']);

        $rzpApiStub = new class ($paymentEntity) {
            public $payment;
            public function __construct($paymentEntity)
            {
                $this->payment = $paymentEntity;
            }
            public function setHeader($header, $value)
            {
            }
        };

        // No 'creditmemo' key in the POST body at all — refund() must fall back
        // to the default reason rather than crash on undefined array access.
        $this->request->method('getPost')->with('creditmemo')->willReturn(null);
        $this->productMetaData->method('getEdition')->willReturn('Community');
        $this->productMetaData->method('getVersion')->willReturn('2.4.7');

        $payment = $this->mockOrderPayment($transactionId);

        $paymentMethod = $this->buildPaymentMethod($rzpApiStub);
        $paymentMethod->refund($payment, 250.0);
    }
}
