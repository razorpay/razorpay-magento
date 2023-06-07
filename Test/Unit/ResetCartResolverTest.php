<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ResetCartResolverTest extends TestCase
{
    public function setup():void
    {
        $this->logger = $this->createMock(
            \Psr\Log\LoggerInterface::class
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

        $this->objectManager = \Mockery::mock(
            \Magento\Framework\App\ObjectManager::class
        );

        $this->orderModel = \Mockery::mock(
            \Magento\Sales\Model\Order::class
        );

        $this->quoteModel = \Mockery::mock(
            \Magento\Quote\Model\Quote::class
        );

        $this->orderModel->shouldReceive('load')->andReturn($this->orderModel);
        $this->orderModel->shouldReceive('setStatus')->andReturn($this->orderModel);
        $this->orderModel->shouldReceive('save');

        $this->quoteModel->shouldReceive('load')->andReturn($this->quoteModel);
        $this->quoteModel->shouldReceive('setIsActive')->andReturn($this->quoteModel);
        $this->quoteModel->shouldReceive('save');
        
        $this->objectManager->shouldReceive('get')->with('Magento\Sales\Model\Order')->andReturn($this->orderModel);
        $this->objectManager->shouldReceive('get')->with('Magento\Quote\Model\Quote')->andReturn($this->quoteModel);
        
        $this->resetCart = \Mockery::mock(Razorpay\Magento\Model\Resolver\ResetCart::class, 
                                            [
                                                $this->logger
                                            ]
                                        )->makePartial()->shouldAllowMockingProtectedMethods();

        $this->resetCart->objectManager = $this->objectManager;
        $this->orderID  = '000012';
    }

    public function testResolveEmptyOrderID()
    {
        $args = [];

        $this->expectException('Magento\Framework\GraphQl\Exception\GraphQlInputException');
        $this->expectExceptionMessage('Required parameter "order_id" is missing');

        $this->resetCart->resolve($this->field, $this->context, $this->info, null, $args);
    }

    public function testResolveSuccess()
    {
        $expected = [
            'success'   => true
        ];

        $this->orderModel->shouldReceive('canCancel')->andReturn(true);
        $this->orderModel->shouldReceive('getQuoteId')->andReturn('Test Quote ID');
        
        $args = [
            'order_id'  => $this->orderID
        ];

        $response = $this->resetCart->resolve($this->field, $this->context, $this->info, null, $args);

        $this->assertSame($expected, $response);
    }

    public function testResolveOrderNotCancelable()
    {
        $expected = [
            'success'   => false
        ];

        $this->orderModel->shouldReceive('canCancel')->andReturn(false);

        $args = [
            'order_id'  => $this->orderID
        ];

        $response = $this->resetCart->resolve($this->field, $this->context, $this->info, null, $args);

        $this->assertSame($expected, $response);
    }

    public function testResolveException()
    {
        $expected = [
            'success'   => false
        ];

        $this->orderModel->shouldReceive('canCancel')->andThrow(new Exception("Test exception message"));

        $args = [
            'order_id'  => $this->orderID,
        ];

        $response = $this->resetCart->resolve($this->field, $this->context, $this->info, null, $args);

        $this->assertSame($expected, $response);
    }
}
