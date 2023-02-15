<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\UrlInterface;
use Razorpay\Magento\Test\MockFactory\MockApi;
use Razorpay\Api\Api;
use Magento\Framework\Controller\Result\Json;
use Psr\Log\Test\TestLogger;

/**
 * @covers Razorpay\Magento\Controller\Payment\Order
 */

class OrderControllerTest extends TestCase {
    private $storeManager;
    public function setUp():void
    {
        
        $this->context = \Mockery::mock(
            \Magento\Framework\App\Action\Context::class
        )->makePartial()->shouldAllowMockingProtectedMethods();
        $this->customerSession = $this->createMock(
            \Magento\Customer\Model\Session::class
        );
        $this->checkoutSession = \Mockery::mock(
            \Magento\Checkout\Model\Session::class
        );
        $this->checkoutFactory = $this->createMock(
            \Razorpay\Magento\Model\CheckoutFactory::class
        );
        $this->config = \Mockery::mock(
            \Razorpay\Magento\Model\Config::class
        );
        $this->catalogSession = $this->createMock(
            \Magento\Catalog\Model\Session::class
        );
        $this->storeManager = \Mockery::mock(
            \Magento\Store\Model\StoreManagerInterface::class
        );
        $this->logger = $this->createMock(
            \Psr\Log\LoggerInterface::class
        );
        //$this->logger->expects('info')->with("Razorpay Webhook with existing secret.");
        $this->store = \Mockery::mock(
            \Magento\Store\Model\Store::class
        );
        $this->order = \Mockery::mock(
            \Magento\Sales\Model\Order::class
        );
        $this->_objectManager = \Mockery::mock(
            \Magento\Framework\ObjectManagerInterface::class
        );
        $this->productMetadataInterfac = \Mockery::mock(
            Magento\Framework\App\ProductMetadataInterface::class
        );
        $this->moduleList = \Mockery::mock(
            Magento\Framework\Module\ModuleList::class
        );
        $this->resultFactoryMock = \Mockery::mock(
            Magento\Framework\Controller\ResultFactory::class
        )->makePartial()->shouldAllowMockingProtectedMethods();
        $this->serializerJson = new \Magento\Framework\Serialize\Serializer\Json;
        $this->translateInline = \Mockery::mock(
            \Magento\Framework\Translate\InlineInterface::class
        );
        $this->json = new Json($this->translateInline,
        $this->serializerJson);
        $this->HttpInterface = \Mockery::mock(
            Magento\Framework\App\Response\HttpInterface::class
        );
        $this->resultInterface = \Mockery::mock(
            Magento\Framework\Controller\ResultInterface::class
        )->makePartial();
        
        $this->config->shouldReceive('getConfigData')->with('key_id')->andReturn('key_id');
        $this->config->shouldReceive('getConfigData')->with('key_secret')->andReturn('key_secret');

        $this->storeManager->shouldReceive('getStore')->andReturn($this->store);
        $this->store->shouldReceive('getBaseUrl')->with('web')->andReturn('https://example.com/');

        $this->order = \Mockery::mock(Razorpay\Magento\Controller\Payment\Order::class, [$this->context, 
                                                                                        $this->customerSession,
                                                                                        $this->checkoutSession,
                                                                                        $this->checkoutFactory,
                                                                                        $this->config,
                                                                                        $this->catalogSession,
                                                                                        $this->storeManager,
                                                                                        $this->logger])->makePartial()->shouldAllowMockingProtectedMethods();
        
        // $this->api = $this->createStub(Razorpay\Api\Api::class);
        $this->api = $this->getMockBuilder(Razorpay\Api\Api::class)
                     ->disableOriginalConstructor()
                     ->disableOriginalClone()
                     ->disableArgumentCloning()
                     ->disallowMockingUnknownTypes()
                     ->getMock();

        $this->webhookData = ['entity' => 'collection',
                            'count' => 1,
                            'items' => [
                                [
                                    'id' => 'LDATzQq2wsBBBB',
                                    'url' => 'https://www.example-two.com/razorpay/payment/webhook',
                                    'entity' => 'webhook',
                                    'active' => true,
                                    'events' => [
                                        'payment.authorized' => true,
                                        'order.paid' => true,
                                    ]
                                ],
                            ]
                            ];
        $this->merchantPreferences = [
            'embedded_url' => 'https://api.razorpay.com/v1/checkout/embedded',
            'is_hosted' => false,
            'image' => null
        ];
        $this->orderData = [
            'id' => 'order_test',
            'entity' => 'order',
            'amount' => 10000,
            'amount_paid' => 0,
            'amount_due' => 0,
            'currency' => 'INR',
            'receipt' => '11',
            'offer_id' => null,
            'status' => 'created',
            'attempts' => 0,
            'notes' => [
                'woocommerce_order_number' => '11'
            ],
            'created_at' => 1666097548
        ];

    }
    function getProperty($object, $propertyName)
    {
        $reflection = new \ReflectionClass($object);
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);
        return $property->getValue($object);
    }
    function testExecuteSuccess()
    {

        $this->resultFactoryMock->shouldReceive('create')->with('json')->andReturn($this->json);

        $this->config->shouldReceive('getConfigData')->with('webhook_triggered_at')->andReturn('1645263824');
        
        $this->config->shouldReceive('getConfigData')->with('webhook_events')->andReturn('order.paid,payment.authorized');
        $this->config->shouldReceive('getConfigData')->with('supported_webhook_events')->andReturn('order.paid,payment.authorized');
        $this->config->shouldReceive('getConfigData')->with('enable_webhook')->andReturn(1);
        $this->config->shouldReceive('getConfigData')->with('webhook_secret')->andReturn('1daswefjwgkjb21ldsvn');
        $this->config->shouldReceive('getPaymentAction')->andReturn('authorize');
        $this->config->shouldReceive('getNewOrderStatus')->andReturn('pending');
        $this->config->shouldReceive('getKeyId')->andReturn('rzp_test_1F4C1VMZlSBWcy');

        

        $this->order->shouldReceive('getGrandTotal')->andReturn(1000);
        $this->order->shouldReceive('getIncrementId')->andReturn('000012');
        $this->order->shouldReceive('getOrderCurrencyCode')->andReturn('INR');
        $this->order->shouldReceive('getEntityId')->andReturn('000012');

        $this->checkoutSession->shouldReceive('getLastRealOrder')->andReturn($this->order);

        $this->_objectManager->shouldReceive('get')->with('Magento\Framework\App\ProductMetadataInterface')->andReturn($this->productMetadataInterfac);
        $this->_objectManager->shouldReceive('get')->with('Magento\Framework\Module\ModuleList')->andReturn($this->moduleList);
        $this->_objectManager->shouldReceive('get')->with('Magento\Sales\Model\Order')->andReturn($this->order);

        $this->productMetadataInterfac->shouldReceive('getVersion')->andReturn('2.4.5-p1');
        $this->moduleList->shouldReceive('getOne')->with('Razorpay_Magento')->andReturn(['setup_version' => '4.0.2']);

        $this->context->shouldReceive('getResultFactory')->andReturn($this->resultFactoryMock);
        
        
        $this->order->shouldReceive('getWebhooks')->andReturn((object)$this->webhookData);
        $this->order->shouldReceive('getMerchantPreferences')->andReturn($this->merchantPreferences);
        
        $this->orderApi = \Mockery::mock(
            Razorpay\Api\Order::class
        );
        $this->orderApi->shouldReceive('create')->andReturn((object) $this->orderData);
       
        $this->api->method('__get')
        ->with($this->equalTo('order'))
        ->will($this->returnValue($this->orderApi));
        // $api = new Api('rzp_test_1F4C1VMZlSBWcy', 'Y5LcmGVRfwicofmUo2SfI0iE');

        $this->order->rzp = $this->api;
        $this->resultFactory = $this->context->getResultFactory();
        // var_dump($resultFactory);
        $this->order->setMockInit($this->_objectManager, $this->resultFactory);
        //var_dump($this->order->getWebhooks());
        //var_dump($this->order->_objectManager);
        
        $response = $this->order->execute();
        $expectedResponse = '{"success":true,"rzp_order":"order_test","order_id":"000012","amount":10000,"quote_currency":"INR","quote_amount":"1000.00","maze_version":"2.4.5-p1","module_version":"4.0.2","is_hosted":false,"image":null,"embedded_url":"https:\/\/api.razorpay.com\/v1\/checkout\/embedded"}';
        $this->assertSame($expectedResponse, $this->getProperty($response, 'json'));
    }
    function testExecuteSuccess2()
    {

        $this->resultFactoryMock->shouldReceive('create')->with('json')->andReturn($this->json);

        $this->config->shouldReceive('getConfigData')->with('webhook_triggered_at')->andReturn('1645263824');
        
        $this->config->shouldReceive('getConfigData')->with('webhook_events')->andReturn('order.paid,payment.authorized');
        $this->config->shouldReceive('getConfigData')->with('supported_webhook_events')->andReturn('order.paid,payment.authorized');
        $this->config->shouldReceive('getConfigData')->with('enable_webhook')->andReturn(1);
        $this->config->shouldReceive('getConfigData')->with('webhook_secret')->andReturn(null);
        $this->config->shouldReceive('getPaymentAction')->andReturn('authorize_capture');
        $this->config->shouldReceive('getNewOrderStatus')->andReturn('pending');
        $this->config->shouldReceive('getKeyId')->andReturn('rzp_test_1F4C1VMZlSBWcy');

        

        $this->order->shouldReceive('getGrandTotal')->andReturn(1000);
        $this->order->shouldReceive('getIncrementId')->andReturn('000012');
        $this->order->shouldReceive('getOrderCurrencyCode')->andReturn('INR');
        $this->order->shouldReceive('getEntityId')->andReturn('000012');

        $this->checkoutSession->shouldReceive('getLastRealOrder')->andReturn($this->order);

        $this->_objectManager->shouldReceive('get')->with('Magento\Framework\App\ProductMetadataInterface')->andReturn($this->productMetadataInterfac);
        $this->_objectManager->shouldReceive('get')->with('Magento\Framework\Module\ModuleList')->andReturn($this->moduleList);
        $this->_objectManager->shouldReceive('get')->with('Magento\Sales\Model\Order')->andReturn($this->order);

        $this->productMetadataInterfac->shouldReceive('getVersion')->andReturn('2.4.5-p1');
        $this->moduleList->shouldReceive('getOne')->with('Razorpay_Magento')->andReturn(['setup_version' => '4.0.2']);

        $this->context->shouldReceive('getResultFactory')->andReturn($this->resultFactoryMock);
        
        
        $this->order->shouldReceive('getWebhooks')->andReturn($this->webhookData);
        $this->order->shouldReceive('getMerchantPreferences')->andReturn($this->merchantPreferences);
        
        $this->orderApi = \Mockery::mock(
            Razorpay\Api\Order::class
        );
        $this->orderApi->shouldReceive('create')->andReturn((object) $this->orderData);
       
        $this->api->method('__get')
        ->with($this->equalTo('order'))
        ->will($this->returnValue($this->orderApi));
        // $api = new Api('rzp_test_1F4C1VMZlSBWcy', 'Y5LcmGVRfwicofmUo2SfI0iE');

        $this->order->rzp = $this->api;
        $this->resultFactory = $this->context->getResultFactory();
        // var_dump($resultFactory);
        $this->order->setMockInit($this->_objectManager, $this->resultFactory);
        //var_dump($this->order->getWebhooks());
        //var_dump($this->order->_objectManager);
        $tl = new TestLogger();
        $response = $this->order->execute();
        //$tl->hasRecord("Razorpay Webhook with existing secret.");
        $expectedResponse = '{"success":true,"rzp_order":"order_test","order_id":"000012","amount":10000,"quote_currency":"INR","quote_amount":"1000.00","maze_version":"2.4.5-p1","module_version":"4.0.2","is_hosted":false,"image":null,"embedded_url":"https:\/\/api.razorpay.com\/v1\/checkout\/embedded"}';
        $this->assertSame($expectedResponse, $this->getProperty($response, 'json'));
    }
}
