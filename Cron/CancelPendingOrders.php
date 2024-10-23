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

    protected const PENDING_ORDER_CRON = 'pending_order_cron';

    protected const RESET_CART_CRON = 'reset_cart_cron';

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

    protected $isCancelPendingOrderAgeEnabled;

    protected $pendingOrderAge;

    protected const PENDING_ORDER_AGE_DEFAULT = 43200;

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
        $this->isCancelPendingOrderAgeEnabled  = $this->config->isCancelPendingOrderAgeEnabled();
        $this->pendingOrderAge                 = ($this->config->getPendingOrderAge() > 0) ? $this->config->getPendingOrderAge() : self::PENDING_ORDER_AGE_DEFAULT;
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

            $searchCriteria = $this->getSearchCriteria(self::PENDING_ORDER_CRON, $this->pendingOrderTimeout, $this->pendingOrderAge, null, self::STATUS_PENDING);

            $orders = $this->orderRepository->getList($searchCriteria);
            foreach ($orders->getItems() as $order)
            {
                if ($order->getPayment()->getMethod() === 'razorpay') {
                    $this->debug->log("Cronjob: Magento Order Id = " . $order->getIncrementId() . " picked for cancellation.");

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

            $searchCriteria = $this->getSearchCriteria(self::RESET_CART_CRON, $this->resetCartOrderTimeout, null, self::STATE_NEW, self::STATUS_CANCELED);

            $orders = $this->orderRepository->getList($searchCriteria);

            foreach ($orders->getItems() as $order)
            {
                if ($order->getPayment()->getMethod() === 'razorpay') {
                    $this->debug->log("Cronjob: Magento Order Id = " . $order->getIncrementId() . " picked for cancellation in reset cart cron.");

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

    private function getSearchCriteria($cronName, $orderTimeout, $orderAge, $orderState, $orderStatus)
    {
        $searchCriteria = null;
        if ($cronName === self::PENDING_ORDER_CRON)
        {
            $dateTimeCheck = date('Y-m-d H:i:s', strtotime('-' . $orderTimeout . ' minutes'));
            $sortOrder = $this->sortOrderBuilder->setField('entity_id')->setDirection('DESC')->create();

            if ($this->isCancelPendingOrderAgeEnabled === true
                && $orderAge !== null
                && $orderAge > $orderTimeout)
            {
                $this->debug->log("Cronjob: PendingOrderAge Enabled.");
                $pendingOrderAgeCheck = date('Y-m-d H:i:s', strtotime('-' . $orderAge . ' minutes'));

                $searchCriteria = $this->searchCriteriaBuilder
                    ->addFilter(
                        'updated_at',
                        $dateTimeCheck,
                        'lt'
                    )->addFilter(
                        'updated_at',
                        $pendingOrderAgeCheck,
                        'gt'
                    )->addFilter(
                        'status',
                        $orderStatus,
                        'eq'
                    )->setSortOrders(
                        [$sortOrder]
                    )->create();
            }
            else
            {
                if($this->isCancelPendingOrderAgeEnabled === true
                    && $orderAge !== null
                    && $orderAge <= $orderTimeout)
                {
                    $this->debug->log("Cronjob: Pending order age is less than or equal to timeout." . " age: " . $orderAge . ", timeout: " . $orderTimeout);
                }
                $searchCriteria = $this->searchCriteriaBuilder
                    ->addFilter(
                        'updated_at',
                        $dateTimeCheck,
                        'lt'
                    )->addFilter(
                        'status',
                        $orderStatus,
                        'eq'
                    )->setSortOrders(
                        [$sortOrder]
                    )->create();
            }
        }
        else if ($cronName === self::RESET_CART_CRON
                 && $orderState !== null)
        {
            $dateTimeCheck = date('Y-m-d H:i:s', strtotime('-' . $orderTimeout . ' minutes'));
            $sortOrder = $this->sortOrderBuilder->setField('entity_id')->setDirection('DESC')->create();

            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter(
                    'updated_at',
                    $dateTimeCheck,
                    'lt'
                )->addFilter(
                    'state',
                    $orderState,
                    'eq'
                )->addFilter(
                    'status',
                    $orderStatus,
                    'eq'
                )->setSortOrders(
                    [$sortOrder]
                )->create();
        }
        return $searchCriteria;
    }
}
