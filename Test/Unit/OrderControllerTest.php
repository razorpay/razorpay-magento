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
        $this->order2 = \Mockery::mock(Razorpay\Magento\Controller\Payment\Order::class)->makePartial();
        //print_r($this->order2);
        $this->password = $this->order2->generatePassword();
        //var_dump($this->order2->api);
    }
    public function testGeneratePasswordEmpty()
    {
        
        $this->assertNotEmpty($this->password);
    }
    public function testGeneratePasswordLength()
    {
        
        $this->assertGreaterThanOrEqual(12, strlen($this->password));
        $this->assertLessThanOrEqual(16, strlen($this->password));
    }
}