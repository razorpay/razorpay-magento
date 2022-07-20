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

class UpdateOrdersToProcessing {
    /**
     * @var Razorpay\Api\Api
     */
    protected $api;

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

    /**
     * @var STATUS_PROCESSING
     */
    protected const STATUS_PROCESSING   = 'processing';
    protected const STATUS_PENDING      = 'pending';
    protected const STATUS_CANCELED     = 'canceled';
    protected const STATE_NEW           = 'new';

    protected const PROCESS_ORDER_WAIT_TIME = 5;

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
        \Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
        \Razorpay\Magento\Model\Config $config,
        \Psr\Log\LoggerInterface $logger
    )
    {
        $this->config                   = $config;
        $keyId                          = $this->config->getConfigData(Config::KEY_PUBLIC_KEY);
        $keySecret                      = $this->config->getConfigData(Config::KEY_PRIVATE_KEY);
        $this->api                      = new Api($keyId, $keySecret);
        $this->orderRepository          = $orderRepository;
        $this->searchCriteriaBuilder    = $searchCriteriaBuilder;
        $this->sortOrderBuilder         = $sortOrderBuilder;
        $this->checkoutSession          = $checkoutSession;
        $this->invoiceService           = $invoiceService;
        $this->invoiceSender            = $invoiceSender;
        $this->orderSender              = $orderSender;
        $this->logger                   = $logger;
    }

    public function execute()
    {

        $this->logger->info("Cronjob: Update Orders To Processing Cron started.");

        $dateTimeCheck = date('Y-m-d H:i:s', strtotime('-' . static::PROCESS_ORDER_WAIT_TIME . ' minutes'));
        $sortOrder = $this->sortOrderBuilder->setField('entity_id')->setDirection('DESC')->create();

        $searchCriteria = $this->searchCriteriaBuilder
                            ->addFilter(
                                'updated_at',
                                $dateTimeCheck,
                                'lt')
                            ->addFilter(
                                'status',
                                static::STATUS_PENDING,
                                'eq'
                            )->setSortOrders(
                                [$sortOrder]
                            )->create();

        $orders = $this->orderRepository->getList($searchCriteria);

        // print_r($orders->getData());die;
        foreach ($orders->getItems() as $order)
        {
            if ($order->getPayment()->getMethod() === 'razorpay') {
                // var_dump($order->getRzpWebhookData());
                // var_dump( unserialize( $order->getRzpWebhookData() ) );
                // die;
                $rzpWebhookData = $order->getRzpWebhookData();
                if (empty($rzpWebhookData) === false) // check if webhook cron has run and populated the rzp_webhook_data column
                {
                    $rzpWebhookDataObj = unserialize($rzpWebhookData);

                    if($rzpWebhookDataObj['webhook_verified_status'] === true)
                    {
                        $this->updateOrderStatus($order, $rzpWebhookDataObj);
                    } 
                }   
            }
        }
    }

    private function updateOrderStatus($order, $rzpWebhookData)
    {
        if ($order)
        {
            $this->logger->info("Cronjob: Updating to Processing for Order ID: " 
                                . $order->getEntityId() 
                                . " and Event :" 
                                . $rzpWebhookData['event']);

            $payment = $order->getPayment();
            $paymentId = $rzpWebhookData['payment_id'];
            $rzpOrderAmount = $rzpWebhookData['amount'];
            $event = $rzpWebhookData['event'];

            $payment->setLastTransId($paymentId)
                    ->setTransactionId($paymentId)
                    ->setIsTransactionClosed(true)
                    ->setShouldCloseParentTransaction(true);

            $payment->setParentTransactionId($payment->getTransactionId());

            if ($event === 'payment.authorized')
            {
                $payment->addTransactionCommentsToOrder(
                    "$paymentId",
                    (new AuthorizeCommand())->execute(
                        $payment,
                        $order->getGrandTotal(),
                        $order
                    ),
                    ""
                );
            }
            else
            {
                $payment->addTransactionCommentsToOrder(
                    "$paymentId",
                    (new CaptureCommand())->execute(
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

            $order->setState(static::STATUS_PROCESSING)->setStatus(static::STATUS_PROCESSING);

            $order->addStatusHistoryComment(
                __(
                    'Actual Amount %1 of %2, with Razorpay Offer/Fee applied.',
                    "Authroized",
                    $order->getBaseCurrency()->formatTxt($amountPaid)
                )
            );

            //update/disable the quote
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $quote = $objectManager->get('Magento\Quote\Model\Quote')->load($order->getQuoteId());
            $quote->setIsActive(false)->save();

            if ($event === 'order.paid')
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
                    $order->setState(static::STATUS_PROCESSING)->setStatus(static::STATUS_PROCESSING);

                    $order->addStatusHistoryComment(
                                __('Notified customer about invoice #%1.', $invoice->getId())
                            )->setIsCustomerNotified(true);

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
                }
            }

            $order->save();
        }
    }

    // /**
    //  * Get the Order from RZP
    //  *
    //  * @param string $orderId
    //  */
    // public function getRzpOrder($orderId)
    // {
    //     try
    //     {
    //         $order = $this->api->order->fetch($orderId);
    //         return $order;
    //     }
    //     catch (\Razorpay\Api\Errors\Error $e)
    //     {
    //         $this->logger->critical("Razorpay Webhook: fetching RZP order "
    //             . "data(id:$orderId) failed with error: ". $e->getMessage());
    //         return;
    //     }
    //     catch (\Exception $e)
    //     {
    //         $this->logger->critical("Razorpay Webhook: fetching RZP order "
    //             . "data(id:$orderId) failed with error: ". $e->getMessage());
    //         return;
    //     }
    // }

    // protected function getOrderWebhookData($orderId) : array
    // {
    //     $collection = $this->objectManagement->get('Magento\Sales\Model\Order')
    //                        ->getCollection()
    //                        ->addFieldToSelect('entity_id')
    //                        ->addFieldToSelect('rzp_webhook_notified_at')
    //                        ->addFilter('increment_id', $orderId)
    //                        ->getFirstItem();
    //     return $collection->getData();
    // }
}
