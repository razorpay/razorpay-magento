<?php

namespace Razorpay\Magento\Test\Unit\Controller;

use Razorpay\Magento\Model\Config;
use Razorpay\Magento\Controller\BaseController;
use Magento\Framework\App\RequestInterface;
use \Magento\Checkout\Model\Session;
use PHPUnit\Framework\TestCase;


/**
 * Razorpay Base Controller
 */
class BaseControllerTest extends TestCase
{
	protected $qoute;

    public function setUp()
    {
    	$this->configMock = $this->getMockBuilder(Config::class)
            ->disableOriginalConstructor()
            ->getMock();

    	$this->checkoutSessionMock = $this->getMockBuilder(Session::class)
            ->disableOriginalConstructor()
            ->setMethods(array('getQuote'))  
            ->getMock();

        $this->baseControllerMock = $this->getMockBuilder(BaseController::class)
            ->disableOriginalConstructor()
            ->setMethods(array('getQuote'))
            ->getMockForAbstractClass();
    }

    /**
     * Return checkout quote object
     *
     * @return \Magento\Quote\Model\Quote
     */
    public function testGetQuote()
    {
    	$quote = '000000025';
        $this->checkoutSessionMock->method('getQuote')
             					  ->willReturn($quote);

    	$this->assertEquals($quote, $this->checkoutSessionMock->getQuote());     

    }
}