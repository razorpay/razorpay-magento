<?php

/**
 * Copyright ©Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Razorpay\Magento\Test\Unit\Model;

use Razorpay\Api\Api;
use Magento\Framework\DataObject;
use Razorpay\Magento\Model\Config;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Data\OrderAdapterInterface;
use Razorpay\Magento\Model\PaymentMethod;
use PHPUnit\Framework\TestCase;


class PaymentMethodTest extends TestCase
{

	/**
     * @var ConfigInterface|MockObject
     */
    protected $configMock;

	protected function setUp()
    {
        $this->configMock = $this->getMockBuilder(Config::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->paymentMethod = $this->getMockBuilder(PaymentMethod::class)
            ->disableOriginalConstructor()
            ->setMethods(array('getPostData','validateSignature', 'updatePaymentNote'))  
            ->getMock();
    }


    public function testAuthorize()
    {

        $paymentMock = $this->getPaymentMock();
        $orderMock = $this->getOrderMock();

        $paymentMock->method('getOrder')
            ->willReturn($orderMock);

		$response = $this->getGatewayResponseObject();
        $amount = 23.03;       

        $this->paymentMethod->method('getPostData')
             ->willReturn($response);

        $this->paymentMethod->authorize($paymentMock, $amount);

        $this->assertEquals($response['paymentMethod']['additional_data']['rzp_payment_id'], $paymentMock->getTransactionId());        
    }


    /**
     * Create mock object for payment model
     * @return \PHPUnit_Framework_MockObject_MockObject
     */
    protected function getPaymentMock()
    {
        $paymentMock = $this->getMockBuilder(\Magento\Payment\Model\Info::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'getParentTransactionId', 'getOrder',
            ])
            ->getMock();        
        return $paymentMock;
    }

    /**
     * Create mock object for order model
     * @return \PHPUnit_Framework_MockObject_MockObject
     */
    protected function getOrderMock()
    {
        $orderMock = $this->getMockBuilder(\Magento\Sales\Model\Order::class)
            ->disableOriginalConstructor()
            ->setMethods(['getIncrementId'])
            ->getMock();

        return $orderMock;
    }

    /**
     * Create response object for Payflowpro gateway
     * @return \Magento\Framework\DataObject
     */
    protected function getGatewayResponseObject()
    {
    	return new \Magento\Framework\DataObject(
            [
                'paymentMethod' => [
                	'additional_data' => [
                		'order_id' => '26',
                		'rzp_payment_id' => 'pay_DKRoeE3JlUS0xo',
                		'rzp_order_id' => 'order_DKRoZagXtDyccc',
                		'rzp_signature' => 'd81a580f713ed45094f4a9b14e9558311715efbb224a7691e02169daf499ca4f'
                	]
                ]
            ]
        );
    }

}


