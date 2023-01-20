<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Razorpay\Magento\Controller\Payment\Order;

class OrderControllerTest extends TestCase {
    public function setUp():void
    {
        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->order = $objectManager->getObject('Razorpay\Magento\Controller\Payment\Order');
    }
    public function testGeneratePasswordEmpty()
    {
        $password = $this->order->generatePassword();
        $this->assertNotEmpty($password);
    }
}