<?php

namespace Razorpay\Magento\Test\Unit\Model\Resolver;

use Magento\Directory\Model\RegionFactory;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Payment\Model\Method\Logger;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction as PaymentTransaction;
use Magento\Sales\Model\Order\StatusResolver;
use Magento\Sales\Model\ResourceModel\Order\Payment\Transaction\CollectionFactory as TransactionCollectionFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Razorpay\Magento\Constants\OrderCronStatus;
use Razorpay\Magento\Controller\Payment\Order as OrderController;
use Razorpay\Magento\Model\Config;
use Razorpay\Magento\Model\OrderLink;
use Razorpay\Magento\Model\PaymentMethod;
use Razorpay\Magento\Model\Resolver\SetRzpPaymentDetailsForOrder;
use Razorpay\Magento\Model\TrackPluginInstrumentation;

class SetRzpPaymentDetailsForOrderTest extends TestCase
{
    private Order $orderModel;
    private Config $config;
    private ScopeConfigInterface $scopeConfig;
    private LoggerInterface $logger;
    private TrackPluginInstrumentation $trackPluginInstrumentation;
    private \Magento\Framework\ObjectManagerInterface $objectManagerMock;
    private OrderLink $orderLinkStub;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('isCustomPaidOrderStatusEnabled')->willReturn(false);
        $this->config->method('canAutoGenerateInvoice')->willReturn(false);

        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->trackPluginInstrumentation = $this->createMock(TrackPluginInstrumentation::class);

        $statusResolver = $this->createMock(StatusResolver::class);
        $statusResolver->method('getOrderStatusByState')->willReturn('processing');

        $this->objectManagerMock = $this->createMock(\Magento\Framework\ObjectManagerInterface::class);

        $this->orderLinkStub = $this->getMockBuilder(OrderLink::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save'])
            ->addMethods(['getRzpOrderId', 'setRzpUpdateOrderCronStatus', 'setRzpPaymentId'])
            ->getMock();
        $this->orderLinkStub->method('setRzpUpdateOrderCronStatus')->willReturnSelf();
        $this->orderLinkStub->method('setRzpPaymentId')->willReturnSelf();

        $collection = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['addFilter', 'getFirstItem'])
            ->getMock();
        $collection->method('addFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($this->orderLinkStub);

        $orderLinkModel = $this->getMockBuilder(OrderLink::class)
            ->disableOriginalConstructor()
            ->getMock();
        $orderLinkModel->method('getCollection')->willReturn($collection);

        $this->objectManagerMock->method('get')->willReturnCallback(
            function ($class) use ($orderLinkModel, $statusResolver) {
                if ($class === 'Razorpay\Magento\Model\OrderLink') {
                    return $orderLinkModel;
                }
                if ($class === StatusResolver::class) {
                    return $statusResolver;
                }
                return $this->createMock(\Magento\Directory\Helper\Data::class);
            }
        );
        \Magento\Framework\App\ObjectManager::setInstance($this->objectManagerMock);

        $this->orderModel = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'load',
                'getEntityId',
                'getIncrementId',
                'getStatus',
                'setState',
                'setStatus',
                'getPayment',
                'getGrandTotal',
                'getBaseCurrency',
                'addStatusHistoryComment',
                'canInvoice',
                'save',
            ])
            ->getMock();
        $this->orderModel->method('load')->willReturnSelf();
        $this->orderModel->method('setState')->willReturnSelf();
        $this->orderModel->method('setStatus')->willReturnSelf();
        $this->orderModel->method('addStatusHistoryComment')->willReturnSelf();
        $this->orderModel->method('canInvoice')->willReturn(false);
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

    /**
     * @param object $rzpApiStub Stub exposing ->utility->verifyPaymentSignature(),
     *        ->order->fetch(), and ->request->request().
     */
    private function buildResolver(object $rzpApiStub): SetRzpPaymentDetailsForOrder
    {
        $paymentMethod = $this->buildPaymentMethodReturning($rzpApiStub);

        return new SetRzpPaymentDetailsForOrder(
            $paymentMethod,
            $this->orderModel,
            $this->config,
            $this->createMock(\Magento\Sales\Model\Service\InvoiceService::class),
            $this->createMock(\Magento\Framework\DB\Transaction::class),
            $this->scopeConfig,
            $this->createMock(\Magento\Checkout\Model\Session::class),
            $this->createMock(\Magento\Sales\Model\Order\Email\Sender\InvoiceSender::class),
            $this->createMock(\Magento\Sales\Model\Order\Email\Sender\OrderSender::class),
            $this->logger,
            $this->trackPluginInstrumentation
        );
    }

    private function buildPaymentMethodReturning(object $rzpApiStub): PaymentMethod
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

        $paymentMethod = $this->getMockBuilder(PaymentMethod::class)
            ->setConstructorArgs([
                $context,
                $this->createMock(Registry::class),
                $this->createMock(ExtensionAttributesFactory::class),
                $this->createMock(AttributeValueFactory::class),
                $this->createMock(PaymentHelper::class),
                $this->createMock(ScopeConfigInterface::class),
                $this->createMock(Logger::class),
                $this->config,
                $this->createMock(HttpRequest::class),
                $this->createMock(TransactionCollectionFactory::class),
                $this->createMock(ProductMetadataInterface::class),
                $this->createMock(RegionFactory::class),
                $this->createMock(OrderRepositoryInterface::class),
                $this->createMock(OrderController::class),
                $this->trackPluginInstrumentation,
            ])
            ->onlyMethods(['setAndGetRzpApiInstance'])
            ->getMock();

        $paymentMethod->method('setAndGetRzpApiInstance')->willReturn($rzpApiStub);

        return $paymentMethod;
    }

    private function buildRzpApiStub($orderEntity, $requestEntity = null, $verifySignatureException = null): object
    {
        $utility = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['verifyPaymentSignature'])
            ->getMock();
        if ($verifySignatureException) {
            $utility->method('verifyPaymentSignature')->willThrowException($verifySignatureException);
        }

        return new class ($utility, $orderEntity, $requestEntity) {
            public $utility;
            public $order;
            public $request;
            public function __construct($utility, $orderEntity, $requestEntity)
            {
                $this->utility = $utility;
                $this->order = $orderEntity;
                $this->request = $requestEntity;
            }
        };
    }

    private function argsFor(string $orderId, string $paymentId, string $signature): array
    {
        return [
            'input' => [
                'order_id' => $orderId,
                'rzp_payment_id' => $paymentId,
                'rzp_signature' => $signature,
            ],
        ];
    }

    public function testResolveWhenOrderIdMissingThenThrowsGraphQlInputException(): void
    {
        $resolver = $this->buildResolver($this->buildRzpApiStub(null));

        $this->expectException(GraphQlInputException::class);
        $this->expectExceptionMessage('Required parameter "order_id" is missing.');

        $resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            null,
            ['input' => ['rzp_payment_id' => 'pay_X', 'rzp_signature' => 'sig']]
        );
    }

    public function testResolveWhenPaymentIdMissingThenThrowsGraphQlInputException(): void
    {
        $resolver = $this->buildResolver($this->buildRzpApiStub(null));

        $this->expectException(GraphQlInputException::class);
        $this->expectExceptionMessage('Required parameter "rzp_payment_id" is missing.');

        $resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            null,
            ['input' => ['order_id' => '000000123', 'rzp_signature' => 'sig']]
        );
    }

    public function testResolveWhenSignatureMissingThenThrowsGraphQlInputException(): void
    {
        $resolver = $this->buildResolver($this->buildRzpApiStub(null));

        $this->expectException(GraphQlInputException::class);
        $this->expectExceptionMessage('Required parameter "rzp_signature" is missing.');

        $resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            null,
            ['input' => ['order_id' => '000000123', 'rzp_payment_id' => 'pay_X']]
        );
    }

    public function testResolveWhenOrderLinkHasNoRzpOrderIdThenThrowsGraphQlInputException(): void
    {
        $this->orderLinkStub->method('getRzpOrderId')->willReturn(null);

        $resolver = $this->buildResolver($this->buildRzpApiStub(null));

        $this->expectException(GraphQlInputException::class);
        $this->expectExceptionMessageMatches('/Unable to Razorpay Order ID/');

        $resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            null,
            $this->argsFor('000000123', 'pay_X', 'sig')
        );
    }

    public function testResolveWhenSignatureVerificationFailsThenExceptionPropagates(): void
    {
        // verifyPaymentSignature() is called unguarded outside any try/catch in
        // the resolver, so a forged/invalid signature must propagate as a real
        // exception rather than silently be swallowed or return a "success" shape.
        $this->orderLinkStub->method('getRzpOrderId')->willReturn('order_RZP1');

        $rzpApiStub = $this->buildRzpApiStub(
            null,
            null,
            new \Razorpay\Api\Errors\SignatureVerificationError('Invalid signature passed')
        );

        $resolver = $this->buildResolver($rzpApiStub);

        $this->expectException(\Razorpay\Api\Errors\SignatureVerificationError::class);

        $resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            null,
            $this->argsFor('000000123', 'pay_X', 'forged-signature')
        );
    }

    public function testResolveWhenReceiptDoesNotMatchOrderIdThenThrowsGraphQlInputException(): void
    {
        $this->orderLinkStub->method('getRzpOrderId')->willReturn('order_RZP1');

        $orderEntity = (object) ['receipt' => 'some-other-order-id', 'amount' => 50000, 'status' => 'paid'];
        $orderApiEntity = $this->getMockBuilder(\stdClass::class)->addMethods(['fetch'])->getMock();
        $orderApiEntity->method('fetch')->willReturn($orderEntity);

        $resolver = $this->buildResolver($this->buildRzpApiStub($orderApiEntity));

        $this->expectException(GraphQlInputException::class);
        $this->expectExceptionMessage('Not a valid Razorpay orderID');

        $resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            null,
            $this->argsFor('000000123', 'pay_X', 'sig')
        );
    }

    public function testResolveWhenPaymentAlreadyPlacedOnOrderThenApiErrorPropagatesAsGraphQlException(): void
    {
        // Simulates calling the mutation a second time for an order whose
        // Razorpay order lookup on the API side now fails/mismatches --
        // must surface as a GraphQlInputException, not a raw SDK exception.
        $this->orderLinkStub->method('getRzpOrderId')->willReturn('order_RZP1');

        $orderApiEntity = $this->getMockBuilder(\stdClass::class)->addMethods(['fetch'])->getMock();
        $orderApiEntity->method('fetch')->willThrowException(
            new \Razorpay\Api\Errors\Error('The id provided does not exist', null, 400)
        );

        $resolver = $this->buildResolver($this->buildRzpApiStub($orderApiEntity));

        $this->expectException(GraphQlInputException::class);
        $this->expectExceptionMessageMatches('/The id provided does not exist/');

        $resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            null,
            $this->argsFor('000000123', 'pay_X', 'sig')
        );
    }

    public function testResolveWhenApiSucceedsThenOrderIsAuthorizedAndCronStatusUpdated(): void
    {
        $this->buildPayment();
        $this->orderModel->method('getEntityId')->willReturn(999);
        $this->orderModel->method('getIncrementId')->willReturn('000000123');
        $this->orderModel->method('getStatus')->willReturn('pending');

        $this->orderLinkStub->method('getRzpOrderId')->willReturn('order_RZP1');
        $this->orderLinkStub->expects($this->atLeastOnce())
            ->method('setRzpUpdateOrderCronStatus')
            ->with(OrderCronStatus::PAYMENT_AUTHORIZED_COMPLETED)
            ->willReturnSelf();
        $this->orderLinkStub->expects($this->once())->method('save');

        $this->config->method('getPaymentAction')->willReturn(PaymentMethod::ACTION_AUTHORIZE);
        $this->scopeConfig->method('getValue')->willReturn('authorize');

        $orderEntity = (object) ['receipt' => '000000123', 'amount' => 50000, 'status' => 'created'];
        $orderApiEntity = $this->getMockBuilder(\stdClass::class)->addMethods(['fetch'])->getMock();
        $orderApiEntity->method('fetch')->willReturn($orderEntity);

        $rzpApiStub = $this->buildRzpApiStub($orderApiEntity);
        $requestStub = $this->getMockBuilder(\stdClass::class)->addMethods(['request'])->getMock();
        $requestStub->method('request')->willReturn(['amount' => 50000, 'status' => 'authorized']);
        $rzpApiStub->request = $requestStub;

        $this->orderModel->expects($this->once())->method('save');

        $resolver = $this->buildResolver($rzpApiStub);

        $result = $resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            null,
            $this->argsFor('000000123', 'pay_X', 'sig')
        );

        $this->assertSame('000000123', $result['order']['order_id']);
    }
}
