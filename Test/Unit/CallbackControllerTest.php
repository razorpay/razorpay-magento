<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use \Magento\Framework\Exception\MailException;
use Magento\Framework\Phrase;

class CallbackControllerTest extends TestCase {
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

        $this->logger = $this->createMock(
            \Psr\Log\LoggerInterface::class
        );

        $this->orderRepository = \Mockery::mock(
            \Magento\Sales\Api\OrderRepositoryInterface::class
        );

        $this->transaction = \Mockery::mock(
            \Magento\Framework\DB\Transaction::class
        );

        $this->invoiceService = \Mockery::mock(
            \Magento\Sales\Model\Service\InvoiceService::class
        );

        $this->orderSender = \Mockery::mock(
            \Magento\Sales\Model\Order\Email\Sender\OrderSender::class
        );

        $this->invoiceSender = \Mockery::mock(
            \Magento\Sales\Model\Order\Email\Sender\InvoiceSender::class
        );

        $this->catalogSession = \Mockery::mock(
            \Magento\Catalog\Model\Session::class
        );

        $this->order = \Mockery::mock(
            \Magento\Sales\Api\Data\OrderInterface::class
        );

        $this->requestInterface = \Mockery::mock(
            \Magento\Framework\App\RequestInterface::class
        );

        $this->orderCollection = \Mockery::mock(
            \Magento\Sales\Model\Order::class
        );

        $this->orderModel = \Mockery::mock(
            \Magento\Sales\Model\Order::class
        );

        $this->payment = \Mockery::mock(
            \Magento\Sales\Api\Data\OrderPaymentInterface::class
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

        $this->quoteModel = \Mockery::mock(
            \Magento\Quote\Model\Quote::class
        );

        $this->invoice = \Mockery::mock(
            \Magento\Sales\Model\Order\Invoice::class
        );

        $this->redirect = \Mockery::mock(
            \Magento\Framework\App\Response\RedirectInterface::class
        )->makePartial();

        $this->response = \Mockery::mock(
            \Magento\Framework\App\ResponseInterface::class
        );

        $this->messageManager = \Mockery::mock(
            \Magento\Framework\Message\ManagerInterface::class
        );

        $this->redirect->shouldReceive('redirect');

        $this->messageManager->shouldReceive('addError');

        $this->context->shouldReceive('getRedirect')->andReturn($this->redirect);
        $this->context->shouldReceive('getResponse')->andReturn($this->response);
        $this->context->shouldReceive('getMessageManager')->andReturn($this->messageManager);
        
        $this->payment->shouldReceive('setLastTransId')->andReturn($this->payment);
        $this->payment->shouldReceive('setTransactionId')->andReturn($this->payment);
        $this->payment->shouldReceive('setIsTransactionClosed')->andReturn($this->payment);
        $this->payment->shouldReceive('setShouldCloseParentTransaction')->andReturn($this->payment);
        $this->payment->shouldReceive('setParentTransactionId')->andReturn($this->payment);
        $this->payment->shouldReceive('getTransactionId')->andReturn('Test transaction ID');
        $this->payment->shouldReceive('addTransactionCommentsToOrder');
        $this->payment->shouldReceive('addTransaction')->andReturn($this->transactionModel);
        
        $this->config->shouldReceive('getConfigData')->with('key_id')->andReturn('key_id');
        $this->config->shouldReceive('getConfigData')->with('key_secret')->andReturn('key_secret');
        $this->config->shouldReceive('getKeyId')->andReturn('key_id');
        $this->config->shouldReceive('canAutoGenerateInvoice')->andReturn(true);
        
        $this->orderCollection->shouldReceive('getCollection')->andReturn($this->orderCollection);
        $this->orderCollection->shouldReceive('addFieldToSelect')->with('entity_id')->andReturn($this->orderCollection);
        $this->orderCollection->shouldReceive('addFieldToSelect')->with('rzp_order_id')->andReturn($this->orderCollection);
        $this->orderCollection->shouldReceive('addFilter')->with('increment_id', '000012')->andReturn($this->orderCollection);
        $this->orderCollection->shouldReceive('getFirstItem')->andReturn($this->orderCollection);
        $this->orderCollection->shouldReceive('getRzpOrderId')->andReturn('order_test');
        $this->orderCollection->shouldReceive('getEntityId')->andReturn('000012');
        
        $this->orderModel->shouldReceive('getIncrementId')->andReturn('000012');
        $this->orderModel->shouldReceive('setState')->andReturn($this->orderModel);
        $this->orderModel->shouldReceive('setStatus')->andReturn( $this->orderModel);
        $this->orderModel->shouldReceive('getPayment')->andReturn($this->payment);
        $this->orderModel->shouldReceive('getGrandTotal')->andReturn(10000);
        $this->orderModel->shouldReceive('save')->andReturn(10000);
        $this->orderModel->shouldReceive('getQuoteId')->andReturn('000012');
        $this->orderModel->shouldReceive('canInvoice')->andReturn(true);
        $this->orderModel->shouldReceive('addStatusHistoryComment')->andReturn($this->orderModel);
        $this->orderModel->shouldReceive('setIsCustomerNotified')->andReturn($this->orderModel);
        $this->orderModel->shouldReceive('getId')->andReturn('000012');
        $this->orderModel->shouldReceive('getIncrementId')->andReturn('000012');
        $this->orderModel->shouldReceive('getStatus')->andReturn('completed');
        
        $this->order->shouldReceive('load')->andReturn($this->orderModel);

        $this->transactionModel->shouldReceive('setIsClosed');
        $this->transactionModel->shouldReceive('save');
        
        $this->orderRepository->shouldReceive('save');
        
        $this->quoteModel->shouldReceive('load')->andReturn($this->quoteModel);
        $this->quoteModel->shouldReceive('setIsActive')->andReturn($this->quoteModel);
        $this->quoteModel->shouldReceive('setReservedOrderId')->andReturn($this->quoteModel);
        $this->quoteModel->shouldReceive('save')->andReturn($this->quoteModel);
        
        $this->invoiceService->shouldReceive('prepareInvoice')->andReturn($this->invoice);

        $this->invoice->shouldReceive('setRequestedCaptureCase');
        $this->invoice->shouldReceive('setTransactionId');
        $this->invoice->shouldReceive('register');
        $this->invoice->shouldReceive('save');
        $this->invoice->shouldReceive('getOrder')->andReturn($this->orderModel);
        $this->invoice->shouldReceive('getId')->andReturn('000012');
        
        $this->transaction->shouldReceive('addObject')->andReturn($this->transaction);
        $this->transaction->shouldReceive('save');

        $this->invoiceSender->shouldReceive('send');

        $this->checkoutSession->shouldReceive('unsRazorpayMailSentOnSuccess');
        $this->checkoutSession->shouldReceive('setLastSuccessQuoteId')->andReturn($this->checkoutSession);
        $this->checkoutSession->shouldReceive('setLastQuoteId')->andReturn($this->checkoutSession);
        $this->checkoutSession->shouldReceive('clearHelperData')->andReturn($this->checkoutSession);
        $this->checkoutSession->shouldReceive('setLastOrderId')->andReturn($this->checkoutSession);
        $this->checkoutSession->shouldReceive('setLastRealOrderId')->andReturn($this->checkoutSession);
        $this->checkoutSession->shouldReceive('setLastOrderStatus')->andReturn($this->checkoutSession);
        $this->checkoutSession->shouldReceive('replaceQuote');
        
        $this->orderSender->shouldReceive('send');

        $this->callback = \Mockery::mock(Razorpay\Magento\Controller\Payment\Callback::class, [ $this->context,
                                                                                                $this->customerSession,
                                                                                                $this->checkoutSession,
                                                                                                $this->config,
                                                                                                $this->logger,
                                                                                                $this->orderRepository,
                                                                                                $this->transaction,
                                                                                                $this->invoiceService,
                                                                                                $this->orderSender,
                                                                                                $this->invoiceSender,
                                                                                                $this->catalogSession,
                                                                                                $this->order])->makePartial()->shouldAllowMockingProtectedMethods();
        $this->callback->shouldReceive('getRequest')->andReturn($this->requestInterface);
    }

    public function testExecuteSuccess()
    {
        $this->checkoutSession->shouldReceive('setRazorpayMailSentOnSuccess');
        $this->callback->shouldReceive('validateSignature');
        $this->config->shouldReceive('getPaymentAction')->andReturn('authorize_capture');

        $this->requestInterface->shouldReceive('getParams')->andReturn(['order_id' => '000012',
                                                                        'razorpay_payment_id' => 'Test Payment id']);
        $this->callback->objectManagement = \Mockery::mock(
            \Magento\Framework\App\ObjectManager::class
        );
        $this->callback->captureCommand = $this->captureCommand;
        $this->captureCommand->shouldReceive('execute');
        $this->callback->objectManagement->shouldReceive('get')->with('Magento\Sales\Model\Order')->andReturn($this->orderCollection);
        $this->callback->objectManagement->shouldReceive('get')->with('Magento\Quote\Model\Quote')->andReturn($this->quoteModel);
        $this->assertSame($this->response, $this->callback->execute());
    }

    public function testOrderAuthorizeSuccess()
    {
        $this->checkoutSession->shouldReceive('setRazorpayMailSentOnSuccess');
        
        $this->callback->shouldReceive('validateSignature');
        $this->config->shouldReceive('getPaymentAction')->andReturn('authorize');
        
        $this->requestInterface->shouldReceive('getParams')->andReturn(['order_id' => '000012',
                                                                        'razorpay_payment_id' => 'Test Payment id']);
        $this->callback->objectManagement = \Mockery::mock(
            \Magento\Framework\App\ObjectManager::class
        );
        $this->callback->authorizeCommand = $this->authorizeCommand;
        $this->authorizeCommand->shouldReceive('execute');
        $this->callback->objectManagement->shouldReceive('get')->with('Magento\Sales\Model\Order')->andReturn($this->orderCollection);
        $this->callback->objectManagement->shouldReceive('get')->with('Magento\Quote\Model\Quote')->andReturn($this->quoteModel);
        $this->assertSame($this->response,$this->callback->execute());
    }
    
    public function testEmptyOrderId()
    {
        $this->checkoutSession->shouldReceive('setRazorpayMailSentOnSuccess');
        
        $this->callback->shouldReceive('validateSignature');
        $this->config->shouldReceive('getPaymentAction')->andReturn('authorize_capture');

        $this->requestInterface->shouldReceive('getParams')->andReturn(['order_id' => '']);
        $this->callback->objectManagement = \Mockery::mock(
            \Magento\Framework\App\ObjectManager::class
        );
        $this->callback->captureCommand = $this->captureCommand;
        $this->captureCommand->shouldReceive('execute');
        $this->callback->objectManagement->shouldReceive('get')->with('Magento\Sales\Model\Order')->andReturn($this->orderCollection);
        $this->callback->objectManagement->shouldReceive('get')->with('Magento\Quote\Model\Quote')->andReturn($this->quoteModel);
        $this->assertSame($this->response, $this->callback->execute());
    }

    public function testApiErrorFromValidateSignature()
    {
        $this->checkoutSession->shouldReceive('setRazorpayMailSentOnSuccess');
        
        $this->apiError = \Mockery::mock(
            Razorpay\Api\Errors\Error::class,['Test Api error message', 0, 0]
        );
        $this->callback->shouldReceive('validateSignature')->andThrow($this->apiError);

        $this->config->shouldReceive('getPaymentAction')->andReturn('authorize_capture');

        $this->requestInterface->shouldReceive('getParams')->andReturn(['order_id' => '000012',
                                                                        'razorpay_payment_id' => 'Test Payment id']);
        $this->callback->objectManagement = \Mockery::mock(
            \Magento\Framework\App\ObjectManager::class
        );
        $this->callback->captureCommand = $this->captureCommand;
        $this->captureCommand->shouldReceive('execute');
        $this->callback->objectManagement->shouldReceive('get')->with('Magento\Sales\Model\Order')->andReturn($this->orderCollection);
        $this->callback->objectManagement->shouldReceive('get')->with('Magento\Quote\Model\Quote')->andReturn($this->quoteModel);
        $this->callback->execute();
    }

    public function testErrorFromValidateSignature()
    {
        $this->checkoutSession->shouldReceive('setRazorpayMailSentOnSuccess');
        
        $this->callback->shouldReceive('validateSignature')->andThrow(new Exception("Test exception message"));
        
        $this->config->shouldReceive('getPaymentAction')->andReturn('authorize_capture');

        $this->requestInterface->shouldReceive('getParams')->andReturn(['order_id' => '000012',
                                                                        'razorpay_payment_id' => 'Test Payment id']);
        $this->callback->objectManagement = \Mockery::mock(
            \Magento\Framework\App\ObjectManager::class
        );
        $this->callback->captureCommand = $this->captureCommand;
        $this->captureCommand->shouldReceive('execute');
        $this->callback->objectManagement->shouldReceive('get')->with('Magento\Sales\Model\Order')->andReturn($this->orderCollection);
        $this->callback->objectManagement->shouldReceive('get')->with('Magento\Quote\Model\Quote')->andReturn($this->quoteModel);
        $this->callback->execute();
    }

    public function testExecuteFailure()
    {
        $this->checkoutSession->shouldReceive('setRazorpayMailSentOnSuccess');
        
        $this->callback->shouldReceive('validateSignature')->andThrow(new Exception("Test exception message"));
        
        $this->config->shouldReceive('getPaymentAction')->andReturn('authorize_capture');

        $this->requestInterface->shouldReceive('getParams')->andReturn(['order_id' => '000012']);
        $this->callback->objectManagement = \Mockery::mock(
            \Magento\Framework\App\ObjectManager::class
        );
        $this->callback->captureCommand = $this->captureCommand;
        $this->captureCommand->shouldReceive('execute');
        $this->callback->objectManagement->shouldReceive('get')->with('Magento\Sales\Model\Order')->andReturn($this->orderCollection);
        $this->callback->objectManagement->shouldReceive('get')->with('Magento\Quote\Model\Quote')->andReturn($this->quoteModel);
        $this->assertSame($this->response, $this->callback->execute());
    }

    public function testExecuteMailException()
    {
        $exceptionPhrase = new Phrase('Test Mail Exception');
        $mailException = new MailException($exceptionPhrase);
        $this->checkoutSession->shouldReceive('setRazorpayMailSentOnSuccess')->andThrow($mailException);
        
        $this->callback->shouldReceive('validateSignature');
        $this->config->shouldReceive('getPaymentAction')->andReturn('authorize_capture');

        $this->requestInterface->shouldReceive('getParams')->andReturn(['order_id' => '000012',
                                                                        'razorpay_payment_id' => 'Test Payment id']);
        $this->callback->objectManagement = \Mockery::mock(
            \Magento\Framework\App\ObjectManager::class
        );
        $this->callback->captureCommand = $this->captureCommand;
        $this->captureCommand->shouldReceive('execute');
        $this->callback->objectManagement->shouldReceive('get')->with('Magento\Sales\Model\Order')->andReturn($this->orderCollection);
        $this->callback->objectManagement->shouldReceive('get')->with('Magento\Quote\Model\Quote')->andReturn($this->quoteModel);
        $this->assertSame($this->response, $this->callback->execute());
    }

    public function testExecuteMailSendingNormalException()
    {
        $this->checkoutSession->shouldReceive('setRazorpayMailSentOnSuccess')->andThrow(new Exception("Test exception message") );
        
        $this->callback->shouldReceive('validateSignature');
        $this->config->shouldReceive('getPaymentAction')->andReturn('authorize_capture');

        $this->requestInterface->shouldReceive('getParams')->andReturn(['order_id' => '000012',
                                                                        'razorpay_payment_id' => 'Test Payment id']);
        $this->callback->objectManagement = \Mockery::mock(
            \Magento\Framework\App\ObjectManager::class
        );
        $this->callback->captureCommand = $this->captureCommand;
        $this->captureCommand->shouldReceive('execute');
        $this->callback->objectManagement->shouldReceive('get')->with('Magento\Sales\Model\Order')->andReturn($this->orderCollection);
        $this->callback->objectManagement->shouldReceive('get')->with('Magento\Quote\Model\Quote')->andReturn($this->quoteModel);
        $this->assertSame($this->response, $this->callback->execute());
    }

    public function testvalidateSignatureFailure()
    {
        $request = [
            'error' => 'Test error'
        ];

        $this->expectException("Exception");
        $this->expectExceptionMessage("Payment Failed or error from gateway");
        $this->callback->validateSignature($request);
    }

    public function testvalidateSignatureSuccess()
    {
        $request = [
            'razorpay_payment_id' => 'Test payment id',
            'razorpay_signature' => 'Test payment signature'
        ];
        $this->api = $this->getMockBuilder(Razorpay\Api\Api::class)
                     ->disableOriginalConstructor()
                     ->disableOriginalClone()
                     ->disableArgumentCloning()
                     ->disallowMockingUnknownTypes()
                     ->getMock();
        $this->utilityApi = \Mockery::mock(
            Razorpay\Api\Utility::class
        );
        $this->utilityApi->shouldReceive('verifyPaymentSignature');
        $this->api->method('__get')
        ->willReturnCallback(function ($propertyName) {
                switch($propertyName) {
                    case 'utility':
                        return $this->utilityApi;
                }
            }
        );
        $this->callback->razorpayOrderID = 'Test razorpay order id';
        $this->callback->rzp = $this->api;
        $this->callback->validateSignature($request);
    }
}