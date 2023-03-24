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

        $this->orderModel = \Mockery::mock(
            \Magento\Sales\Model\Order::class
        )->makePartial();

        $this->store = \Mockery::mock(
            \Magento\Store\Model\Store::class
        );

        $this->order = \Mockery::mock(
            \Magento\Sales\Model\Order::class
        );

        $this->_objectManager = \Mockery::mock(
            \Magento\Framework\ObjectManagerInterface::class
        );

        $this->productMetadataInterface = \Mockery::mock(
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

        $this->orderApi = \Mockery::mock(
            Razorpay\Api\Order::class
        );

        $this->webhookApi = \Mockery::mock(
            Razorpay\Api\Webhook::class
        );

        $this->requestApi = \Mockery::mock(
            Razorpay\Api\Request::class
        );

        $this->apiError = \Mockery::mock(
            Razorpay\Api\Errors\Error::class, ['Test Api error message', 0, 0]
        );

        $this->api = $this->getMockBuilder(Razorpay\Api\Api::class)
                     ->disableOriginalConstructor()
                     ->disableOriginalClone()
                     ->disableArgumentCloning()
                     ->disallowMockingUnknownTypes()
                     ->getMock();
        
        $this->context->shouldReceive('getResultFactory')->andReturn($this->resultFactoryMock);
       
        $this->config->shouldReceive('getConfigData')->with('webhook_triggered_at')->andReturn('1645263824');
        $this->config->shouldReceive('getConfigData')->with('key_id')->andReturn('key_id');
        $this->config->shouldReceive('getConfigData')->with('key_secret')->andReturn('key_secret');
        $this->config->shouldReceive('getKeyId')->andReturn('key_id');
        $this->config->shouldReceive('getConfigData')->with('webhook_events')->andReturn('order.paid,payment.authorized');
        $this->config->shouldReceive('getConfigData')->with('supported_webhook_events')->andReturn('order.paid,payment.authorized');
        $this->config->shouldReceive('getConfigData')->with('enable_webhook')->andReturn(1);
        $this->config->shouldReceive('getNewOrderStatus')->andReturn('pending');
        $this->config->shouldReceive('setConfigData');

        $this->storeManager->shouldReceive('getStore')->andReturn($this->store);

        $this->store->shouldReceive('getBaseUrl')->with('web')->andReturn('https://www.example.com/');

        $this->orderModel->shouldReceive('load')->andReturn($this->orderModel);
        $this->orderModel->shouldReceive('setState')->andReturn($this->orderModel);
        $this->orderModel->shouldReceive('setStatus')->andReturn($this->orderModel);
        $this->orderModel->shouldReceive('save')->andReturn($this->orderModel);
        
        $this->resultFactoryMock->shouldReceive('create')->with('json')->andReturn($this->json);

        $this->_objectManager->shouldReceive('get')->with('Magento\Framework\App\ProductMetadataInterface')->andReturn($this->productMetadataInterface);
        $this->_objectManager->shouldReceive('get')->with('Magento\Framework\Module\ModuleList')->andReturn($this->moduleList);
        $this->_objectManager->shouldReceive('get')->with('Magento\Sales\Model\Order')->andReturn($this->orderModel);

        $this->productMetadataInterface->shouldReceive('getVersion')->andReturn('2.4.5-p1');
        $this->moduleList->shouldReceive('getOne')->with('Razorpay_Magento')->andReturn(['setup_version' => '4.0.2']);

        $this->webhookApi->shouldReceive('all')->with(['count' => 10, 'skip' => 1])->andReturn(['count' => 0]);
        $this->webhookApi->shouldReceive('all')->with(['count' => 10, 'skip' => 2])->andReturn(['count' => 0]);

        $this->api->method('__get')->willReturnCallback(function ($propertyName)
        {
            switch($propertyName)
            {
                case 'webhook':
                    return $this->webhookApi;
                case 'order':
                    return $this->orderApi;
                case 'request':
                    return $this->requestApi;
            }
        });

        $this->order = \Mockery::mock(Razorpay\Magento\Controller\Payment\Order::class, [$this->context, 
                                                                                        $this->customerSession,
                                                                                        $this->checkoutSession,
                                                                                        $this->checkoutFactory,
                                                                                        $this->config,
                                                                                        $this->catalogSession,
                                                                                        $this->storeManager,
                                                                                        $this->logger])->makePartial()->shouldAllowMockingProtectedMethods();

        $this->order->shouldReceive('getGrandTotal')->andReturn(1000);
        $this->order->shouldReceive('getIncrementId')->andReturn('000012');
        $this->order->shouldReceive('getOrderCurrencyCode')->andReturn('INR');
        $this->order->shouldReceive('getEntityId')->andReturn('000012');
        $this->order->shouldReceive('getPublicRazorpayApiInstance')->andReturn($this->api);

        $this->checkoutSession->shouldReceive('getLastRealOrder')->andReturn($this->order);

        $this->webhookData = ['entity' => 'collection',
                              'count' => 1,
                              'items' => [
                                    (object) [
                                        'id' => 'LDATzQq2wsBBBB',
                                        'url' => 'https://www.example.com/razorpay/payment/webhook',
                                        'entity' => 'webhook',
                                        'active' => true,
                                        'events' => [
                                            'payment.authorized' => true,
                                            'order.paid' => true,
                                        ]
                                    ],
                                ]
                             ];
        
        $this->webhookData2 = ['entity' => 'collection',
                               'count' => 1,
                               'items' => [
                                    (object)[
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

        $this->orderData = [ 'id' => 'order_test',
                             'entity' => 'order',
                             'amount' => 10000,
                             'amount_paid' => 0,
                             'amount_due' => 0,
                             'currency' => 'INR',
                             'receipt' => '11',
                             'offer_id' => null,
                             'status' => 'created',
                             'attempts' => 0,
                             'notes' => [],
                             'created_at' => 1666097548
                          ];

        $this->merchantPreferences = ["options" => [
            "image"=> "https://cdn.razorpay.com/logos/IjFWzUIxXibcjw_medium.jpeg",
            "redirect"=> true
        ]];

        $this->order->rzp = $this->api;
        $this->resultFactory = $this->context->getResultFactory();
        $this->order->setMockInit($this->_objectManager, $this->resultFactory);
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
        $this->config->shouldReceive('getConfigData')->with('webhook_secret')->andReturn('1daswefjwgkjb21ldsvn');
        $this->config->shouldReceive('getPaymentAction')->andReturn('authorize');

        $this->orderApi->shouldReceive('create')->andReturn((object) $this->orderData);
       
        $this->webhookApi->shouldReceive('edit')->andReturn("Test Webhook Edit Response");
        $this->webhookApi->shouldReceive('create')->andReturn("Test Webhook Create Response");
        $this->webhookApi->shouldReceive('all')->with(['count' => 10, 'skip' => 0])->andReturn($this->webhookData);
        $this->requestApi->shouldReceive('request')->with("GET", "preferences")->andReturn($this->merchantPreferences);
        
        $response = $this->order->execute();
        $expectedResponse = '{"success":true,"rzp_order":"order_test","order_id":"000012","amount":10000,"quote_currency":"INR","quote_amount":"1000.00","maze_version":"2.4.5-p1","module_version":"4.0.2","is_hosted":true,"image":"https:\/\/cdn.razorpay.com\/logos\/IjFWzUIxXibcjw_medium.jpeg","embedded_url":"https:\/\/api.razorpay.com\/v1\/checkout\/embedded"}';

        $this->assertSame($expectedResponse, $this->getProperty($response, 'json'));
    }

    function testExecuteOrderApiFailure()
    {
        $this->config->shouldReceive('getConfigData')->with('webhook_secret')->andReturn(null);
        $this->config->shouldReceive('getPaymentAction')->andReturn('authorize_capture');
        
        $this->orderApi->shouldReceive('create')->andThrow($this->apiError);
        $this->webhookApi->shouldReceive('edit')->andThrow($this->apiError);
        $this->webhookApi->shouldReceive('create')->andThrow($this->apiError);
        $this->webhookApi->shouldReceive('all')->with(['count' => 10, 'skip' => 0])->andReturn($this->webhookData2);

        $this->requestApi->shouldReceive('request')->with("GET", "preferences")->andReturn($this->merchantPreferences);

        $response = $this->order->execute();
        $expectedResponse = '{"message":"Test Api error message","parameters":[]}';

        $this->assertSame($expectedResponse, $this->getProperty($response, 'json'));
    }

    function testExecuteOrderCreationException()
    {
        $this->config->shouldReceive('getConfigData')->with('webhook_secret')->andReturn(null);
        $this->config->shouldReceive('getPaymentAction')->andReturn('authorize_capture');
        
        $this->orderApi->shouldReceive('create')->andThrow(new Exception("Test exception message"));
        $this->webhookApi->shouldReceive('edit')->andThrow(new Exception("Test exception message"));
        $this->webhookApi->shouldReceive('create')->andThrow(new Exception("Test exception message"));
        $this->webhookApi->shouldReceive('all')->with(['count' => 10, 'skip' => 0])->andReturn($this->webhookData);

        $this->requestApi->shouldReceive('request')->with("GET", "preferences")->andReturn($this->merchantPreferences);

        $response = $this->order->execute();
        $expectedResponse = '{"message":"Test exception message","parameters":[]}';

        $this->assertSame($expectedResponse, $this->getProperty($response, 'json'));
    }

    function testGetWebhooksApiFailure()
    {
        $this->config->shouldReceive('getConfigData')->with('webhook_secret')->andReturn(null);
        $this->config->shouldReceive('getPaymentAction')->andReturn('authorize_capture');
        
        $this->orderApi->shouldReceive('create')->andThrow($this->apiError);
        $this->webhookApi->shouldReceive('edit')->andReturn("hehe");
        $this->webhookApi->shouldReceive('create')->andReturn("hehe2");
        $this->webhookApi->shouldReceive('all')->with(['count' => 10, 'skip' => 0])->andThrow($this->apiError);

        $this->requestApi->shouldReceive('request')->with("GET", "preferences")->andReturn($this->merchantPreferences);

        $response = $this->order->execute();
        $expectedResponse = '{"message":"Test Api error message","parameters":[]}';

        $this->assertSame($expectedResponse, $this->getProperty($response, 'json'));
    }

    function testGetWebhooksException()
    {
        $this->config->shouldReceive('getConfigData')->with('webhook_secret')->andReturn(null);
        $this->config->shouldReceive('getPaymentAction')->andReturn('authorize_capture');
        
        $this->orderApi->shouldReceive('create')->andThrow(new Exception("Test exception message"));
        $this->webhookApi->shouldReceive('edit')->andReturn("hehe");
        $this->webhookApi->shouldReceive('create')->andReturn("hehe2");
        $this->webhookApi->shouldReceive('all')->with(['count' => 10, 'skip' => 0])->andThrow(new Exception("Test exception message"));

        $this->requestApi->shouldReceive('request')->with("GET", "preferences")->andReturn($this->merchantPreferences);

        $response = $this->order->execute();
        $expectedResponse = '{"message":"Test exception message","parameters":[]}';

        $this->assertSame($expectedResponse, $this->getProperty($response, 'json'));
    }

    function testGetPreferencesApiFailure()
    {
        $this->requestApi->shouldReceive('request')->with("GET", "preferences")->andThrow($this->apiError);

        $expected = [
            "embedded_url" => "https://api.razorpay.com/v1/checkout/embedded",
            "is_hosted" => false,
            "image" => ""
        ];

        $this->assertSame($expected, $this->order->getMerchantPreferences());
    }
}
