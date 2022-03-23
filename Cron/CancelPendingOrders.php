<?php
namespace Razorpay\Magento\Cron;
use \Magento\Sales\Model\Order;

class CancelPendingOrders {
    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    protected $orderRepository;
    /**
     * @var \Magento\Framework\Api\SearchCriteriaBuilder
     */
    protected $searchCriteriaBuilder;

    /**
     * @var \Magento\Sales\Api\OrderManagementInterface
     */
    protected $orderManagement;

    /**
     * @var STATUS_PENDING
     */
    protected const STATUS_PENDING = 'pending';

    /**
     * CancelOrder constructor.
     * @param \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
     * @param \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder
     * @param \Magento\Framework\Api\SortOrderBuilder $sortOrderBuilder
     * @param \Magento\Sales\Api\OrderManagementInterface $orderManagement
     * @param \Razorpay\Magento\Model\Config $config
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder,
        \Magento\Framework\Api\SortOrderBuilder $sortOrderBuilder,
        \Magento\Sales\Api\OrderManagementInterface $orderManagement,
        \Razorpay\Magento\Model\Config $config,
        \Psr\Log\LoggerInterface $logger
    )
    {
        $this->orderRepository                 = $orderRepository;
        $this->searchCriteriaBuilder           = $searchCriteriaBuilder;
        $this->sortOrderBuilder                = $sortOrderBuilder;
        $this->orderManagement                 = $orderManagement;
        $this->config                          = $config;
        $this->logger                          = $logger;
        $this->isCancelPendingOrderCronEnabled = $this->config->isCancelPendingOrderCronEnabled();
        $this->pendingOrderTimeout             = ($this->config->getPendingOrderTimeout() > 0) ? $this->config->getPendingOrderTimeout() : 30;
    }

    public function execute()
    {
        // Execute only if Cancel Pending Order Cron is Enabled
        if ($this->isCancelPendingOrderCronEnabled === true
            && $this->pendingOrderTimeout > 0)
        {
            $this->logger->info("Cronjob: Cancel Pending Order Cron started.");
            $dateTimeCheck = date('Y-m-d H:i:s', strtotime('-' . $this->pendingOrderTimeout . ' minutes'));
            $sortOrder = $this->sortOrderBuilder->setField('entity_id')->setDirection('DESC')->create();
            $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter(
                'updated_at',
                $dateTimeCheck,
                'lt'
            )->addFilter(
               'status',
               static::STATUS_PENDING,
               'eq'
            )->setSortOrders(
                [$sortOrder]
            )->create();

            $orders = $this->orderRepository->getList($searchCriteria);
            foreach ($orders->getItems() as $order)
            {
                $this->cancelOrder($order);
            }
        } else
        {
            $this->logger->critical('Cronjob: isCancelPendingOrderCronEnabled:'
             . $this->isCancelPendingOrderCronEnabled . ', '
            . 'pendingOrderTimeout:' . $this->pendingOrderTimeout);
        }
    }

    private function cancelOrder($order)
    {
        if ($order)
        {
            if ($order->canCancel()) {
                $this->logger->info("Cronjob: Cancelling Order ID: " . $order->getEntityId());

                $order->cancel()
                ->setState(
                    Order::STATE_CANCELED,
                    Order::STATE_CANCELED,
                    'Payment Failed',
                    false
                )->save();
            }
        }
    }
}
