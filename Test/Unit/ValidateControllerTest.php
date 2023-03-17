<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\UrlInterface;
use Razorpay\Magento\Test\MockFactory\MockApi;
use Razorpay\Api\Api;
use Magento\Framework\Controller\Result\Json;
use Psr\Log\Test\TestLogger;

/**
 * @covers Razorpay\Magento\Controller\Payment\Validate
 */

class ValidateControllerTest extends TestCase {
    public function setUp():void
    {
        $this->context = \Mockery::mock(
            \Magento\Framework\App\Action\Context::class
        )->makePartial()
         ->shouldAllowMockingProtectedMethods();

        $this->customerSession = $this->createMock(
            \Magento\Customer\Model\Session::class
        );

        $this->checkoutSession = \Mockery::mock(
            \Magento\Checkout\Model\Session::class
        );

        $this->config = \Mockery::mock(
            \Razorpay\Magento\Model\Config::class
        );

        $this->catalogSession = \Mockery::mock(
            \Magento\Catalog\Model\Session::class
        );

        $this->order = $this->createMock(
            \Magento\Sales\Api\Data\OrderInterface::class
        );

        $this->invoiceService = \Mockery::mock(
            \Magento\Sales\Model\Service\InvoiceService::class
        );

        $this->transaction = \Mockery::mock(
            \Magento\Framework\DB\Transaction::class
        );

        $this->invoiceSender = $this->createMock(
            \Magento\Sales\Model\Order\Email\Sender\InvoiceSender::class
        );

        $this->orderRepository = $this->createMock(
            Magento\Sales\Api\OrderRepositoryInterface::class
        );

        $this->orderSender = $this->createMock(
            \Magento\Sales\Model\Order\Email\Sender\OrderSender::class
        );

        $this->logger = $this->createMock(
            \Psr\Log\LoggerInterface::class
        );

        $this->objectManager = \Mockery::mock(
            \Magento\Framework\ObjectManagerInterface::class
        );

        $this->quoteModel = \Mockery::mock(
            Magento\Quote\Model\Quote::class
        );

        $this->utilityApi = \Mockery::mock(
            Razorpay\Api\Utility::class
        );

        $this->orderModel = \Mockery::mock(
            \Magento\Sales\Model\Order::class
        );

        $this->paymentModel = \Mockery::mock(
            \Magento\Sales\Model\Order\Payment::class
        );

        $this->transactionModel = \Mockery::mock(
            \Magento\Sales\Model\Order\Payment\Transaction::class
        );

        $this->invoice = \Mockery::mock(
            \Magento\Sales\Model\Order\Invoice::class
        );

        $this->captureCommand = \Mockery::mock(
            \Magento\Sales\Model\Order\Payment\State\CaptureCommand::class
        );

        $this->authorizeCommand = \Mockery::mock(
            \Magento\Sales\Model\Order\Payment\State\AuthorizeCommand::class
        );

        $this->abstractModel = \Mockery::mock(
            Magento\Framework\Model\AbstractModel::class
        );

        $this->resultFactory = \Mockery::mock(
            Magento\Framework\Controller\ResultFactory::class
        );

        $this->serializerJson = new \Magento\Framework\Serialize\Serializer\Json;

        $this->translateInline = \Mockery::mock(
            \Magento\Framework\Translate\InlineInterface::class
        );

        $this->apiError = \Mockery::mock(
            \Razorpay\Api\Errors\Error::class,['Test Api error message',0,0]
        );

        $this->exceptionError = \Mockery::mock(
            \Exception::class,['Test Exception error message']
        );

        $this->emailExceptionError = \Mockery::mock(
            \Exception::class,['Email Exception error message']
        );

        $this->json = new Json($this->translateInline,
                               $this->serializerJson);

        $this->utilityApi->shouldReceive('verifyPaymentSignature')
                         ->andReturn(true);

        $this->post = [
            'razorpay_payment_id' => 'pay_LJ7q34bS5acDu5',
            'razorpay_signature'  => '032e145d2f885ce5452aca638e338e4ee840e8dfb857055da53596d9592b89b6',
            'razorpay_order_id'   => 'order_LJ7pjEUXgeOhhj'
        ];

        $this->config->shouldReceive('getConfigData')
                     ->with('key_id')->andReturn('key_id');
        $this->config->shouldReceive('getConfigData')
                     ->with('key_secret')
                     ->andReturn('key_secret');
        $this->config->shouldReceive('getKeyId')
                     ->andReturn('key_id');
        $this->config->shouldReceive('isCustomPaidOrderStatusEnabled')
                     ->andReturn(true);
        $this->config->shouldReceive('getCustomPaidOrderStatus')
                     ->andReturn(true);
        $this->config->shouldReceive('canAutoGenerateInvoice')
                     ->andReturn(true);

        $this->context->shouldReceive('getResultFactory')
                      ->andReturn($this->resultFactory);

        $this->paymentModel->shouldReceive('setLastTransId')
                           ->with($this->post['razorpay_payment_id'])
                           ->andReturn($this->paymentModel);
        $this->paymentModel->shouldReceive('setTransactionId')
                           ->with($this->post['razorpay_payment_id'])
                           ->andReturn($this->paymentModel);
        $this->paymentModel->shouldReceive('setIsTransactionClosed')
                           ->with(true)
                           ->andReturn($this->paymentModel);
        $this->paymentModel->shouldReceive('setShouldCloseParentTransaction')
                           ->with(true)
                           ->andReturn($this->paymentModel);
        $this->paymentModel->shouldReceive('getTransactionId')
                           ->andReturn($this->post['razorpay_payment_id']);
        $this->paymentModel->shouldReceive('setParentTransactionId')
                           ->with($this->paymentModel->getTransactionId())
                           ->andReturn($this->paymentModel);
        $this->paymentModel->shouldReceive('addTransactionCommentsToOrder');

        $this->paymentModel->shouldReceive('addTransaction')
                           ->with(\Magento\Sales\Model\Order\Payment\Transaction::TYPE_AUTH, null, true, "")
                           ->andReturn($this->transactionModel);

        $this->transactionModel->shouldReceive('setIsClosed')
                               ->with(true)
                               ->andReturn($this->transactionModel);
        $this->transactionModel->shouldReceive('save')
                               ->andReturn($this->transactionModel);

        $this->orderModel->shouldReceive('getIncrementId')
                         ->andReturn('000000001');
        $this->orderModel->shouldReceive('getQuoteId')
                         ->andReturn('1');
        $this->orderModel->shouldReceive('canInvoice')
                         ->andReturn(true);
        $this->orderModel->shouldReceive('setState')
                         ->with('processing')
                         ->andReturn($this->orderModel);
        $this->orderModel->shouldReceive('setStatus')
                         ->with('processing')
                         ->andReturn($this->orderModel);
        $this->orderModel->shouldReceive('getPayment')
                         ->andReturn($this->paymentModel);
        $this->orderModel->shouldReceive('save')
                         ->andReturn($this->orderModel);
        $this->orderModel->shouldReceive('getGrandTotal')
                         ->andReturn(10000);
        $this->orderModel->shouldReceive('addStatusHistoryComment')
                         ->andReturn($this->orderModel);
        $this->orderModel->shouldReceive('setIsCustomerNotified')
                         ->with(true)
                         ->andReturn($this->orderModel);

        $this->checkoutSession->shouldReceive('getLastRealOrder')
                              ->andReturn($this->orderModel);
        $this->checkoutSession->shouldReceive('setFirstTimeChk')
                              ->andReturn('0');
        $this->checkoutSession->shouldReceive('setRazorpayMailSentOnSuccess')
                              ->andReturn($this->checkoutSession);
        $this->checkoutSession->shouldReceive('unsRazorpayMailSentOnSuccess')
                              ->andReturn($this->checkoutSession);

        $this->catalogSession->shouldReceive('getRazorpayOrderID')
                             ->andReturn('order_LJ7pjEUXgeOhhj');

        $this->quoteModel->shouldReceive('load')
                         ->with($this->orderModel->getQuoteId())
                         ->andReturn($this->quoteModel);
        $this->quoteModel->shouldReceive('setIsActive')
                         ->with(false)
                         ->andReturn($this->quoteModel);
        $this->quoteModel->shouldReceive('setIsActive')
                         ->with(true)
                         ->andReturn($this->quoteModel);
        $this->quoteModel->shouldReceive('save')
                         ->andReturn($this->quoteModel);

        $this->objectManager->shouldReceive('get')
                            ->with('Magento\Quote\Model\Quote')
                            ->andReturn($this->quoteModel);

        $this->invoiceService->shouldReceive('prepareInvoice')
                             ->with($this->orderModel)
                             ->andReturn($this->invoice);

        $this->invoice->shouldReceive('setRequestedCaptureCase')
                      ->with(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE)
                      ->andReturn($this->invoice);
        $this->invoice->shouldReceive('setTransactionId')
                      ->with($this->post['razorpay_payment_id'])
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

        $this->resultFactory->shouldReceive('create')
                            ->with('json')
                            ->andReturn($this->json);
    }

    function getProperty($object, $propertyName)
    {
        $reflection = new \ReflectionClass($object);

        $property = $reflection->getProperty($propertyName);

        $property->setAccessible(true);

        return $property->getValue($object);
    }

    function testValidateAutorize()
    {
        $this->config->shouldReceive('getPaymentAction')
                     ->andReturn('authorize');

        $this->validate = \Mockery::mock(Razorpay\Magento\Controller\Payment\Validate::class,
                                        [
                                            $this->context,
                                            $this->customerSession,
                                            $this->checkoutSession,
                                            $this->config,
                                            $this->catalogSession,
                                            $this->order,
                                            $this->invoiceService,
                                            $this->transaction,
                                            $this->invoiceSender,
                                            $this->orderRepository,
                                            $this->orderSender,
                                            $this->logger
                                        ])
                                        ->makePartial()
                                        ->shouldAllowMockingProtectedMethods();

        $this->api = $this->getMockBuilder(Razorpay\Api\Api::class)
                          ->disableOriginalConstructor()
                          ->disableOriginalClone()
                          ->disableArgumentCloning()
                          ->disallowMockingUnknownTypes()
                          ->getMock();

        $this->api->method('__get')->willReturnCallback(function ($propertyName)
        {
            switch($propertyName)
            {
                case 'utility':
                    return $this->utilityApi;
            }
        });

        $this->validate->rzp = $this->api;

        $this->validate->setMockInit($this->objectManager);

        $this->validate->shouldReceive('getPostData')
                       ->andReturn($this->post);

        $this->validate->authorizeCommand = $this->authorizeCommand;

        $this->authorizeCommand->shouldReceive('execute');

        $this->response = $this->validate->execute();

        $expectedResponse = '{"success":true,"redirect_url":"checkout\/onepage\/success\/","order_id":"000000001"}';

        $this->assertSame($expectedResponse, $this->getProperty($this->response, 'json'));
    }

    function testValidateAutorizeCapture()
    {
        $this->config->shouldReceive('getPaymentAction')
                     ->andReturn('authorize_capture');

        $this->validate = \Mockery::mock(Razorpay\Magento\Controller\Payment\Validate::class,
                                        [
                                            $this->context,
                                            $this->customerSession,
                                            $this->checkoutSession,
                                            $this->config,
                                            $this->catalogSession,
                                            $this->order,
                                            $this->invoiceService,
                                            $this->transaction,
                                            $this->invoiceSender,
                                            $this->orderRepository,
                                            $this->orderSender,
                                            $this->logger
                                        ])
                                        ->makePartial()
                                        ->shouldAllowMockingProtectedMethods();

        $this->api = $this->getMockBuilder(Razorpay\Api\Api::class)
                          ->disableOriginalConstructor()
                          ->disableOriginalClone()
                          ->disableArgumentCloning()
                          ->disallowMockingUnknownTypes()
                          ->getMock();

        $this->api->method('__get')->willReturnCallback(function ($propertyName)
        {
            switch($propertyName)
            {
                case 'utility':
                    return $this->utilityApi;
            }
        });

        $this->validate->rzp = $this->api;

        $this->validate->setMockInit($this->objectManager);

        $this->validate->shouldReceive('getPostData')
                       ->andReturn($this->post);

        $this->validate->captureCommand = $this->captureCommand;

        $this->captureCommand->shouldReceive('execute');

        $this->response = $this->validate->execute();

        $expectedResponse = '{"success":true,"redirect_url":"checkout\/onepage\/success\/","order_id":"000000001"}';

        $this->assertSame($expectedResponse, $this->getProperty($this->response, 'json'));
    }

    function testValidateApiExceptionError()
    {
        $this->config->shouldReceive('getPaymentAction')
                     ->andReturn('authorize');

        $this->validate = \Mockery::mock(Razorpay\Magento\Controller\Payment\Validate::class,
                                        [
                                            $this->context,
                                            $this->customerSession,
                                            $this->checkoutSession,
                                            $this->config,
                                            $this->catalogSession,
                                            $this->order,
                                            $this->invoiceService,
                                            $this->transaction,
                                            $this->invoiceSender,
                                            $this->orderRepository,
                                            $this->orderSender,
                                            $this->logger
                                        ])
                                        ->makePartial()
                                        ->shouldAllowMockingProtectedMethods();

        $this->api = $this->getMockBuilder(Razorpay\Api\Api::class)
                          ->disableOriginalConstructor()
                          ->disableOriginalClone()
                          ->disableArgumentCloning()
                          ->disallowMockingUnknownTypes()
                          ->getMock();

        $this->api->method('__get')->willReturnCallback(function ($propertyName)
        {
            switch($propertyName)
            {
                case 'utility':
                    return $this->utilityApi;
            }
        });

        $this->post = [];

        $this->validate->rzp = $this->api;

        $this->validate->setMockInit($this->objectManager);
        $this->validate->shouldReceive('getPostData')
                       ->andReturn($this->post);
        $this->validate->shouldReceive('validateSignature')
                       ->andThrow($this->apiError);

        $this->validate->authorizeCommand = $this->authorizeCommand;

        $this->authorizeCommand->shouldReceive('execute');

        $this->response = $this->validate->execute();

        $expectedResponse = '{"success":false,"redirect_url":"checkout\/#payment","parameters":[],"message":"Test Api error message"}';

        $this->assertSame($expectedResponse, $this->getProperty($this->response, 'json'));
    }

    function testValidateExceptionError()
    {
        $this->config->shouldReceive('getPaymentAction')
                     ->andReturn('authorize');

        $this->validate = \Mockery::mock(Razorpay\Magento\Controller\Payment\Validate::class,
                                        [
                                            $this->context,
                                            $this->customerSession,
                                            $this->checkoutSession,
                                            $this->config,
                                            $this->catalogSession,
                                            $this->order,
                                            $this->invoiceService,
                                            $this->transaction,
                                            $this->invoiceSender,
                                            $this->orderRepository,
                                            $this->orderSender,
                                            $this->logger
                                        ])
                                        ->makePartial()
                                        ->shouldAllowMockingProtectedMethods();

        $this->api = $this->getMockBuilder(Razorpay\Api\Api::class)
                          ->disableOriginalConstructor()
                          ->disableOriginalClone()
                          ->disableArgumentCloning()
                          ->disallowMockingUnknownTypes()
                          ->getMock();

        $this->api->method('__get')->willReturnCallback(function ($propertyName)
        {
            switch($propertyName)
            {
                case 'utility':
                    return $this->utilityApi;
            }
        });

        $this->post = [];

        $this->validate->rzp = $this->api;

        $this->validate->setMockInit($this->objectManager);
        $this->validate->shouldReceive('getPostData')
                       ->andReturn($this->post);
        $this->validate->shouldReceive('validateSignature')
                       ->andThrow($this->exceptionError);

        $this->validate->authorizeCommand = $this->authorizeCommand;

        $this->authorizeCommand->shouldReceive('execute');

        $this->response = $this->validate->execute();

        $expectedResponse = '{"success":false,"redirect_url":"checkout\/#payment","parameters":[],"message":"Test Exception error message"}';

        $this->assertSame($expectedResponse, $this->getProperty($this->response, 'json'));
    }

    function testValidateEmailExceptionError()
    {
        $this->config->shouldReceive('getPaymentAction')
                     ->andReturn('authorize');

        $this->validate = \Mockery::mock(Razorpay\Magento\Controller\Payment\Validate::class,
                                        [
                                            $this->context,
                                            $this->customerSession,
                                            $this->checkoutSession,
                                            $this->config,
                                            $this->catalogSession,
                                            $this->order,
                                            $this->invoiceService,
                                            $this->transaction,
                                            $this->invoiceSender,
                                            $this->orderRepository,
                                            $this->orderSender,
                                            $this->logger
                                        ])
                                        ->makePartial()
                                        ->shouldAllowMockingProtectedMethods();

        $this->api = $this->getMockBuilder(Razorpay\Api\Api::class)
                          ->disableOriginalConstructor()
                          ->disableOriginalClone()
                          ->disableArgumentCloning()
                          ->disallowMockingUnknownTypes()
                          ->getMock();

        $this->api->method('__get')->willReturnCallback(function ($propertyName)
        {
            switch($propertyName)
            {
                case 'utility':
                    return $this->utilityApi;
            }
        });

        $this->validate->rzp = $this->api;

        $this->validate->setMockInit($this->objectManager);
        $this->validate->shouldReceive('getPostData')
                       ->andReturn($this->post);

        $this->checkoutSession->shouldReceive('setRazorpayMailSentOnSuccess')
                              ->andThrow($this->emailExceptionError);

        $this->validate->authorizeCommand = $this->authorizeCommand;

        $this->authorizeCommand->shouldReceive('execute');

        $this->response = $this->validate->execute();

        $expectedResponse = '{"success":true,"redirect_url":"checkout\/onepage\/success\/","order_id":"000000001"}';

        $this->assertSame($expectedResponse, $this->getProperty($this->response, 'json'));
    }

    function testGetPostData()
    {
        $expectedResponse = [
            'razorpay_payment_id' => 'pay_LJ7q34bS5acDu5',
            'razorpay_signature'  => '032e145d2f885ce5452aca638e338e4ee840e8dfb857055da53596d9592b89b6',
            'razorpay_order_id'   => 'order_LJ7pjEUXgeOhhj'
        ];

        $this->validate = \Mockery::mock(Razorpay\Magento\Controller\Payment\Validate::class,
                                        [
                                            $this->context,
                                            $this->customerSession,
                                            $this->checkoutSession,
                                            $this->config,
                                            $this->catalogSession,
                                            $this->order,
                                            $this->invoiceService,
                                            $this->transaction,
                                            $this->invoiceSender,
                                            $this->orderRepository,
                                            $this->orderSender,
                                            $this->logger
                                        ])
                                        ->makePartial()->shouldAllowMockingProtectedMethods();

        $this->validate->shouldReceive('fileGetContents')
                       ->andReturn($this->post);

        $this->assertSame($this->validate->getPostData(), $expectedResponse);
    }
}
