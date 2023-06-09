<?php

declare(strict_types=1);

if (class_exists('Razorpay\\Api\\Api')  === false)
{
    include_once __DIR__ . '/../../../Razorpay/src/Api.php';
}

if (class_exists('Razorpay\\Api\\Errors\\Error') === false)
{
    include_once __DIR__ . '/../../../Razorpay/src/Errors/Error.php';
}

use PHPUnit\Framework\TestCase;
use \Magento\Framework\Exception\MailException;
use Magento\Framework\Phrase;

class SetRzpPaymentDeetailsForOrderResolverTest extends TestCase 
{
    public function setUp():void
    {
        $this->paymentMethod = \Mockery::mock(
            \Razorpay\Magento\Model\PaymentMethod::class
        );

        $this->order = \Mockery::mock(
            \Magento\Sales\Api\Data\OrderInterface::class
        );

        $this->config = \Mockery::mock(
            \Razorpay\Magento\Model\Config::class
        );

        $this->invoiceService = \Mockery::mock(
            \Magento\Sales\Model\Service\InvoiceService::class
        );

        $this->transaction = \Mockery::mock(
            \Magento\Framework\DB\Transaction::class
        );

        $this->scopeConfig = \Mockery::mock(
            \Magento\Framework\App\Config\ScopeConfigInterface::class
        );

        $this->checkoutSession = \Mockery::mock(
            \Magento\Checkout\Model\Session::class
        );

        $this->invoiceSender = \Mockery::mock(
            \Magento\Sales\Model\Order\Email\Sender\InvoiceSender::class
        );

        $this->orderSender = \Mockery::mock(
            \Magento\Sales\Model\Order\Email\Sender\OrderSender::class
        );

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

        $this->orderModel = \Mockery::mock(
            \Magento\Sales\Model\Order::class
        );

        $this->utilityApi = \Mockery::mock(
            Razorpay\Api\Utility::class
        );

        $this->orderApi = \Mockery::mock(
            Razorpay\Api\Order::class
        );

        $this->apiError = \Mockery::mock(
            Razorpay\Api\Errors\Error::class, ['Test Api error message', 0, 0]
        );

        $this->paymentModel = \Mockery::mock(
            \Magento\Sales\Model\Order\Payment::class
        );

        $this->captureCommand = \Mockery::mock(
            \Magento\Sales\Model\Order\Payment\State\CaptureCommand::class
        );

        $this->authorizeCommand = \Mockery::mock(
            \Magento\Sales\Model\Order\Payment\State\AuthorizeCommand::class
        );

        $this->transactionModel = \Mockery::mock(
            \Magento\Sales\Model\Order\Payment\Transaction::class
        );

        $this->invoice = \Mockery::mock(
            \Magento\Sales\Model\Order\Invoice::class
        );

        $this->abstractModel = \Mockery::mock(
            Magento\Framework\Model\AbstractModel::class
        );

        $this->orderID  = '000012';
        $this->razorpay_payment_id = 'pay_LJ7q34bS5acDu5';
        $this->razorpay_signature = '032e145d2f885ce5452aca638e338e4ee840e8dfb857055da53596d9592b89b6';

        $this->api = $this->getMockBuilder(Razorpay\Api\Api::class)
                     ->disableOriginalConstructor()
                     ->disableOriginalClone()
                     ->disableArgumentCloning()
                     ->disallowMockingUnknownTypes()
                     ->getMock();

        $this->paymentMethod->rzp = $this->api;

        $this->utilityApi->shouldReceive('verifyPaymentSignature');

        $this->config->shouldReceive('isCustomPaidOrderStatusEnabled')->andReturn(true);
        $this->config->shouldReceive('getCustomPaidOrderStatus')->andReturn('custom_processing');
        $this->config->shouldReceive('canAutoGenerateInvoice')->andReturn(true);

        $this->scopeConfig->shouldReceive('getValue')->andReturn('Authorized');
        
        $this->orderModel->shouldReceive('getStatus')->andReturn('pending');
        $this->orderModel->shouldReceive('setState')->andReturn($this->orderModel);
        $this->orderModel->shouldReceive('setStatus')->andReturn($this->orderModel);
        $this->orderModel->shouldReceive('getPayment')->andReturn($this->paymentModel);
        $this->orderModel->shouldReceive('getGrandTotal')->andReturn(10000);
        $this->orderModel->shouldReceive('canInvoice')->andReturn(true);
        $this->orderModel->shouldReceive('addStatusHistoryComment')->andReturn($this->orderModel);
        $this->orderModel->shouldReceive('setIsCustomerNotified')
                         ->with(true)
                         ->andReturn($this->orderModel);
        $this->orderModel->shouldReceive('save');
        
        $this->paymentModel->shouldReceive('setLastTransId')
                           ->andReturn($this->paymentModel);
        $this->paymentModel->shouldReceive('setTransactionId')
                           ->andReturn($this->paymentModel);
        $this->paymentModel->shouldReceive('setIsTransactionClosed')
                           ->andReturn($this->paymentModel);
        $this->paymentModel->shouldReceive('setShouldCloseParentTransaction')
                           ->andReturn($this->paymentModel);
        $this->paymentModel->shouldReceive('getTransactionId')
                           ->andReturn($this->razorpay_payment_id);
        $this->paymentModel->shouldReceive('setParentTransactionId')
                           ->with($this->paymentModel->getTransactionId())
                           ->andReturn($this->paymentModel);
        $this->paymentModel->shouldReceive('addTransactionCommentsToOrder');

        $this->paymentModel->shouldReceive('addTransaction')
        ->with(\Magento\Sales\Model\Order\Payment\Transaction::TYPE_AUTH, null, true, "")
        ->andReturn($this->transactionModel);

        $this->captureCommand->shouldReceive('execute');

        $this->authorizeCommand->shouldReceive('execute');

        $this->transactionModel->shouldReceive('setIsClosed')
                               ->with(true)
                               ->andReturn($this->transactionModel);
        $this->transactionModel->shouldReceive('save')
                               ->andReturn($this->transactionModel);

        $this->invoiceService->shouldReceive('prepareInvoice')
                             ->with($this->orderModel)
                             ->andReturn($this->invoice);

        $this->invoice->shouldReceive('setRequestedCaptureCase')
                      ->with(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE)
                      ->andReturn($this->invoice);
        $this->invoice->shouldReceive('setTransactionId')
                      ->andReturn($this->invoice);
        $this->invoice->shouldReceive('register')
                      ->andReturn($this->invoice);
        $this->invoice->shouldReceive('save')
                      ->andReturn($this->invoice);
        $this->invoice->shouldReceive('getOrder')
                      ->andReturn($this->abstractModel);
        $this->invoice->shouldReceive('getId')
                      ->andReturn(1);

        $this->transaction->shouldReceive('addObject')
                          ->with($this->invoice)
                          ->andReturn($this->transaction);
        $this->transaction->shouldReceive('addObject')
                          ->with($this->invoice->getOrder())
                          ->andReturn($this->transaction);
        $this->transaction->shouldReceive('save')
                          ->andReturn($this->transaction);
        
        $this->checkoutSession->shouldReceive('setRazorpayMailSentOnSuccess')
                              ->andReturn($this->checkoutSession);
        $this->checkoutSession->shouldReceive('unsRazorpayMailSentOnSuccess')
                              ->andReturn($this->checkoutSession);
        
        $this->invoiceSender->shouldReceive('send');

        $this->api->method('__get')->willReturnCallback(function ($propertyName)
        {
            switch($propertyName)
            {
                case 'utility':
                    return $this->utilityApi;
                case 'order':
                    return $this->orderApi;
            }
        });

        $this->setRzpPaymentDetailsForOrder = \Mockery::mock(Razorpay\Magento\Model\Resolver\SetRzpPaymentDetailsForOrder::class, 
                                        [
                                            $this->paymentMethod, 
                                            $this->order,
                                            $this->config,
                                            $this->invoiceService,
                                            $this->transaction,
                                            $this->scopeConfig,
                                            $this->checkoutSession,
                                            $this->invoiceSender,
                                            $this->orderSender,
                                            $this->logger
                                        ]
                                     )->makePartial()
                                      ->shouldAllowMockingProtectedMethods();

        $this->setRzpPaymentDetailsForOrder->captureCommand = $this->captureCommand;
        $this->setRzpPaymentDetailsForOrder->authorizeCommand = $this->authorizeCommand;
    }

    public function testResolveEmptyOrderID()
    {
        $args = [
            'input' => [
                'rzp_payment_id'    => $this->razorpay_payment_id,
                'rzp_signature'     => $this->razorpay_signature
            ]
        ];

        $this->expectException('Magento\Framework\GraphQl\Exception\GraphQlInputException');
        $this->expectExceptionMessage('Required parameter "order_id" is missing');

        $this->setRzpPaymentDetailsForOrder->resolve($this->field, $this->context, $this->info, null, $args);
    }

    public function testResolveEmptyRzpPaymentID()
    {
        $args = [
            'input' => [
                'order_id'          => $this->orderID,
                'rzp_signature'     => $this->razorpay_signature
            ]
        ];

        $this->expectException('Magento\Framework\GraphQl\Exception\GraphQlInputException');
        $this->expectExceptionMessage('Required parameter "rzp_payment_id" is missing');

        $this->setRzpPaymentDetailsForOrder->resolve($this->field, $this->context, $this->info, null, $args);
    }

    public function testResolveEmptyRzpSignature()
    {
        $args = [
            'input' => [
                'order_id'          => $this->orderID,
                'rzp_payment_id'    => $this->razorpay_payment_id
            ]
        ];

        $this->expectException('Magento\Framework\GraphQl\Exception\GraphQlInputException');
        $this->expectExceptionMessage('Required parameter "rzp_signature" is missing');

        $this->setRzpPaymentDetailsForOrder->resolve($this->field, $this->context, $this->info, null, $args);
    }

    public function testResolveRzorpayOrderNotFound()
    {
        $this->order->shouldReceive('load')->andReturn($this->orderModel);

        $this->orderModel->shouldReceive('getRzpOrderId')->andReturn(null);

        $args = [
            'input' => [
                'order_id'          => $this->orderID,
                'rzp_payment_id'    => $this->razorpay_payment_id,
                'rzp_signature'     => $this->razorpay_signature
            ]
        ];

        $this->expectException('Magento\Framework\GraphQl\Exception\GraphQlInputException');
        $this->expectExceptionMessage('Error: Error: Unable to Razorpay Order ID for Order ID:000012..');

        $this->setRzpPaymentDetailsForOrder->resolve($this->field, $this->context, $this->info, null, $args);        
    }

    public function testResolveRzorpayOrderFoundWithDifferentReceiptID()
    {
        $this->order->shouldReceive('load')->andReturn($this->orderModel);

        $this->orderModel->shouldReceive('getRzpOrderId')->andReturn('order_LJ7pjEUXgeOhhj');

        $this->orderApi->shouldReceive('fetch')->andReturn((object)['receipt' => '000011']);
        
        $args = [
            'input' => [
                'order_id'          => $this->orderID,
                'rzp_payment_id'    => $this->razorpay_payment_id,
                'rzp_signature'     => $this->razorpay_signature
            ]
        ];

        $this->expectException('Magento\Framework\GraphQl\Exception\GraphQlInputException');
        $this->expectExceptionMessage('Error: Not a valid Razorpay orderID.');

        $this->setRzpPaymentDetailsForOrder->resolve($this->field, $this->context, $this->info, null, $args);        
    }

    public function testResolveRzorpayOrderFoundApiException()
    {
        $this->order->shouldReceive('load')->andReturn($this->orderModel);

        $this->orderModel->shouldReceive('getRzpOrderId')->andReturn('order_LJ7pjEUXgeOhhj');

        $this->orderApi->shouldReceive('fetch')->andThrow($this->apiError);
        
        $args = [
            'input' => [
                'order_id'          => $this->orderID,
                'rzp_payment_id'    => $this->razorpay_payment_id,
                'rzp_signature'     => $this->razorpay_signature
            ]
        ];

        $this->expectException('Magento\Framework\GraphQl\Exception\GraphQlInputException');
        $this->expectExceptionMessage('Razorpay Error: Test Api error message.');

        $this->setRzpPaymentDetailsForOrder->resolve($this->field, $this->context, $this->info, null, $args);        
    }

    public function testResolveRzorpayOrderFoundWithSameReceiptIDAuthorizeCapture()
    {
        $expected = [
            'order' => [
                'order_id'  => '000012'
            ]
        ];

        $this->order->shouldReceive('load')->andReturn($this->orderModel);

        $this->orderModel->shouldReceive('getRzpOrderId')->andReturn('order_LJ7pjEUXgeOhhj');

        $this->orderApi->shouldReceive('fetch')->andReturn((object)['receipt' => '000012',
                                                                    'amount'  => 10000,
                                                                    'status'  => 'paid']);
        
        $this->config->shouldReceive('getPaymentAction') ->andReturn('authorize_capture');
        
        $this->orderSender->shouldReceive('send');

        $args = [
            'input' => [
                'order_id'          => $this->orderID,
                'rzp_payment_id'    => $this->razorpay_payment_id,
                'rzp_signature'     => $this->razorpay_signature
            ]
        ];

        $response = $this->setRzpPaymentDetailsForOrder->resolve($this->field, $this->context, $this->info, null, $args);
        
        $this->assertSame($expected, $response);
    }

    public function testResolveRzorpayOrderFoundWithSameReceiptIDAuthorize()
    {
        $expected = [
            'order' => [
                'order_id'  => '000012'
            ]
        ];

        $this->order->shouldReceive('load')->andReturn($this->orderModel);

        $this->orderModel->shouldReceive('getRzpOrderId')->andReturn('order_LJ7pjEUXgeOhhj');

        $this->orderApi->shouldReceive('fetch')->andReturn((object)['receipt' => '000012',
                                                                    'amount'  => 10000,
                                                                    'status'  => 'paid']);
        
        $this->config->shouldReceive('getPaymentAction') ->andReturn('authorize');
        
        $this->orderSender->shouldReceive('send');

        $args = [
            'input' => [
                'order_id'          => $this->orderID,
                'rzp_payment_id'    => $this->razorpay_payment_id,
                'rzp_signature'     => $this->razorpay_signature
            ]
        ];

        $response = $this->setRzpPaymentDetailsForOrder->resolve($this->field, $this->context, $this->info, null, $args);
        
        $this->assertSame($expected, $response);
    }

    public function testResolveRzorpayOrderFoundWithSameReceiptIDAuthorizeException()
    {
        $this->order->shouldReceive('load')->andReturn($this->orderModel);

        $this->orderModel->shouldReceive('getRzpOrderId')->andReturn('order_LJ7pjEUXgeOhhj');

        $this->orderApi->shouldReceive('fetch')->andReturn((object)['receipt' => '000012',
                                                                    'amount'  => 10000,
                                                                    'status'  => 'paid']);
        
        $this->config->shouldReceive('getPaymentAction') ->andReturn('authorize');
        
        $this->orderSender->shouldReceive('send')->andThrow(new Exception("Test exception message"));

        $args = [
            'input' => [
                'order_id'          => $this->orderID,
                'rzp_payment_id'    => $this->razorpay_payment_id,
                'rzp_signature'     => $this->razorpay_signature
            ]
        ];

        $this->expectException('Magento\Framework\GraphQl\Exception\GraphQlInputException');
        $this->expectExceptionMessage('Error: Test exception message.');

        $this->setRzpPaymentDetailsForOrder->resolve($this->field, $this->context, $this->info, null, $args);
    }

    public function testResolveRzorpayOrderFoundWithSameReceiptIDAuthorizeMailException()
    {
        $this->order->shouldReceive('load')->andReturn($this->orderModel);

        $this->orderModel->shouldReceive('getRzpOrderId')->andReturn('order_LJ7pjEUXgeOhhj');

        $this->orderApi->shouldReceive('fetch')->andReturn((object)['receipt' => '000012',
                                                                    'amount'  => 10000,
                                                                    'status'  => 'paid']);
        
        $this->config->shouldReceive('getPaymentAction') ->andReturn('authorize');
        
        $exceptionPhrase = new Phrase('Test Mail Exception');
        $mailException = new MailException($exceptionPhrase);
        $this->orderSender->shouldReceive('send')->andThrow($mailException);

        $args = [
            'input' => [
                'order_id'          => $this->orderID,
                'rzp_payment_id'    => $this->razorpay_payment_id,
                'rzp_signature'     => $this->razorpay_signature
            ]
        ];

        $this->expectException('Magento\Framework\GraphQl\Exception\GraphQlInputException');
        $this->expectExceptionMessage('Razorpay Error: Test Mail Exception.');

        $this->setRzpPaymentDetailsForOrder->resolve($this->field, $this->context, $this->info, null, $args);
    }
}
