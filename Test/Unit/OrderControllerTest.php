<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\UrlInterface;
use Razorpay\Magento\Test\MockFactory\MockApi;
use Razorpay\Api\Api;
/**
 * @covers Razorpay\Magento\Controller\Payment\Order
 */

class OrderControllerTest extends TestCase {
    private $storeManager;
    public function setUp():void
    {
        

        $this->order = \Mockery::mock(Razorpay\Magento\Controller\Payment\Order::class)->makePartial()->shouldAllowMockingProtectedMethods();

        // $this->api = $this->createStub(Razorpay\Api\Api::class);
        $this->api = $this->getMockBuilder(Razorpay\Api\Api::class)
                     ->disableOriginalConstructor()
                     ->disableOriginalClone()
                     ->disableArgumentCloning()
                     ->disallowMockingUnknownTypes()
                     ->getMock();

        // $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        // $this->objectManager = \Magento\Framework\TestFramework\Unit\Helper\ObjectManager::getInstance();
        $this->objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        // var_dump($this->objectManager);

        $this->order_create = [
            'id' => 'yash',
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
    function testExecuteSuccess()
    {
        // $this->rzpMagentoMock = new Razorpay\Magento\Test\Mockfactory\ControllerMockApi;
        // $this->rzpMagentoMock->setResonseType('execute_success');
        
        // var_dump( $this->rzpMagentoMock->call('POST', 'razorpay/payment/order') );
        
        // $this->rzpMagentoMock->setResonseType('execute_failed');
        // var_dump( $this->rzpMagentoMock->call('POST', 'razorpay/payment/order') );

        $context = \Mockery::mock(
            \Magento\Framework\App\Action\Context::class
        )->makePartial()->shouldAllowMockingProtectedMethods();
        $customerSession = $this->createMock(
            \Magento\Customer\Model\Session::class
        );
        $checkoutSession = \Mockery::mock(
            \Magento\Checkout\Model\Session::class
        );
        $checkoutFactory = $this->createMock(
            \Razorpay\Magento\Model\CheckoutFactory::class
        );
        $config = \Mockery::mock(
            \Razorpay\Magento\Model\Config::class
        );
        $catalogSession = $this->createMock(
            \Magento\Catalog\Model\Session::class
        );
        $storeManager = \Mockery::mock(
            \Magento\Store\Model\StoreManagerInterface::class
        );
        $logger = $this->createMock(
            \Psr\Log\LoggerInterface::class
        );
        $store = \Mockery::mock(
            \Magento\Store\Model\Store::class
        );
        $order = \Mockery::mock(
            \Magento\Sales\Model\Order::class
        );
        $_objectManager = \Mockery::mock(
            \Magento\Framework\ObjectManagerInterface::class
        );
        $productMetadataInterfac = \Mockery::mock(
            Magento\Framework\App\ProductMetadataInterface::class
        );
        $moduleList = \Mockery::mock(
            Magento\Framework\Module\ModuleList::class
        );
        $resultFactoryMock = \Mockery::mock(
            Magento\Framework\Controller\ResultFactory::class
        )->makePartial()->shouldAllowMockingProtectedMethods();
        $serializerJson = \Mockery::mock(
            \Magento\Framework\Serialize\Serializer\Json::class
        )->makePartial()->shouldAllowMockingProtectedMethods();
        $translateInline = \Mockery::mock(
            \Magento\Framework\Translate\InlineInterface::class
        );
        $json = \Mockery::mock(
            Magento\Framework\Controller\Result\Json::class, [
                $translateInline,
                $serializerJson
            ]
        )->makePartial()->shouldAllowMockingProtectedMethods();
        $HttpInterface = \Mockery::mock(
            Magento\Framework\App\Response\HttpInterface::class
        );
        $resultInterface = \Mockery::mock(
            Magento\Framework\Controller\ResultInterface::class
        )->makePartial();

        $resultFactoryMock->shouldReceive('create')->with('json')->andReturn($json);

        $config->shouldReceive('getConfigData')->with('webhook_triggered_at')->andReturn('1645263824');
        $config->shouldReceive('getConfigData')->with('key_id')->andReturn('key_id');
        $config->shouldReceive('getConfigData')->with('key_secret')->andReturn('key_secret');
        $config->shouldReceive('getPaymentAction')->andReturn('authorize');
        $config->shouldReceive('getNewOrderStatus')->andReturn('pending');
        $config->shouldReceive('getKeyId')->andReturn('rzp_test_1F4C1VMZlSBWcy');

        $storeManager->shouldReceive('getStore')->andReturn($store);
        $store->shouldReceive('getBaseUrl')->with('web')->andReturn('https://example.com/');

        $order->shouldReceive('getGrandTotal')->andReturn(1000);
        $order->shouldReceive('getIncrementId')->andReturn('000012');
        $order->shouldReceive('getOrderCurrencyCode')->andReturn('INR');

        $checkoutSession->shouldReceive('getLastRealOrder')->andReturn($order);

        $_objectManager->shouldReceive('get')->with('Magento\Framework\App\ProductMetadataInterface')->andReturn($productMetadataInterfac);
        $_objectManager->shouldReceive('get')->with('Magento\Framework\Module\ModuleList')->andReturn($moduleList);
        $_objectManager->shouldReceive('get')->with('Magento\Sales\Model\Order')->andReturn($order);

        $productMetadataInterfac->shouldReceive('getVersion')->andReturn('2.4.5-p1');
        $moduleList->shouldReceive('getOne')->with('Razorpay_Magento')->andReturn(['setup_version' => '4.0.2']);

        $context->shouldReceive('getResultFactory')->andReturn($resultFactoryMock);

        $this->order = \Mockery::mock(Razorpay\Magento\Controller\Payment\Order::class, [$context, 
                                                                                        $customerSession,
                                                                                        $checkoutSession,
                                                                                        $checkoutFactory,
                                                                                        $config,
                                                                                        $catalogSession,
                                                                                        $storeManager,
                                                                                        $logger])->makePartial()->shouldAllowMockingProtectedMethods();
        
        
        $this->order->shouldReceive('getWebhooks')->andReturn(['entity' => 'collection',
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
                                                            ]);
        $this->order->shouldReceive('getMerchantPreferences')->andReturn([
            'embedded_url' => 'https://api.razorpay.com/v1/checkout/embedded',
            'is_hosted' => false,
            'image' => null
        ]

        );
        
        $orderApi = \Mockery::mock(
            Razorpay\Api\Order::class
        );
        $orderApi->shouldReceive('create')->andReturn(
                                                    (object) [
                                                        'id' => 'yash123',
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
                                                    ]
                                                );
       
        $this->api->method('__get')
        ->with($this->equalTo('order'))
        ->will($this->returnValue($orderApi));
        // $api = new Api('rzp_test_1F4C1VMZlSBWcy', 'Y5LcmGVRfwicofmUo2SfI0iE');

        $this->order->rzp = $this->api;
        $resultFactory = $context->getResultFactory();
        // var_dump($resultFactory);
        $this->order->setMockInit($_objectManager, $resultFactory);
        //var_dump($this->order->getWebhooks());
        //var_dump($this->order->_objectManager);
        $response = $this->order->execute();
            
        $json->shouldReceive('render',$HttpInterface)->andReturn('ssss');
        
        // $r = $resultInterface->renderResult('',$response->renderResult());

        // print_r($response->renderResult($resultInterface));
        
        $this->assertEmpty('');
    }
}
