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
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Payment\Model\Method\Logger;
use Magento\QuoteGraphQl\Model\Cart\GetCartForUser;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Payment\Transaction\CollectionFactory as TransactionCollectionFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Razorpay\Magento\Controller\Payment\Order as OrderController;
use Razorpay\Magento\Model\Config;
use Razorpay\Magento\Model\OrderLink;
use Razorpay\Magento\Model\PaymentMethod;
use Razorpay\Magento\Model\Resolver\PlaceRazorpayOrder;
use Razorpay\Magento\Model\TrackPluginInstrumentation;

class PlaceRazorpayOrderTest extends TestCase
{
    private ScopeConfigInterface $scopeConfig;
    private GetCartForUser $getCartForUser;
    private CartManagementInterface $cartManagement;
    private Order $orderModel;
    private LoggerInterface $logger;
    private Config $config;
    private TrackPluginInstrumentation $trackPluginInstrumentation;
    private \Magento\Framework\ObjectManagerInterface $objectManagerMock;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->getCartForUser = $this->createMock(GetCartForUser::class);
        $this->cartManagement = $this->createMock(CartManagementInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->config = $this->createMock(Config::class);
        $this->trackPluginInstrumentation = $this->createMock(TrackPluginInstrumentation::class);

        $this->orderModel = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'load',
                'getGrandTotal',
                'getOrderCurrencyCode',
                'getBaseDiscountAmount',
                'getEntityId',
                'setStatus',
                'save',
            ])
            ->getMock();
        $this->orderModel->method('load')->willReturnSelf();

        $this->objectManagerMock = $this->createMock(\Magento\Framework\ObjectManagerInterface::class);
        \Magento\Framework\App\ObjectManager::setInstance($this->objectManagerMock);
    }

    /**
     * @param object $rzpApiStub Stub exposing ->order->create($payload)
     */
    private function buildResolver(object $rzpApiStub): PlaceRazorpayOrder
    {
        $paymentMethod = $this->buildPaymentMethodReturning($rzpApiStub);

        return new PlaceRazorpayOrder(
            $this->scopeConfig,
            $this->getCartForUser,
            $this->cartManagement,
            $paymentMethod,
            $this->orderModel,
            $this->logger,
            $this->config,
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

        $this->objectManagerMock->method('get')->willReturn(
            $this->createMock(\Magento\Directory\Helper\Data::class)
        );

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

    private function mockOrderLinkSave(): void
    {
        $orderLink = $this->getMockBuilder(OrderLink::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save'])
            ->addMethods(['setRzpOrderId', 'setOrderId'])
            ->getMock();
        $orderLink->method('setRzpOrderId')->willReturnSelf();
        $orderLink->method('setOrderId')->willReturnSelf();

        $collection = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['addFilter', 'getFirstItem'])
            ->getMock();
        $collection->method('addFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($orderLink);

        $orderLinkModel = $this->getMockBuilder(OrderLink::class)
            ->disableOriginalConstructor()
            ->getMock();
        $orderLinkModel->method('getCollection')->willReturn($collection);

        $salesOrderModel = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load', 'setStatus', 'save', 'getEntityId'])
            ->getMock();
        $salesOrderModel->method('load')->willReturnSelf();
        $salesOrderModel->method('setStatus')->willReturnSelf();
        $salesOrderModel->method('getEntityId')->willReturn(999);

        $this->objectManagerMock->method('get')->willReturnCallback(
            function ($class) use ($orderLinkModel, $salesOrderModel) {
                if ($class === 'Razorpay\Magento\Model\OrderLink') {
                    return $orderLinkModel;
                }
                if ($class === 'Magento\Sales\Model\Order') {
                    return $salesOrderModel;
                }
                return $this->createMock(\Magento\Directory\Helper\Data::class);
            }
        );
    }

    public function testResolveWhenOrderIdMissingThenThrowsGraphQlInputException(): void
    {
        $resolver = $this->buildResolver(new \stdClass());

        $this->expectException(GraphQlInputException::class);
        $this->expectExceptionMessage('Required parameter "order_id" is missing');

        $resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            null,
            ['referrer' => 'https://example.com/checkout']
        );
    }

    public function testResolveWhenReferrerMissingThenThrowsGraphQlInputException(): void
    {
        $resolver = $this->buildResolver(new \stdClass());

        $this->expectException(GraphQlInputException::class);
        $this->expectExceptionMessage('Required parameter "referrer" is missing');

        $resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            null,
            ['order_id' => '000000123']
        );
    }

    public function testResolveWhenReferrerIsNotAValidUrlThenThrowsGraphQlInputException(): void
    {
        $resolver = $this->buildResolver(new \stdClass());

        $this->expectException(GraphQlInputException::class);
        $this->expectExceptionMessage('Parameter "referrer" is invalid');

        $resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            null,
            ['order_id' => '000000123', 'referrer' => 'not-a-url']
        );
    }

    public function testResolveWhenOrderDataIncompleteThenReturnsFailureWithoutThrowing(): void
    {
        // getGrandTotal()/getOrderCurrencyCode()/getBaseDiscountAmount() all
        // returning their mock default of null simulates "order not found".
        $resolver = $this->buildResolver(new \stdClass());

        $result = $resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            null,
            ['order_id' => '000000123', 'referrer' => 'https://example.com/checkout']
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unable to fetch order data', $result['message']);
    }

    public function testResolveWhenThreeDecimalCurrencyThenReturnsFailure(): void
    {
        $this->orderModel->method('getGrandTotal')->willReturn(100.0);
        $this->orderModel->method('getOrderCurrencyCode')->willReturn('KWD');
        $this->orderModel->method('getBaseDiscountAmount')->willReturn(0.0);

        $resolver = $this->buildResolver(new \stdClass());

        $result = $resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            null,
            ['order_id' => '000000123', 'referrer' => 'https://example.com/checkout']
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('KWD currency is not supported', $result['message']);
    }

    public function testResolveWhenApiSucceedsThenOrderLinkIsSavedAndSuccessReturned(): void
    {
        $this->orderModel->method('getGrandTotal')->willReturn(500.0);
        $this->orderModel->method('getOrderCurrencyCode')->willReturn('INR');
        $this->orderModel->method('getBaseDiscountAmount')->willReturn(0.0);
        $this->orderModel->method('getEntityId')->willReturn(999);

        $this->config->method('getNewOrderStatus')->willReturn('pending');
        $this->scopeConfig->method('getValue')->willReturn('capture');

        $rzpOrderEntity = (object) ['id' => 'order_RZP123'];
        $orderEntity = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['create'])
            ->getMock();
        $orderEntity->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $payload) {
                return $payload['receipt'] === '000000123'
                    && $payload['currency'] === 'INR'
                    && $payload['amount'] === 50000;
            }))
            ->willReturn($rzpOrderEntity);

        $rzpApiStub = new class ($orderEntity) {
            public $order;
            public function __construct($orderEntity)
            {
                $this->order = $orderEntity;
            }
        };

        $this->mockOrderLinkSave();

        $resolver = $this->buildResolver($rzpApiStub);

        $result = $resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            null,
            ['order_id' => '000000123', 'referrer' => 'https://example.com/checkout']
        );

        $this->assertTrue($result['success']);
        $this->assertSame('order_RZP123', $result['rzp_order_id']);
    }

    public function testResolveWhenApiThrowsRazorpayErrorThenReturnsFailureWithoutThrowing(): void
    {
        $this->orderModel->method('getGrandTotal')->willReturn(500.0);
        $this->orderModel->method('getOrderCurrencyCode')->willReturn('INR');
        $this->orderModel->method('getBaseDiscountAmount')->willReturn(0.0);

        $this->config->method('getNewOrderStatus')->willReturn('pending');
        $this->scopeConfig->method('getValue')->willReturn('capture');

        $orderEntity = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['create'])
            ->getMock();
        $orderEntity->method('create')->willThrowException(
            new \Razorpay\Api\Errors\Error('Order amount mismatch', null, 400)
        );

        $rzpApiStub = new class ($orderEntity) {
            public $order;
            public function __construct($orderEntity)
            {
                $this->order = $orderEntity;
            }
        };

        $resolver = $this->buildResolver($rzpApiStub);

        $result = $resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            null,
            ['order_id' => '000000123', 'referrer' => 'https://example.com/checkout']
        );

        $this->assertFalse($result['success']);
        $this->assertSame('Order amount mismatch', $result['message']);
    }
}
