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

    protected $pendingOrderAge;

    protected $isMagicEnabled;

    protected const PENDING_ORDER_MAXIMUM_AGE_DEFAULT = 43200;

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
        $this->pendingOrderTimeout             = 1;
        $this->pendingOrderAge                 = (($this->config->getPendingOrderAge() > 0) && ($this->config->getPendingOrderAge() < self::PENDING_ORDER_MAXIMUM_AGE_DEFAULT)) ? $this->config->getPendingOrderAge() : self::PENDING_ORDER_MAXIMUM_AGE_DEFAULT;
        $this->isCancelResetCartCronEnabled    = $this->config->isCancelResetCartOrderCronEnabled();
        $this->resetCartOrderTimeout           = ($this->config->getResetCartOrderTimeout() > 0) ? $this->config->getResetCartOrderTimeout() : 30;
        $this->debug                           = $debug;
        $this->isMagicEnabled                  = (bool)$this->config->getMagicStatus();
    }

    public function execute()
    {
        // Execute only if Cancel Pending Order Cron is Enabled
        if ($this->isCancelPendingOrderCronEnabled === true
            && $this->pendingOrderTimeout > 0)
        {
            $this->logger->info("Cronjob: Cancel Pending Order Cron started.");

            $searchCriteria = $this->getSearchCriteria(self::PENDING_ORDER_CRON, $this->pendingOrderTimeout, $this->pendingOrderAge, null, self::STATUS_PENDING);

            // searchCriteria is null when orderAge <= orderTimeout — log and bail early
            if ($searchCriteria === null)
            {
                $this->logger->debug("Cronjob: Cancel Pending Order Cron - searchCriteria is null, orderAge=" . $this->pendingOrderAge . " orderTimeout=" . $this->pendingOrderTimeout);
                return;
            }

            $orders = $this->orderRepository->getList($searchCriteria);

            // Log total count so merchant can confirm orders are being fetched
            $this->logger->debug("Cronjob: Cancel Pending Order Cron - total orders found: " . $orders->getTotalCount());

            $this->processOrders($orders);
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

            $this->processOrders($orders);
        } else
        {
            $this->logger->critical('Cronjob: isCancelResetCartCronEnabled:'
             . $this->isCancelResetCartCronEnabled . ', '
            . 'resetCartOrderTimeout:' . $this->resetCartOrderTimeout);
        }
    }

    // Iterates orders and triggers cancellation for razorpay payment orders only.
    // Extracted to avoid duplicating this loop across both cron types.
    private function processOrders($orders)
    {
        foreach ($orders->getItems() as $order)
        {
            if ($order->getPayment()->getMethod() === 'razorpay')
            {
                // Order has razorpay payment — proceed to cancellation check
                $this->logger->debug("Cronjob: Order ID " . $order->getIncrementId() . " picked for cancellation check.");
                $this->cancelOrder($order);
            }
            else
            {
                // Non-razorpay orders are skipped — logged to help diagnose missing cancellations
                $this->logger->debug("Cronjob: Order ID " . $order->getIncrementId() . " skipped - payment method: " . $order->getPayment()->getMethod());
            }
        }
    }

    private function cancelOrder($order)
    {
        if ($order)
        {
            // Order state/status may not allow cancellation (e.g. already processing)
            if ($order->canCancel() === false)
            {
                $this->logger->debug("Cronjob: Order ID " . $order->getIncrementId() . " skipped - canCancel() returned false, state: " . $order->getState() . " status: " . $order->getStatus());
                return;
            }

            // Skip cancellation if webhook has already notified payment success
            if ($this->isOrderAlreadyPaid($order) === true)
            {
                $this->logger->debug("Cronjob: Order ID " . $order->getIncrementId() . " skipped - isOrderAlreadyPaid returned true.");
                return;
            }

            $this->logger->debug("Cronjob: Cancelling Order ID: " . $order->getIncrementId());

            $order->cancel()
            ->setState(
                Order::STATE_CANCELED,
                Order::STATE_CANCELED,
                'Payment Failed',
                false
            )->save();
        }
    }

    private function isOrderAlreadyPaid($order)
    {
        // Magic checkout stores order_id as increment_id in razorpay_sales_order.
        // Standard checkout stores order_id as entity_id.
        // Query primary ID first based on config, then fall back to the other.
        $primaryId  = $this->isMagicEnabled ? $order->getIncrementId() : $order->getEntityId();
        $fallbackId = $this->isMagicEnabled ? $order->getEntityId()    : $order->getIncrementId();

        $this->logger->debug("Cronjob: isOrderAlreadyPaid - Order ID " . $order->getIncrementId() . " querying by primary ID: " . $primaryId);

        $orderLinkData = $this->getOrderLinkByOrderId($primaryId);

        // Primary lookup missed — try fallback ID
        if (empty($orderLinkData->getId()))
        {
            $this->logger->debug("Cronjob: isOrderAlreadyPaid - Order ID " . $order->getIncrementId() . " primary lookup missed, trying fallback ID: " . $fallbackId);

            $orderLinkData = $this->getOrderLinkByOrderId($fallbackId);
        }

        // No orderLink record found for this order — skip cancellation to be safe
        if (empty($orderLinkData->getId()))
        {
            $this->logger->debug("Cronjob: isOrderAlreadyPaid - Order ID " . $order->getIncrementId() . " no orderLink record found, skipping cancellation.");
            return true;
        }

        $isPaid = ($orderLinkData->getRzpWebhookNotifiedAt() !== null);

        $this->logger->debug("Cronjob: isOrderAlreadyPaid - Order ID " . $order->getIncrementId() . " rzp_webhook_notified_at: " . ($orderLinkData->getRzpWebhookNotifiedAt() ?? 'null') . ", isPaid: " . ($isPaid ? 'true' : 'false'));

        return $isPaid;
    }

    // Queries razorpay_sales_order by a given order_id value.
    // Extracted to avoid repeating the collection query pattern for primary/fallback lookups.
    private function getOrderLinkByOrderId($orderId)
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

        return $objectManager->get('Razorpay\Magento\Model\OrderLink')
                ->getCollection()
                ->addFilter('order_id', $orderId)
                ->getFirstItem();
    }

    private function getSearchCriteria($cronName, $orderTimeout, $orderAge, $orderState, $orderStatus)
    {
        $searchCriteria = null;
        if ($cronName === self::PENDING_ORDER_CRON)
        {
            $dateTimeCheck = date('Y-m-d H:i:s', strtotime('-' . $orderTimeout . ' minutes'));
            $sortOrder = $this->sortOrderBuilder->setField('entity_id')->setDirection('DESC')->create();

            if (($orderAge !== null) && ($orderAge > $orderTimeout))
            {
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
