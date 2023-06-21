<?php

declare(strict_types = 1);

use PHPUnit\Framework\TestCase;
use \Magento\Framework\Exception\MailException;
use Magento\Framework\Phrase;

/**
 * @covers Razorpay\Magento\Cron\CancelPendingOrders
 */
 class CancelPendingOrdersCronTest extends TestCase 
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

        $this->orderManagement = $this->createMock(
            \Magento\Sales\Api\OrderManagementInterface::class
        );

        $this->config = \Mockery::mock(
            \Razorpay\Magento\Model\Config::class
        );

        $this->logger = $this->createMock(
            \Psr\Log\LoggerInterface::class
        );

        $this->orderModel = \Mockery::mock(
            \Magento\Sales\Model\Order::class
        );

        $this->orderModelItem = \Mockery::mock(
            \Magento\Sales\Model\Order::class
        );
        
        $this->searchCriteriaInterface = $this->createMock(
            Magento\Framework\Api\SearchCriteriaInterface::class
        );

        $this->paymentModel = \Mockery::mock(
            \Magento\Sales\Model\Order\Payment::class
        );
        
        $this->sortOrderBuilder->shouldReceive('setField')->andReturn($this->sortOrderBuilder);
        $this->sortOrderBuilder->shouldReceive('setDirection')->andReturn($this->sortOrderBuilder);
        $this->sortOrderBuilder->shouldReceive('create')->andReturn($this->sortOrderBuilder);
        
        $this->searchCriteriaBuilder->shouldReceive('addFilter')->andReturn($this->searchCriteriaBuilder);
        $this->searchCriteriaBuilder->shouldReceive('setSortOrders')->andReturn($this->searchCriteriaBuilder);
        $this->searchCriteriaBuilder->shouldReceive('create')->andReturn($this->searchCriteriaInterface);
        
        $this->orderRepository->shouldReceive('getList')->andReturn($this->orderModel);

        $this->orderModel->shouldReceive('getItems')->andReturn([$this->orderModelItem]);

        $this->orderModelItem->shouldReceive('getPayment')->andReturn($this->paymentModel);
        $this->orderModelItem->shouldReceive('canCancel')->andReturn(true);
        $this->orderModelItem->shouldReceive('getEntityId')->andReturn(1);
        $this->orderModelItem->shouldReceive('cancel')->andReturn($this->orderModelItem);
        $this->orderModelItem->shouldReceive('setState')->andReturn($this->orderModelItem);
        $this->orderModelItem->shouldReceive('save')->andReturn($this->orderModelItem);
        
        $this->paymentModel->shouldReceive('getMethod')->andReturn('razorpay');
    }

    public function testExecuteCancelEnabledResetCartEnabled()
    {
        $this->config->shouldReceive('isCancelPendingOrderCronEnabled')->andReturn(true);
        $this->config->shouldReceive('getPendingOrderTimeout')->andReturn(0);
        $this->config->shouldReceive('isCancelResetCartOrderCronEnabled')->andReturn(true);
        $this->config->shouldReceive('getResetCartOrderTimeout')->andReturn(0);

        $this->cancelPendingOrders = \Mockery::mock(Razorpay\Magento\Cron\CancelPendingOrders::class, 
                                        [
                                            $this->orderRepository, 
                                            $this->searchCriteriaBuilder,
                                            $this->sortOrderBuilder,
                                            $this->orderManagement,
                                            $this->config,
                                            $this->logger
                                        ]
                                     )->makePartial()->shouldAllowMockingProtectedMethods();

        $this->cancelPendingOrders->execute();
    }

    public function testExecuteCancelNotEnabledResetCartNotEnabled()
    {
        $this->config->shouldReceive('isCancelPendingOrderCronEnabled')->andReturn(false);
        $this->config->shouldReceive('getPendingOrderTimeout')->andReturn(0);
        $this->config->shouldReceive('isCancelResetCartOrderCronEnabled')->andReturn(false);
        $this->config->shouldReceive('getResetCartOrderTimeout')->andReturn(0);

        $this->cancelPendingOrders = \Mockery::mock(Razorpay\Magento\Cron\CancelPendingOrders::class, 
                                        [
                                            $this->orderRepository, 
                                            $this->searchCriteriaBuilder,
                                            $this->sortOrderBuilder,
                                            $this->orderManagement,
                                            $this->config,
                                            $this->logger
                                        ]
                                     )->makePartial()->shouldAllowMockingProtectedMethods();

        $this->cancelPendingOrders->execute();
    }
}
