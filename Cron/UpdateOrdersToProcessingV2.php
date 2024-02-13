<?php
namespace Razorpay\Magento\Cron;

if(class_exists('Razorpay\\Api\\Api')  === false)
{
   // require in case of zip installation without composer
    require_once __DIR__ . "/../../Razorpay/Razorpay.php"; 
}

use Razorpay\Api\Api;
use Razorpay\Api\Errors;
use Razorpay\Magento\Model\Config;
use Magento\Sales\Model\Order\Payment\State\CaptureCommand;
use Magento\Sales\Model\Order\Payment\State\AuthorizeCommand;
use \Magento\Sales\Model\Order;
use Razorpay\Magento\Constants\OrderCronStatus;

class UpdateOrdersToProcessingV2 {
    /**
     * @var Razorpay\Api\Api
     */
    protected $api;

    /**
     * @var \Magento\Framework\DB\Transaction
     */
    protected $transaction;

    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $checkoutSession;

    /**
     * @var \Magento\Sales\Model\Service\InvoiceService
     */
    protected $invoiceService;

    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\InvoiceSender
     */
    protected $invoiceSender;

    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\OrderSender
     */
    protected $orderSender;

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

    protected $enableCustomPaidOrderStatus;

    protected $orderStatus;

    /**
     * @var STATUS_PROCESSING
     */
    protected const STATUS_PROCESSING   = 'processing';
    protected const STATUS_PENDING      = 'pending';
    protected const STATUS_CANCELED     = 'canceled';
    protected const STATE_NEW           = 'new';
    protected const PAYMENT_AUTHORIZED  = 'payment.authorized';
    protected const ORDER_PAID          = 'order.paid';

    protected const PROCESS_ORDER_WAIT_TIME = 5 * 60;

    /**
     * @var \Razorpay\Magento\Model\Config
     */
    protected $config;

    /**
     * @var \Magento\Framework\Api\SortOrderBuilder
     */
    protected $sortOrderBuilder;
    
    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var \Magento\Sales\Model\Order\Payment\State\AuthorizeCommand
     */
    protected $authorizeCommand;

    /**
     * @var \Magento\Sales\Model\Order\Payment\State\CaptureCommand
     */
    protected $captureCommand;

    /**
     * @var \Razorpay\Magento\Model\Util\DebugMode
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
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Sales\Api\OrderManagementInterface $orderManagement,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Framework\DB\Transaction $transaction,
        \Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
        \Razorpay\Magento\Model\Config $config,
        \Psr\Log\LoggerInterface $logger,
        \Razorpay\Magento\Model\Util\DebugMode $debug
    )
    {
        $this->config                   = $config;
        $keyId                          = $this->config->getConfigData(Config::KEY_PUBLIC_KEY);
        $keySecret                      = $this->config->getConfigData(Config::KEY_PRIVATE_KEY);
        $this->api                      = new Api($keyId, $keySecret);
        $this->orderRepository          = $orderRepository;
        $this->searchCriteriaBuilder    = $searchCriteriaBuilder;
        $this->sortOrderBuilder         = $sortOrderBuilder;
        $this->transaction              = $transaction;
        $this->checkoutSession          = $checkoutSession;
        $this->invoiceService           = $invoiceService;
        $this->invoiceSender            = $invoiceSender;
        $this->orderSender              = $orderSender;
        $this->logger                   = $logger;
        $this->orderStatus              = static::STATUS_PROCESSING;
        $this->debug                    = $debug;

        $this->enableCustomPaidOrderStatus = $this->config->isCustomPaidOrderStatusEnabled();

        if ($this->enableCustomPaidOrderStatus === true
            && empty($this->config->getCustomPaidOrderStatus()) === false)
        {
            $this->orderStatus = $this->config->getCustomPaidOrderStatus();
        }

        $this->authorizeCommand = new AuthorizeCommand();
        $this->captureCommand = new CaptureCommand();
    }

    public function execute()
    {
        $this->logger->info("Cronjob: Update Orders To Processing Cron V2 started.");

        $dateTimeCheck = time() - static::PROCESS_ORDER_WAIT_TIME; 
        
        $objectManagement = $this->getObjectManager();
        
        $orderLinkCollection = $objectManagement->get('Razorpay\Magento\Model\OrderLink')
                                                        ->getCollection()
                                                        ->addFieldToFilter('rzp_update_order_cron_status',["lt" => 3])
                                                        ->addFieldToFilter('rzp_webhook_notified_at',["notnull" => true])
                                                        ->addFieldToFilter('rzp_webhook_notified_at',["lt" => $dateTimeCheck])
                                                        ->setOrder('entity_id')
                                                        ->setPageSize(5);
        
        $orderLink = $orderLinkCollection->getData();
        
        if (count($orderLink) > 0)
        {
            foreach ($orderLink as $orderData)
            {
                $this->debug->log("Cronjob: Magento Order Id = " . $orderData['order_id'] . " picked for updation");

                $order = $this->orderRepository->get($orderData['order_id']);
                $singleOrderLinkCollection = $objectManagement->get('Razorpay\Magento\Model\OrderLink')
                                                ->getCollection()
                                                ->addFilter('order_id', $order->getEntityId())
                                                ->getFirstItem();
                
                if ((empty($order) === false) and (
                    $order->getPayment()->getMethod() === 'razorpay') and 
                    ($order->getState() === static::STATUS_PROCESSING or 
                    $order->getState() === static::STATE_NEW)) 
                { 
                    $rzpWebhookData = $orderData['rzp_webhook_data'];
                    if (empty($rzpWebhookData) === false) // check if webhook cron has run and populated the rzp_webhook_data column
                    {
                        $this->debug->log("Cronjob: Webhook data present for Magento Order Id = " . $orderData['order_id']);

                        $rzpWebhookDataObj = unserialize($rzpWebhookData); // nosemgrep
                        
                        if (isset($rzpWebhookDataObj[static::ORDER_PAID]) === true)
                        {
                            $this->updateOrderStatus($order, static::ORDER_PAID, $rzpWebhookDataObj[static::ORDER_PAID], $singleOrderLinkCollection);
                        }

                        if (isset($rzpWebhookDataObj[static::PAYMENT_AUTHORIZED]) === true and
                            $singleOrderLinkCollection->getRzpUpdateOrderCronStatus() < OrderCronStatus::INVOICE_GENERATED)
                        {
                            if ($order->getState() === static::STATUS_PROCESSING and
                                $singleOrderLinkCollection->getRzpUpdateOrderCronStatus() == OrderCronStatus::PAYMENT_AUTHORIZED_COMPLETED)
                            {
                                $this->logger->info('Payment Authorized cron repeated for id: ' . $order->getIncrementId());
                                $singleOrderLinkCollection->setRzpUpdateOrderCronStatus(OrderCronStatus::PAYMENT_AUTHORIZED_CRON_REPEAT);
                                $singleOrderLinkCollection->save();
                            }
                            else{
                                $this->updateOrderStatus($order, static::PAYMENT_AUTHORIZED, $rzpWebhookDataObj[static::PAYMENT_AUTHORIZED], $singleOrderLinkCollection);
                            }
                        }
                    }
                    else
                    {
                        $this->logger->info('Razorpay Webhook code not triggered yet. \'rzp_webhook_data\' is empty for id:' . $order->getEntityId());
                    }   
                }
            }
        }
        
    }

    private function updateOrderStatus($order, $event, $rzpWebhookData, $orderLinkCollection)
    {
        $this->logger->info("Cronjob: Updating to Processing for Order ID: " 
                        . $order->getIncrementId() 
                        . " and Event :" 
                        . $event
                        . " started."
                    );

        $payment        = $order->getPayment();
        $paymentId      = $rzpWebhookData['payment_id'];
        $rzpOrderAmount = $rzpWebhookData['amount'];

        $payment->setLastTransId($paymentId)
                ->setTransactionId($paymentId)
                ->setIsTransactionClosed(true)
                ->setShouldCloseParentTransaction(true);

        $payment->setParentTransactionId($payment->getTransactionId());

        if ($event === static::PAYMENT_AUTHORIZED)
        {
            $payment->addTransactionCommentsToOrder(
                "$paymentId",
                $this->authorizeCommand->execute(
                    $payment,
                    $order->getGrandTotal(),
                    $order
                ),
                ""
            );
        }
        else if ($event === static::ORDER_PAID)
        {
            $payment->addTransactionCommentsToOrder(
                "$paymentId",
                $this->captureCommand->execute(
                    $payment,
                    $order->getGrandTotal(),
                    $order
                ),
                ""
            );
        }

        $transaction = $payment->addTransaction(\Magento\Sales\Model\Order\Payment\Transaction::TYPE_AUTH, null, true, "");

        $transaction->setIsClosed(true);

        $transaction->save();

        $amountPaid = number_format($rzpOrderAmount / 100, 2, ".", "");

        $order->setState(static::STATUS_PROCESSING)->setStatus($this->orderStatus);

        if ($event === static::PAYMENT_AUTHORIZED)
        {
            $order->addStatusHistoryComment(
                __(
                    'Actual Amount %1 of %2, with Razorpay Offer/Fee applied.',
                    "Authorized",
                    $order->getBaseCurrency()->formatTxt($amountPaid)
                )
            );
        }
        else if ($event === static::ORDER_PAID)
        {
            $order->addStatusHistoryComment(
                __(
                    '%1 amount of %2 online, with Razorpay Offer/Fee applied.',
                    "Captured",
                    $order->getBaseCurrency()->formatTxt($amountPaid)
                )
            );
        }

        $orderLinkCollection->setRzpUpdateOrderCronStatus(OrderCronStatus::PAYMENT_AUTHORIZED_COMPLETED);
        $this->logger->info('Payment authorized completed for id : '. $order->getIncrementId());

        if ($event === static::ORDER_PAID)
        {
            if ($order->canInvoice() && $this->config->canAutoGenerateInvoice())
            {
                $invoice = $this->invoiceService->prepareInvoice($order);
                $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                $invoice->setTransactionId($paymentId);
                $invoice->register();
                $invoice->save();

                $transactionSave = $this->transaction
                                        ->addObject($invoice)
                                        ->addObject($invoice->getOrder());
                $transactionSave->save();

                $this->invoiceSender->send($invoice);

                //send notification code
                $order->setState(static::STATUS_PROCESSING)->setStatus($this->orderStatus);

                $order->addStatusHistoryComment(
                            __('Notified customer about invoice #%1.', $invoice->getId())
                        )->setIsCustomerNotified(true);
                
                $orderLinkCollection->setRzpUpdateOrderCronStatus(OrderCronStatus::INVOICE_GENERATED);
                $this->logger->info('Invoice generated for id : '. $order->getIncrementId());
            }
            else
            {
                $orderLinkCollection->setRzpUpdateOrderCronStatus(OrderCronStatus::INVOICE_GENERATION_NOT_POSSIBLE);
                $this->logger->info('Invoice generation not possible for id : '. $order->getIncrementId());
            }
        }

        $order->save();

        $orderLinkCollection->setRzpPaymentId($paymentId);
        $orderLinkCollection->save();

        //send Order email, after successfull payment
        try
        {
            $this->checkoutSession->setRazorpayMailSentOnSuccess(true);
            $this->orderSender->send($order);
            $this->checkoutSession->unsRazorpayMailSentOnSuccess();
        }
        catch (\Magento\Framework\Exception\MailException $e)
        {
            $this->logger->critical($e);
        }
        catch (\Exception $e)
        {
            $this->logger->critical($e);
        }

        $this->logger->info("Cronjob: Updating to Processing for Order ID: " 
                            . $order->getIncrementId() 
                            . " and Event :" 
                            . $event
                            . " ended."
                        );   
    }

    // @codeCoverageIgnoreStart
    function getObjectManager()
    {
        return \Magento\Framework\App\ObjectManager::getInstance();
    }
    // @codeCoverageIgnoreEnd
}
