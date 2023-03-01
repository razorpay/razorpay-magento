<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Magento\Framework\Controller\Result\Json;

class BaseControllerTest extends TestCase
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
        $this->config = \Mockery::mock(
            \Razorpay\Magento\Model\Config::class
        );
        $this->quoteModel = \Mockery::mock(
            \Magento\Quote\Model\Quote::class
        );
        $this->checkoutFactory = \Mockery::mock(
            \Razorpay\Magento\Model\CheckoutFactory::class
        );
        $this->checkoutModel = \Mockery::mock(
            \Razorpay\Magento\Model\Checkout::class
        );
        $this->response = \Mockery::mock(
            \Magento\Framework\App\ResponseInterface::class
        );
        

        $this->config->shouldReceive('getConfigData')->with('key_id')->andReturn('key_id');
        $this->config->shouldReceive('getConfigData')->with('key_secret')->andReturn('key_secret');
        
        $this->context->shouldReceive('getResponse')->andReturn($this->response);

        $this->response->shouldReceive('setStatusHeader');

        $this->checkoutSession->shouldReceive('getQuote')->andReturn($this->quoteModel);

        $this->checkoutFactory->shouldReceive('create')->andReturn($this->checkoutModel);

        $this->baseController = \Mockery::mock(Razorpay\Magento\Controller\BaseController::class, [ $this->context,
                                                                                                    $this->customerSession,
                                                                                                    $this->checkoutSession,
                                                                                                    $this->config])->makePartial()->shouldAllowMockingProtectedMethods();
        
    }
    function getProperty($object, $propertyName, $value)
    {
        $reflection = new \ReflectionClass($object);
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
        return $property->getValue($object);
    }
    public function testGetQuote()
    {
        $this->assertSame($this->quoteModel, $this->baseController->getQuote());
    }
    public function testGetCheckout()
    {
        $this->getProperty($this->baseController, 'checkoutFactory', $this->checkoutFactory);
        $this->assertSame($this->checkoutModel, $this->baseController->getCheckout());
    }
    public function testInitCheckout()
    {
        $this->quoteModel->shouldReceive('hasItems')->andReturn(true);
        $this->quoteModel->shouldReceive('getHasError')->andReturn(true);

        $this->expectException("\Magento\Framework\Exception\LocalizedException");
        $this->expectExceptionMessage("We can't initialize checkout.");

        $this->baseController->initCheckout();
        
    }
}