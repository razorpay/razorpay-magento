<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Razorpay\Magento\Controller\Payment\Order;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\UrlInterface;

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
    }

    public function testMockApiMethod() 
    {
        $this->api->method('getAppsDetails')->willReturn('foo');
        $this->assertSame('foo', $this->api->getAppsDetails());
    }

    public function testWebhook()
    {
        $this->api->expects($this->once())
                   ->method('__get')
                   ->with($this->equalTo('webhook'))
                   ->will($this->returnValue(['snickers']));

        $response = $this->api->webhook;
        $this->assertEquals(['snickers'], $response);
    }

    public function testWebhookAll()
    {
        $this->api->expects($this->once())
                   ->method('__get')
                   ->with($this->equalTo('webhook_all'))
                   ->will($this->returnValue('snickers'));

        $response = $this->api->webhook_all;
        $this->assertEquals('snickers', $response);
    }

    public function testGeneratePasswordEmpty()
    {
        $this->password = $this->order->generatePassword();

        $this->assertNotEmpty($this->password);
    }

    public function testGeneratePasswordLength()
    {
        $this->password = $this->order->generatePassword();

        $this->assertGreaterThanOrEqual(12, strlen($this->password));
        $this->assertLessThanOrEqual(16, strlen($this->password));
    }

    public function testWebhooksIsArray() {
        $this->order->setMockInit('rzp_key', 'rzp_secret');
        $this->order->getWebhooks();

        $this->assertIsArray($this->order->webhooks->items);
    }
}