<?php

declare(strict_types = 1);

use PHPUnit\Framework\TestCase;
use \Magento\Framework\Exception\MailException;
use Magento\Framework\Phrase;

/**
 * @covers Razorpay\Magento\Cron\UpdateOrdersToProcessing
 */
class UpdateOrdersToProcessingCronTest extends TestCase 
{
    public function setUp():void
    {
		$this->orderRepository = \Mockery::mock(
		    \Magento\Sales\Api\OrderRepositoryInterface::class
		);

        $this->searchCriteriaBuilder = \Mockery::mock(
            \Magento\Framework\Api\SearchCriteriaBuilder::class
        );

        $this->sortOrderBuilder = \Mockery::mock(
            \Magento\Framework\Api\SortOrderBuilder::class
        );

        $this->checkoutSession = \Mockery::mock(
            \Magento\Checkout\Model\Session::class
        );

        $this->orderManagement = $this->createMock(
            \Magento\Sales\Api\OrderManagementInterface::class
        );

        $this->invoiceService = \Mockery::mock(
            \Magento\Sales\Model\Service\InvoiceService::class
        );

        $this->transaction = \Mockery::mock(
            \Magento\Framework\DB\Transaction::class
        );

        $this->invoiceSender = \Mockery::mock(
            \Magento\Sales\Model\Order\Email\Sender\InvoiceSender::class
        );

        $this->orderSender = \Mockery::mock(
            \Magento\Sales\Model\Order\Email\Sender\OrderSender::class
        );

        $this->config = \Mockery::mock(
            \Razorpay\Magento\Model\Config::class
        );

        $this->logger = $this->createMock(
            \Psr\Log\LoggerInterface::class
        );

        $this->searchCriteriaInterface = $this->createMock(
            Magento\Framework\Api\SearchCriteriaInterface::class
        );

        $this->orderInterface = $this->createMock(
            \Magento\Sales\Api\Data\OrderInterface::class
        ); 

        // $this->orderItemInterface = $this->createMock(
        //     \Magento\Sales\Api\Data\OrderItemInterface::class
        // );

        $this->orderModel = \Mockery::mock(
            \Magento\Sales\Model\Order::class
        );

        $this->orderModel_1 = \Mockery::mock(
            \Magento\Sales\Model\Order::class
        );

        $this->orderModel_2 = \Mockery::mock(
            \Magento\Sales\Model\Order::class
        );

        $this->paymentModel = \Mockery::mock(
            \Magento\Sales\Model\Order\Payment::class
        );

        $this->transactionModel = \Mockery::mock(
            \Magento\Sales\Model\Order\Payment\Transaction::class
        );

        $this->currencyModel = \Mockery::mock(
            \Magento\Directory\Model\Currency::class
        );

        $this->objectManager = \Mockery::mock(
            \Magento\Framework\ObjectManagerInterface::class
        );

        $this->quoteModel = \Mockery::mock(
            Magento\Quote\Model\Quote::class
        );

		$this->captureCommand = \Mockery::mock(
            \Magento\Sales\Model\Order\Payment\State\CaptureCommand::class
        );

        $this->authorizeCommand = \Mockery::mock(
            \Magento\Sales\Model\Order\Payment\State\AuthorizeCommand::class
        );

		$this->invoice = \Mockery::mock(
            \Magento\Sales\Model\Order\Invoice::class
        );

		$this->abstractModel = \Mockery::mock(
            Magento\Framework\Model\AbstractModel::class
        );

		$this->captureCommand->shouldReceive('execute');

		$this->authorizeCommand->shouldReceive('execute');

		$this->config->shouldReceive('getConfigData')
					 ->with('key_id')
					 ->andReturn('key_id');

		$this->config->shouldReceive('getConfigData')
					 ->with('key_secret')
					 ->andReturn('key_secret');

		$this->config->shouldReceive('isCustomPaidOrderStatusEnabled')
					 ->andReturn(true);

		$this->config->shouldReceive('getCustomPaidOrderStatus')
					 ->andReturn('somecustomvalue');
		$this->config->shouldReceive('canAutoGenerateInvoice')
					 ->andReturn(true);
		
		$this->invoiceService->shouldReceive('prepareInvoice')
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

		$this->invoiceSender->shouldReceive('send');

		$this->checkoutSession->shouldReceive('setRazorpayMailSentOnSuccess')
                              ->andReturn($this->checkoutSession);
        $this->checkoutSession->shouldReceive('unsRazorpayMailSentOnSuccess')
                              ->andReturn($this->checkoutSession);

		$this->updateOrdersToProcessing = \Mockery::mock(Razorpay\Magento\Cron\UpdateOrdersToProcessing::class,
														[
															$this->orderRepository,
															$this->searchCriteriaBuilder,
															$this->sortOrderBuilder,
															$this->checkoutSession,
															$this->orderManagement,
															$this->invoiceService,
															$this->transaction,
															$this->invoiceSender,
															$this->orderSender,
															$this->config,
															$this->logger
														])
														->makePartial()
														->shouldAllowMockingProtectedMethods();

		$this->sortOrderBuilder->shouldReceive('setField')
							   ->with('entity_id')
							   ->andReturn($this->sortOrderBuilder);
		$this->sortOrderBuilder->shouldReceive('setDirection')
							   ->with('DESC')
							   ->andReturn($this->sortOrderBuilder);
		$this->sortOrderBuilder->shouldReceive('create')
							   ->andReturn($this->sortOrderBuilder);

		$this->searchCriteriaBuilder->shouldReceive('addFilter')
									->with('rzp_update_order_cron_status', 5, 'lt')
									->andReturn($this->searchCriteriaBuilder);
		$this->searchCriteriaBuilder->shouldReceive('addFilter')
									->with('rzp_webhook_notified_at', null, 'neq')
									->andReturn($this->searchCriteriaBuilder);
		$this->searchCriteriaBuilder->shouldReceive('addFilter')
									->with('rzp_webhook_notified_at', time() - (5 * 60), 'lt')
									->andReturn($this->searchCriteriaBuilder);
		$this->searchCriteriaBuilder->shouldReceive('addFilter')
									->with('status', 'pending', 'eq')
									->andReturn($this->searchCriteriaBuilder);
		$this->searchCriteriaBuilder->shouldReceive('setSortOrders')
									->andReturn($this->searchCriteriaBuilder);
		$this->searchCriteriaBuilder->shouldReceive('create')
									->andReturn($this->searchCriteriaInterface);

		$this->orderRepository->shouldReceive('getList')
							  ->with($this->searchCriteriaInterface)
							  ->andReturn($this->orderModel);
		
		$orderModel_1_data = [
		    'order.paid' => [
		            'webhook_verified_status' => true,
		            'payment_id' => 'pay_KMYpn54F9caDqo',
		            'amount' => 3700
			],
		];

		$orderModel_2_data = [
			'payment.authorized' => [
				'webhook_verified_status' => true,
				'payment_id' => 'pay_KMYb26r7N0qQV8',
				'amount' => 18800
			]
		];

		$this->paymentModel->shouldReceive('getTransactionId');
		$this->paymentModel->shouldReceive('getMethod')
						   ->andReturn('razorpay');
		$this->paymentModel->shouldReceive('setLastTransId')
						   ->andReturn($this->paymentModel);
		$this->paymentModel->shouldReceive('setTransactionId')
						   ->andReturn($this->paymentModel);
		$this->paymentModel->shouldReceive('setIsTransactionClosed')
						   ->andReturn($this->paymentModel);						   
		$this->paymentModel->shouldReceive('setShouldCloseParentTransaction')
						   ->andReturn($this->paymentModel);
		$this->paymentModel->shouldReceive('setParentTransactionId')
						   ->andReturn($this->paymentModel);
		$this->paymentModel->shouldReceive('getIsTransactionPending')
						   ->andReturn(true);
		$this->paymentModel->shouldReceive('getIsFraudDetected')
						   ->andReturn(false);
		$this->paymentModel->shouldReceive('getExtensionAttributes')
						   ->andReturn(true);
		$this->paymentModel->shouldReceive('addTransaction')
						   ->with('authorization', null, true, "")
						   ->andReturn($this->transactionModel);
		$this->paymentModel->shouldReceive('addTransactionCommentsToOrder');

		$this->transactionModel->shouldReceive('setIsClosed')
							   ->with(true)
							   ->andReturn($this->transactionModel);
		$this->transactionModel->shouldReceive('save');

		$this->orderModel_1->shouldReceive('getPayment')
						   ->andReturn($this->paymentModel);
		$this->orderModel_1->shouldReceive('getRzpWebhookData')
						   ->andReturn(serialize($orderModel_1_data));
		$this->orderModel_1->shouldReceive('getEntityId')
						   ->andReturn(1);
		$this->orderModel_1->shouldReceive('getGrandTotal')
						   ->andReturn(3700);
		$this->orderModel_1->shouldReceive('setState')
						   ->andReturn('processing')
						   ->andReturn($this->orderModel_1);
		$this->orderModel_1->shouldReceive('setStatus')
						   ->andReturn('processing')
						   ->andReturn($this->orderModel_1);
		$this->orderModel_1->shouldReceive('getBaseCurrency')
						   ->andReturn($this->currencyModel);
		$this->orderModel_1->shouldReceive('addStatusHistoryComment')->andReturn($this->orderModel_1);
		$this->orderModel_1->shouldReceive('setIsCustomerNotified');
		$this->orderModel_1->shouldReceive('getQuoteId')
						   ->andReturn('1');
		$this->orderModel_1->shouldReceive('getRzpUpdateOrderCronStatus')
						   ->andReturn(1);
		$this->orderModel_1->shouldReceive('setRzpUpdateOrderCronStatus');
		$this->orderModel_1->shouldReceive('save');
		$this->orderModel_1->shouldReceive('canInvoice')->andReturn(true);
		

		
		$this->orderModel_2->shouldReceive('getPayment')
						   ->andReturn($this->paymentModel);
		$this->orderModel_2->shouldReceive('getRzpWebhookData')
						   ->andReturn(serialize($orderModel_2_data));
		$this->orderModel_2->shouldReceive('getEntityId')
						   ->andReturn(2);
		$this->orderModel_2->shouldReceive('getGrandTotal')
						   ->andReturn(18800);
		$this->orderModel_2->shouldReceive('setState')
						   ->andReturn('processing')
						   ->andReturn($this->orderModel_2);
		$this->orderModel_2->shouldReceive('setStatus')
						   ->andReturn('processing')
						   ->andReturn($this->orderModel_2);
		$this->orderModel_2->shouldReceive('getBaseCurrency')
						   ->andReturn($this->currencyModel);
		$this->orderModel_2->shouldReceive('addStatusHistoryComment')->andReturn($this->orderModel_2);
		$this->orderModel_2->shouldReceive('setIsCustomerNotified');
		$this->orderModel_2->shouldReceive('getQuoteId')
						   ->andReturn('2');
		$this->orderModel_2->shouldReceive('getRzpUpdateOrderCronStatus')
						   ->andReturn(1);
		$this->orderModel_2->shouldReceive('setRzpUpdateOrderCronStatus');
		$this->orderModel_2->shouldReceive('save');
		$this->orderModel_2->shouldReceive('canInvoice')->andReturn(true);
		
		
		$this->currencyModel->shouldReceive('formatTxt');

		$this->orderModel->shouldReceive('getItems')
							  ->andReturn([$this->orderModel_1, $this->orderModel_2]);

        $this->quoteModel->shouldReceive('load')
                         ->with('1')
                         ->andReturn($this->quoteModel);

        $this->quoteModel->shouldReceive('load')
                         ->with('2')
                         ->andReturn($this->quoteModel);

        $this->quoteModel->shouldReceive('setIsActive')
                         ->with(false)
                         ->andReturn($this->quoteModel);
        $this->quoteModel->shouldReceive('save')
                         ->andReturn($this->quoteModel);

        $this->objectManager->shouldReceive('get')
                            ->with('Magento\Quote\Model\Quote')
                            ->andReturn($this->quoteModel);

		$this->updateOrdersToProcessing->shouldReceive('getObjectManager')
									   ->andReturn($this->objectManager);
		
		$this->updateOrdersToProcessing->captureCommand = $this->captureCommand;
		$this->updateOrdersToProcessing->authorizeCommand = $this->authorizeCommand;	 
    }

    public function testExecuteSuccess()
    {
		$this->orderSender->shouldReceive('send');

		$this->updateOrdersToProcessing->execute();
    	$this->assertSame(true,true);
    }

	public function testExecuteException()
    {
		$this->orderSender->shouldReceive('send')->andThrow(new Exception("Test exception message"));

		$this->updateOrdersToProcessing->execute();

    	$this->assertSame(true,true);
    }

	public function testExecuteMailException()
    {
		$exceptionPhrase = new Phrase('Test Mail Exception');
        $mailException = new MailException($exceptionPhrase);

		$this->orderSender->shouldReceive('send')->andThrow($mailException);

		$this->updateOrdersToProcessing->execute();
		
    	$this->assertSame(true,true);
    }
}