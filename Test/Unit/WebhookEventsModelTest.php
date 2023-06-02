<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class WebhookEventsModelTest extends TestCase 
{
    public function setup():void
    {
        $this->webhookEvents = \Mockery::mock(
            \Razorpay\Magento\Model\WebhookEvents::class
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
        $response = $this->webhookEvents->toOptionArray();

        $this->assertSame('order.paid', $response[0]['value']);
        $this->assertSame('order.paid', $this->getProperty($response[0]['label'], 'text'));
        
        $this->assertSame('payment.authorized', $response[1]['value']);
        $this->assertSame('payment.authorized', $this->getProperty($response[1]['label'], 'text'));
    }
}
