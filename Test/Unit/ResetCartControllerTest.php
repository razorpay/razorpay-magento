<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Magento\Framework\Controller\Result\Json;

class ResetCartControllerTest extends TestCase
{
    public function setup():void
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

        $this->catalogSession = \Mockery::mock(
            \Magento\Catalog\Model\Session::class
        );

        $this->logger = $this->createMock(
            \Psr\Log\LoggerInterface::class
        );

        $this->orderModel = \Mockery::mock(
            \Magento\Sales\Model\Order::class
        );

        $this->objectManager = \Mockery::mock(
            \Magento\Framework\App\ObjectManager::class
        );

        $this->quoteModel = \Mockery::mock(
            \Magento\Quote\Model\Quote::class
        );

        $this->messageManager = \Mockery::mock(
            \Magento\Framework\Message\ManagerInterface::class
        );

        $this->resultFactoryMock = \Mockery::mock(
            Magento\Framework\Controller\ResultFactory::class
        )->makePartial()->shouldAllowMockingProtectedMethods();

        $this->serializerJson = new \Magento\Framework\Serialize\Serializer\Json;

        $this->translateInline = \Mockery::mock(
            \Magento\Framework\Translate\InlineInterface::class
        );

        $this->json = new Json($this->translateInline, $this->serializerJson);

        $this->context->shouldReceive('getMessageManager')->andReturn($this->messageManager);
        $this->context->shouldReceive('getResultFactory')->andReturn($this->resultFactoryMock);
        
        $this->config->shouldReceive('getConfigData')->with('key_id')->andReturn('key_id');
        $this->config->shouldReceive('getConfigData')->with('key_secret')->andReturn('key_secret');
        $this->config->shouldReceive('getKeyId')->andReturn('key_id');

        $this->checkoutSession->shouldReceive('getLastRealOrder')->andReturn($this->orderModel);
        $this->checkoutSession->shouldReceive('replaceQuote');
        $this->checkoutSession->shouldReceive('setFirstTimeChk');
        
        $this->objectManager->shouldReceive('get')->with('Magento\Sales\Model\Order')->andReturn($this->orderModel);
        $this->objectManager->shouldReceive('get')->with('Magento\Quote\Model\Quote')->andReturn($this->quoteModel);
        
        $this->orderModel->shouldReceive('load')->andReturn($this->orderModel);
        $this->orderModel->shouldReceive('getEntityId')->andReturn('000012');
        $this->orderModel->shouldReceive('canCancel')->andReturn(true);
        $this->orderModel->shouldReceive('setStatus');
        $this->orderModel->shouldReceive('save');
        
        $this->quoteModel->shouldReceive('load')->andReturn($this->quoteModel);
        $this->quoteModel->shouldReceive('setIsActive')->andReturn($this->quoteModel);
        $this->quoteModel->shouldReceive('save')->andReturn($this->quoteModel);
        
        $this->messageManager->shouldReceive('addError');
        
        $this->resultFactoryMock->shouldReceive('create')->with('json')->andReturn($this->json);

        $this->resetCart = \Mockery::mock(Razorpay\Magento\Controller\Payment\ResetCart::class, 
                                            [
                                                $this->context,
                                                $this->customerSession,
                                                $this->checkoutSession,
                                                $this->checkoutFactory,
                                                $this->config,
                                                $this->catalogSession,
                                                $this->logger
                                            ]
                                        )->makePartial()->shouldAllowMockingProtectedMethods();
        
        $this->resetCart->objectManager = $this->objectManager;
    }

    function getProperty($object, $propertyName)
    {
        $reflection = new \ReflectionClass($object);

        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);

        return $property->getValue($object);
    }

    public function testExecuteSuccess()
    {
        $this->checkoutSession->shouldReceive('getLastQuoteId')->andReturn('000012');

        $response = $this->resetCart->execute();

        $expected_response = '{"success":true,"redirect_url":"checkout\/#payment"}';

        $this->assertSame($expected_response, $this->getProperty($response, 'json'));
    }

    public function testExecuteFailure()
    {
        $this->checkoutSession->shouldReceive('getLastQuoteId')->andReturn(null);
        
        $response = $this->resetCart->execute();

        $expected_response = '{"success":true,"redirect_url":"checkout\/cart"}';

        $this->assertSame($expected_response, $this->getProperty($response, 'json'));
    }
}
