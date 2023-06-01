<?php

declare(strict_types=1);

include_once __DIR__ . '/../../../Razorpay/src/Errors/Error.php';
include_once __DIR__ . '/../../../Razorpay/src/Api.php';

use PHPUnit\Framework\TestCase;

class PlaceRazorpayOrderResolverTest extends TestCase 
{
    public function setUp():void
    {
        $this->scopeConfig = \Mockery::mock(
            \Magento\Framework\App\Config\ScopeConfigInterface::class
        );

        $this->getCartForUser = \Mockery::mock(
            \Magento\QuoteGraphQl\Model\Cart\GetCartForUser::class
        );

        $this->cartManagement = \Mockery::mock(
            \Magento\Quote\Api\CartManagementInterface::class
        );
        
        $this->paymentMethod = \Mockery::mock(
            \Razorpay\Magento\Model\PaymentMethod::class
        );

        $this->order = \Mockery::mock(
            \Magento\Sales\Api\Data\OrderInterface::class
        );

        $this->logger = $this->createMock(
            \Psr\Log\LoggerInterface::class
        );

        $this->config = \Mockery::mock(
            \Razorpay\Magento\Model\Config::class
        );

        $this->field = \Mockery::mock(
            \Magento\Framework\GraphQl\Config\Element\Field::class
        );
        
        $this->context = \Mockery::mock(
            \Magento\Framework\App\Action\Context::class
        );

        $this->info = \Mockery::mock(
            \Magento\Framework\GraphQl\Schema\Type\ResolveInfo::class
        );

        $this->orderModel = \Mockery::mock(
            \Magento\Sales\Model\Order::class
        );

        $this->orderApi = \Mockery::mock(
            Razorpay\Api\Order::class
        );

        $this->objectManager = \Mockery::mock(
            \Magento\Framework\App\ObjectManager::class
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
        
        $this->paymentMethod->rzp = $this->api;

        $this->order->shouldReceive('load')->andReturn($this->orderModel);
        
        $this->scopeConfig->shouldReceive('getValue')->andReturn('authorize');

        $this->objectManager->shouldReceive('get')->with('Magento\Sales\Model\Order')->andReturn($this->orderModel);
        
        $this->orderModel->shouldReceive('setRzpOrderId');
        $this->orderModel->shouldReceive('save');
        $this->orderModel->shouldReceive('setStatus')->andReturn($this->orderModel);
        $this->orderModel->shouldReceive('load')->andReturn($this->orderModel);
        
        $this->config->shouldReceive('getNewOrderStatus')->andReturn('pending');

        $this->api->method('__get')->willReturnCallback(function ($propertyName)
        {
            switch($propertyName)
            {
                case 'order':
                    return $this->orderApi;
            }
        });

        $this->placeRazorpayOrder = \Mockery::mock(Razorpay\Magento\Model\Resolver\PlaceRazorpayOrder::class, 
                                        [
                                            $this->scopeConfig, 
                                            $this->getCartForUser,
                                            $this->cartManagement,
                                            $this->paymentMethod,
                                            $this->order,
                                            $this->logger,
                                            $this->config
                                        ]
                                     )->makePartial()
                                      ->shouldAllowMockingProtectedMethods();
        
        $this->orderID  = '000012';
        $this->referrer = 'http://127.0.0.1/magento2.4.5-p1/checkout';

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
            'notes' => [],
            'created_at' => 1666097548
        ];

        $this->setProperty($this->placeRazorpayOrder, '_objectManager', $this->objectManager);
    }

    function setProperty($object, $propertyName, $value)
    {
        $reflection = new \ReflectionClass($object);

        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);

        return $property->getValue($object);
    }

    public function testResolveEmptyOrderID()
    {
        $args = [
            'referrer'  => $this->referrer
        ];

        $this->expectException('Magento\Framework\GraphQl\Exception\GraphQlInputException');
        $this->expectExceptionMessage('Required parameter "order_id" is missing');

        $this->placeRazorpayOrder->resolve($this->field, $this->context, $this->info, null, $args);
    }

    public function testResolveEmptyReferrer()
    {
        $args = [
            'order_id'  => $this->orderID
        ];

        $this->expectException('Magento\Framework\GraphQl\Exception\GraphQlInputException');
        $this->expectExceptionMessage('Required parameter "referrer" is missing');
        
        $this->placeRazorpayOrder->resolve($this->field, $this->context, $this->info, null, $args);
    }

    public function testResolveInvalidReferrer()
    {
        $args = [
            'order_id'  => $this->orderID,
            'referrer'  => 'Test invalid referrer'
        ];

        $this->expectException('Magento\Framework\GraphQl\Exception\GraphQlInputException');
        $this->expectExceptionMessage('Parameter "referrer" is invalid');
        
        $this->placeRazorpayOrder->resolve($this->field, $this->context, $this->info, null, $args);
    }

    public function testResolveSuccess()
    {
        $expected = [
            'success'       => true,
            'rzp_order_id'  => 'order_test',
            'order_id'      => '000012',
            'amount'        => '100.00',
            'currency'      => 'INR',
            'message'       => 'Razorpay Order created successfully'
        ];

        $this->orderModel->shouldReceive('getGrandTotal')->andReturn(100);
        $this->orderModel->shouldReceive('getOrderCurrencyCode')->andReturn('INR');
        $this->orderModel->shouldReceive('getBaseDiscountAmount')->andReturn(0);
        
        $this->orderApi->shouldReceive('create')->andReturn((object)$this->orderData);

        $args = [
            'order_id'  => $this->orderID,
            'referrer'  => $this->referrer
        ];

        $response = $this->placeRazorpayOrder->resolve($this->field, $this->context, $this->info, null, $args);

        $this->assertSame($expected, $response);
    }

    public function testResolveApiError()
    {
        $expected = [
            'success'   => false,
            'message'   => 'Test Api error message'
        ];

        $this->orderModel->shouldReceive('getGrandTotal')->andReturn(100);
        $this->orderModel->shouldReceive('getOrderCurrencyCode')->andReturn('INR');
        $this->orderModel->shouldReceive('getBaseDiscountAmount')->andReturn(0);
        
        $this->orderApi->shouldReceive('create')->andThrow($this->apiError);

        $args = [
            'order_id'  => $this->orderID,
            'referrer'  => $this->referrer
        ];

        $response = $this->placeRazorpayOrder->resolve($this->field, $this->context, $this->info, null, $args);

        $this->assertSame($expected, $response);
    }

    public function testResolveException()
    {
        $expected = [
            'success'   => false,
            'message'   => 'Test exception message'
        ];

        $this->orderModel->shouldReceive('getGrandTotal')->andThrow(new Exception("Test exception message"));

        $this->orderApi->shouldReceive('create')->andThrow($this->apiError);

        $args = [
            'order_id'  => $this->orderID,
            'referrer'  => $this->referrer
        ];

        $response = $this->placeRazorpayOrder->resolve($this->field, $this->context, $this->info, null, $args);

        $this->assertSame($expected, $response);
    }

    public function testResolveEmptyRazorpayOrder()
    {
        $expected = [
            'success'   => false,
            'message'   => 'Razorpay Order not generated. Something went wrong'
        ];

        $this->orderModel->shouldReceive('getGrandTotal')->andReturn(100);
        $this->orderModel->shouldReceive('getOrderCurrencyCode')->andReturn('INR');
        $this->orderModel->shouldReceive('getBaseDiscountAmount')->andReturn(0);
        
        $this->orderApi->shouldReceive('create')->andReturn(null);

        $args = [
            'order_id'  => $this->orderID,
            'referrer'  => $this->referrer
        ];

        $response = $this->placeRazorpayOrder->resolve($this->field, $this->context, $this->info, null, $args);

        $this->assertSame($expected, $response);
    }

    public function testResolveOrderNotFetched()
    {
        $expected = [
            'success'   => false,
            'message'   => 'graphQL: Unable to fetch order data for Order ID: ' . $this->orderID
        ];

        $this->orderModel->shouldReceive('getGrandTotal')->andReturn(null);
        $this->orderModel->shouldReceive('getOrderCurrencyCode')->andReturn(null);
        $this->orderModel->shouldReceive('getBaseDiscountAmount')->andReturn(null);
        
        $args = [
            'order_id'  => $this->orderID,
            'referrer'  => $this->referrer
        ];

        $response = $this->placeRazorpayOrder->resolve($this->field, $this->context, $this->info, null, $args);

        $this->assertSame($expected, $response);
    }
}
