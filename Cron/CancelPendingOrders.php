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
     * @var STATUS_CANCELED
     */
    protected const STATUS_CANCELED = 'canceled';

    /**
     * @var STATE_NEW
     */
    protected const STATE_NEW = 'new';

    /**
     * @var \Magento\Framework\Api\SortOrderBuilder
     */
    protected $sortOrderBuilder;

    /**
     * @var \Razorpay\Magento\Model\Config
     */
    protected $config;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    protected $isCancelPendingOrderCronEnabled;

    protected $pendingOrderTimeout;

    protected $isCancelResetCartCronEnabled;

    protected $resetCartOrderTimeout;

    /**
     * @var \Razorpay\Magento\Model\Util\DebugUtils
     */
    protected $debug;

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
        \Psr\Log\LoggerInterface $logger,
        \Razorpay\Magento\Model\Util\DebugUtils $debug
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
        $this->isCancelResetCartCronEnabled    = $this->config->isCancelResetCartOrderCronEnabled();
        $this->resetCartOrderTimeout           = ($this->config->getResetCartOrderTimeout() > 0) ? $this->config->getResetCartOrderTimeout() : 30;
        $this->debug                           = $debug;
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
                if ($order->getPayment()->getMethod() === 'razorpay') {
                    $this->debug->log("Cronjob: Magento Order Id = " . $order->getIncrementId() . " picked for cancelation.");

                    $this->cancelOrder($order);    
                }
            }
        } else
        {
            $this->logger->critical('Cronjob: isCancelPendingOrderCronEnabled:'
             . $this->isCancelPendingOrderCronEnabled . ', '
            . 'pendingOrderTimeout:' . $this->pendingOrderTimeout);
        }

        // Execute only if Reset Cart Cron is Enabled
        if ($this->isCancelResetCartCronEnabled === true
            && $this->resetCartOrderTimeout > 0)
        {
            $this->logger->info("Cronjob: Cancel Reset Cart Order Cron started.");
            $dateTimeCheck = date('Y-m-d H:i:s', strtotime('-' . $this->resetCartOrderTimeout . ' minutes'));
            $sortOrder = $this->sortOrderBuilder->setField('entity_id')->setDirection('DESC')->create();
            $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter(
                'updated_at',
                $dateTimeCheck,
                'lt'
            )->addFilter(
               'state',
               static::STATE_NEW,
               'eq'
            )->addFilter(
               'status',
               static::STATUS_CANCELED,
               'eq'
            )->setSortOrders(
                [$sortOrder]
            )->create();

            $orders = $this->orderRepository->getList($searchCriteria);

            foreach ($orders->getItems() as $order)
            {
                if ($order->getPayment()->getMethod() === 'razorpay') {
                    $this->debug->log("Cronjob: Magento Order Id = " . $order->getIncrementId() . " picked for cancelation in reset cart cron.");

                    $this->cancelOrder($order);
                }
            }
        } else
        {
            $this->logger->critical('Cronjob: isCancelResetCartCronEnabled:'
             . $this->isCancelResetCartCronEnabled . ', '
            . 'resetCartOrderTimeout:' . $this->resetCartOrderTimeout);
        }
    }

    private function cancelOrder($order)
    {
        if ($order)
        {
            if ($order->canCancel() and
                $this->isOrderAlreadyPaid($order->getEntityId()) === false) 
            {
                $this->logger->info("Cronjob: Cancelling Order ID: " . $order->getIncrementId());

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

    private function isOrderAlreadyPaid($entity_id)
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

        $orderLinkData = $objectManager->get('Razorpay\Magento\Model\OrderLink')
                        ->getCollection()
                        ->addFilter('order_id', $entity_id)
                        ->getFirstItem();
        
        return ($orderLinkData->getRzpWebhookNotifiedAt() !== null);
    }
}
