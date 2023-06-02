<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class PaymentActionModelTest extends TestCase 
{
    public function setup():void
    {
        $this->paymentAction = \Mockery::mock(
            \Razorpay\Magento\Model\PaymentAction::class
        )->makePartial()
        ->shouldAllowMockingProtectedMethods();;
    }

    function getProperty($object, $propertyName)
    {
        $reflection = new \ReflectionClass($object);

        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);

        return $property->getValue($object);
    }

    public function testToOptionArray()
    {
        $response = $this->paymentAction->toOptionArray();

        $this->assertSame('authorize', $response[0]['value']);
        $this->assertSame('Authorize Only', $this->getProperty($response[0]['label'], 'text'));

        $this->assertSame('authorize_capture', $response[1]['value']);
        $this->assertSame('Authorize and Capture', $this->getProperty($response[1]['label'], 'text'));
    }
}
