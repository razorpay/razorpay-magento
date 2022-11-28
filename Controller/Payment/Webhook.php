<?php

namespace Razorpay\Magento\Controller\Payment;

use Razorpay\Api\Api;
use Razorpay\Api\Errors;
use Razorpay\Magento\Model\Config;
use Razorpay\Magento\Model\PaymentMethod;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Controller\ResultFactory;
use Magento\Sales\Model\Order\Payment\State\CaptureCommand;
use Magento\Sales\Model\Order\Payment\State\AuthorizeCommand;

/**
 * Webhook controller to handle Razorpay order webhook
 *
 * ...
 */
class Webhook extends \Razorpay\Magento\Controller\BaseController
{
    /**
     * @var Razorpay\Api\Api
     */
    protected $api;

    /**
     * @var \Magento\Sales\Api\Data\OrderInterface
     */
    protected $order;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var \Magento\Framework\App\ObjectManager
     */
    protected $objectManagement;

    /**
     * @var \Magento\Sales\Model\Service\InvoiceService
     */
    protected $invoiceService;

    /**
     * @var \Magento\Framework\DB\Transaction
     */
    protected $transaction;

    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\InvoiceSender
     */
    protected $invoiceSender;

    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\OrderSender
     */
    protected $orderSender;

    protected $enableCustomPaidOrderStatus;

    protected $orderStatus;

    /**
     * @var STATUS_PROCESSING
     */
    protected const STATUS_PROCESSING   = 'processing';
    protected const STATUS_PENDING      = 'pending';
    protected const STATUS_CANCELED     = 'canceled';
    protected const STATE_NEW           = 'new';

    /**
     * @var HTTP CONFLICT Request
     */
    protected const HTTP_CONFLICT_STATUS = 409;

    /**
     * @var Webhook Notify Wait Time
     */
    protected const WEBHOOK_NOTIFY_WAIT_TIME = (5 * 60);

    /**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Razorpay\Magento\Model\Config $config
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Sales\Model\Service\InvoiceService $invoiceService
     * @param \Magento\Framework\DB\Transaction $transaction
     * @param \Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender
     * @param \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Razorpay\Magento\Model\Config $config,
        \Magento\Sales\Api\Data\OrderInterface $order,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Framework\DB\Transaction $transaction,
        \Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender
    ) {
        parent::__construct(
            $context,
            $customerSession,
            $checkoutSession,
            $config
        );

        $keyId                    = $this->config->getConfigData(Config::KEY_PUBLIC_KEY);
        $keySecret                = $this->config->getConfigData(Config::KEY_PRIVATE_KEY);
        $this->api                = new Api($keyId, $keySecret);
        $this->order              = $order;
        $this->logger             = $logger;
        $this->objectManagement   = \Magento\Framework\App\ObjectManager::getInstance();
        $this->invoiceService     = $invoiceService;
        $this->transaction        = $transaction;
        $this->invoiceSender      = $invoiceSender;
        $this->orderSender        = $orderSender;
        $this->orderStatus        = static::STATUS_PROCESSING;

        $this->enableCustomPaidOrderStatus = $this->config->isCustomPaidOrderStatusEnabled();

        if ($this->enableCustomPaidOrderStatus === true
            && empty($this->config->getCustomPaidOrderStatus()) === false)
        {
            $this->orderStatus = $this->config->getCustomPaidOrderStatus();
        }
    }

    /**
     * Processes the incoming webhook
     */
    public function execute()
    {
        $this->logger->info("Razorpay Webhook processing started.");
        
        $this->config->setConfigData('webhook_triggered_at', time());

        $post = $this->getPostData();

        if (json_last_error() !== 0)
        {
            return;
        }

        $razorpaySignature = isset($_SERVER['HTTP_X_RAZORPAY_SIGNATURE']) ? $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] : '';

        if (($this->config->isWebhookEnabled() === true) &&
            (isset($post['event']) && empty($post['event']) === false))
        {
            if (!empty($razorpaySignature) === true)
            {
                $webhookSecret = $this->config->getWebhookSecret();
                // To accept webhooks, the merchant must configure it on the magento backend by setting the secret.
                if (empty($webhookSecret) === true)
                {
                    return;
                }

                try
                {
                    $postData = file_get_contents('php://input');

                    $this->rzp->utility->verifyWebhookSignature(
                        $postData,
                        $razorpaySignature,
                        $webhookSecret
                    );
                }
                catch (Errors\SignatureVerificationError $e)
                {
                    $this->logger->critical(
                        $e->getMessage(),
                        [
                            'data'  => $post,
                            'event' => 'razorpay.magento.signature.verify_failed'
                        ]
                    );
                    header('Status: 400 Signature Verification failed', true, 400); // nosemgrep
                    exit;
                }

                if (isset($post['payload']['payment']['entity']['notes']['merchant_order_id']) === true)
                {
                    $orderId            = $post['payload']['payment']['entity']['notes']['merchant_order_id'];
                    $paymentId          = $post['payload']['payment']['entity']['id'];
                    $orderWebhookData   = $this->getOrderWebhookData($orderId);
                    $amountPaid         = $post['payload']['payment']['entity']['amount'];

                    $this->setWebhookData($post, $orderWebhookData['entity_id'], true, $paymentId, $amountPaid);

                    if (empty($orderWebhookData['rzp_webhook_notified_at']) === true)
                    {
                        $this->setWebhookNotifiedAt($orderWebhookData['entity_id']);

                        $this->logger->info("Razorpay Webhook: Updated WebhookNotifiedAt.");

                        $this->setWebhookData($post, $orderWebhookData['entity_id'], true, $paymentId, $amountPaid);

                        header('Status: ' . static::HTTP_CONFLICT_STATUS . ' Webhook conflicts due to early execution.', true, static::HTTP_CONFLICT_STATUS); // nosemgrep
                        exit;
                    }
                    elseif (empty($orderWebhookData['rzp_webhook_notified_at']) === false and
                          ((time() - $orderWebhookData['rzp_webhook_notified_at']) < static::WEBHOOK_NOTIFY_WAIT_TIME)
                    )
                    {
                        $this->logger->critical("Razorpay Webhook: Webhook conflicts due to early execution for OrderID: " . $orderId);

                        $this->setWebhookData($post, $orderWebhookData['entity_id'], true, $paymentId, $amountPaid);

                        header('Status: ' . static::HTTP_CONFLICT_STATUS . ' Webhook conflicts due to early execution.', true, static::HTTP_CONFLICT_STATUS); // nosemgrep
                        exit;
                    }
                }

                switch ($post['event'])
                {
                    case 'payment.authorized':
                        return $this->authorize($post);
                    case 'order.paid':
                        $this->orderPaid($post);
                    default:
                        return;
                }
            }
        }
        $this->logger->info("Razorpay Webhook processing completed.");
    }

    /**
     * Payment Authorized
     *
     * @param array $post
     */
    protected function authorize(array $post)
    {
        $this->logger->info("Razorpay Webhook Event(" . $post['event'] . ") processing Started.");

        $paymentId      = $post['payload']['payment']['entity']['id'];
        $rzpOrderId     = $post['payload']['payment']['entity']['order_id'];
        $amountPaid     = $post['payload']['payment']['entity']['amount'];

        try
        {
            $rzpOrder       = $this->getRzpOrder($rzpOrderId);
            $orderId        = $rzpOrder->receipt;
            $rzpOrderAmount = $rzpOrder->amount;

            if (isset($orderId) === false)
            {
                $this->logger->info("Razorpay Webhook: Quote ID not set for Razorpay payment_id(:$paymentId)");
                return;
            }
        }
        catch (\Razorpay\Api\Errors\Error $e)
        {
            $this->logger->critical("Razorpay Webhook: fetching RZP order data(id:$rzpOrderId) "
                                . "PaymentId:(:$paymentId) failed with error: ". $e->getMessage());
            return;
        }catch (\Exception $e)
        {
            $this->logger->critical("Razorpay Webhook: fetching RZP order data(id:$rzpOrderId) "
                                . "PaymentId:(:$paymentId) failed with error: ". $e->getMessage());
            return;
        }

        try
        {
            # fetch the related sales order and verify the payment ID with rzp payment id
            # To avoid duplicate order entry for same quote
            $collection = $this->objectManagement->get('Magento\Sales\Model\Order')
                                                 ->getCollection()
                                                 ->addFieldToSelect('entity_id')
                                                 ->addFilter('increment_id', $orderId)
                                                 ->getFirstItem();

            $salesOrder = $collection->getData();

            if (isset($salesOrder['entity_id']) && empty($salesOrder['entity_id']) === false)
            {
                $this->logger->info("Razorpay inside order already processed with webhook orderID:" . $orderId
                                    ." and OrderID:".$salesOrder['entity_id']);

                $order = $this->order->load($salesOrder['entity_id']);

                if ($order)
                {
                    $payment = $order->getPayment();

                    if ($order->getState() === static::STATE_NEW and
                        ($order->getStatus() === static::STATUS_CANCELED or
                        $order->getStatus() === static::STATUS_PENDING)
                    )
                    {

                        $this->logger->info("Razorpay Webhook: "
                                            . " Event: " . $post['event']
                                            . ", State: " . $order->getState()
                                            . ", Status: " . $order->getStatus());

                        $payment->setLastTransId($paymentId)
                                ->setTransactionId($paymentId)
                                ->setIsTransactionClosed(true)
                                ->setShouldCloseParentTransaction(true);

                        $payment->setParentTransactionId($payment->getTransactionId());

                        $payment->addTransactionCommentsToOrder(
                            "$paymentId",
                            (new AuthorizeCommand())->execute(
                                $payment,
                                $order->getGrandTotal(),
                                $order
                            ),
                            ""
                        );

                        $transaction = $payment->addTransaction(\Magento\Sales\Model\Order\Payment\Transaction::TYPE_AUTH, null, true, "");

                        $transaction->setIsClosed(true);

                        $transaction->save();

                        $amountPaid = number_format($rzpOrderAmount / 100, 2, ".", "");

                        $order->setState(static::STATUS_PROCESSING)->setStatus($this->orderStatus);

                        $order->addStatusHistoryComment(
                            __(
                                'Actual Amount %1 of %2, with Razorpay Offer/Fee applied.',
                                "Authorized",
                                $order->getBaseCurrency()->formatTxt($amountPaid)
                            )
                        );

                        $order->save();

                        //update/disable the quote
                        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                        $quote = $objectManager->get('Magento\Quote\Model\Quote')->load($order->getQuoteId());
                        $quote->setIsActive(false)->save();
                    }
                    else
                    {
                        $this->logger->info("Razorpay Webhook: Sales Order and payment "
                            . "already exist for Razorpay payment_id(:$paymentId)");
                        return;
                    }
                }
            }
        }
        catch (\Exception $e)
        {
            $this->logger->critical("Razorpay Webhook payment.authorized exeption, orderID:" . $orderId
                                    ." PaymentId: " . $paymentId
                                    ." and Message:" . $e->getMessage());
        }
    }

    /**
     * Order Paid
     *
     * @param array $post
     */
    protected function orderPaid(array $post)
    {
        $this->logger->info("Razorpay Webhook Event(" . $post['event'] . ")  processing Started.");

        $paymentId      = $post['payload']['payment']['entity']['id'];
        $amountPaid     = $post['payload']['order']['entity']['amount_paid'];
        $rzpOrderAmount = $post['payload']['order']['entity']['amount'];
        $orderId        = $post['payload']['order']['entity']['receipt'];

        if (isset($orderId) === false)
        {
            $this->logger->info("Razorpay Webhook: Order ID not set for Razorpay payment_id(:$paymentId)");
            return;
        }

        try
        {
            $collection = $this->objectManagement->get('Magento\Sales\Model\Order')
                                                 ->getCollection()
                                                 ->addFieldToSelect('entity_id')
                                                 ->addFilter('increment_id', $orderId)
                                                 ->getFirstItem();

            $salesOrder = $collection->getData();

            if (isset($salesOrder['entity_id']) && empty($salesOrder['entity_id']) === false)
            {
                $this->logger->info("Razorpay inside order already processed with webhook orderID:" . $orderId
                                    ." and entity_id:".$salesOrder['entity_id']);

                $order = $this->order->load($salesOrder['entity_id']);

                if ($order)
                {
                    if (in_array($order->getStatus(), [static::STATUS_PENDING, $this->orderStatus]) or
                        ($order->getState() === static::STATE_NEW and
                         $order->getStatus() === static::STATUS_CANCELED)
                    )
                    {
                        $this->logger->info("Razorpay Webhook: "
                                            . " Event: " . $post['event']
                                            . ", State: " . $order->getState()
                                            . ", Status: " . $order->getStatus());

                        $payment = $order->getPayment();

                        $amountPaid = number_format($rzpOrderAmount / 100, 2, ".", "");

                        if ($order->getState() === static::STATE_NEW and
                            ($order->getStatus() === static::STATUS_CANCELED or
                            $order->getStatus() === static::STATUS_PENDING)
                        )
                        {
                            $order->setState(static::STATUS_PROCESSING)->setStatus($this->orderStatus);
                        }

                        $payment = $order->getPayment();

                        $payment->setLastTransId($paymentId)
                                ->setTransactionId($paymentId)
                                ->setIsTransactionClosed(true)
                                ->setShouldCloseParentTransaction(true);

                        $payment->setParentTransactionId($payment->getTransactionId());

                        $payment->addTransactionCommentsToOrder(
                            "$paymentId",
                            (new CaptureCommand())->execute(
                                $payment,
                                $order->getGrandTotal(),
                                $order
                            ),
                            ""
                        );

                        $transaction = $payment->addTransaction(\Magento\Sales\Model\Order\Payment\Transaction::TYPE_AUTH, null, true, "");

                        $transaction->setIsClosed(true);

                        $transaction->save();

                        $order->addStatusHistoryComment(
                            __(
                                '%1 amount of %2 online, with Razorpay Offer/Fee applied.',
                                "Captured",
                                $order->getBaseCurrency()->formatTxt($amountPaid)
                            )
                        );

                        //update/disable the quote
                        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                        $quote = $objectManager->get('Magento\Quote\Model\Quote')->load($order->getQuoteId());
                        $quote->setIsActive(false)->save();

                        if ($order->canInvoice() && $this->config->canAutoGenerateInvoice())
                        {
                            $invoice = $this->invoiceService->prepareInvoice($order);
                            $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                            $invoice->setTransactionId($paymentId);
                            $invoice->register();
                            $invoice->save();

                            $transactionSave = $this->transaction
                              ->addObject($invoice)
                              ->addObject($invoice
                              ->getOrder());
                            $transactionSave->save();

                            $this->invoiceSender->send($invoice);

                            //send notification code
                            $order->setState(static::STATUS_PROCESSING)->setStatus($this->orderStatus);

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
                                $this->logger->critical($e->getMessage());
                            }
                            catch (\Exception $e)
                            {
                                $this->logger->critical($e->getMessage());
                            }
                        }

                        $order->save();
                    }
                }
            }
        }
        catch (\Exception $e)
        {
            $this->logger->critical("Razorpay Webhook order.paid exeption, orderID:" . $orderId
                                    ." PaymentId: " . $paymentId
                                    ." and Message:" . $e->getMessage());
        }
    }

    /**
     * Get the Order from RZP
     *
     * @param string $orderId
     */
    public function getRzpOrder($orderId)
    {
        try
        {
            $order = $this->api->order->fetch($orderId);
            return $order;
        }
        catch (\Razorpay\Api\Errors\Error $e)
        {
            $this->logger->critical("Razorpay Webhook: fetching RZP order "
                . "data(id:$orderId) failed with error: ". $e->getMessage());
            return;
        }
        catch (\Exception $e)
        {
            $this->logger->critical("Razorpay Webhook: fetching RZP order "
                . "data(id:$orderId) failed with error: ". $e->getMessage());
            return;
        }
    }

    /**
     * Get Webhook post data as an array
     *
     * @return Webhook post data as an array
     */
    protected function getPostData() : array
    {
        $request = file_get_contents('php://input');

        if (!isset($request) || empty($request))
        {
            $request = "{}";
        }

        return json_decode($request, true);
    }

    protected function getOrderWebhookData($orderId) : array
    {
        $collection = $this->objectManagement->get('Magento\Sales\Model\Order')
                           ->getCollection()
                           ->addFieldToSelect('entity_id')
                           ->addFieldToSelect('rzp_webhook_notified_at')
                           ->addFilter('increment_id', $orderId)
                           ->getFirstItem();
        return $collection->getData();
    }

    protected function setWebhookNotifiedAt($entity_id)
    {
        $order = $this->order->load($entity_id);
        $order->setRzpWebhookNotifiedAt(time());
        $order->save();
    }

    protected function setWebhookData($post, $entityId, $webhookVerifiedStatus, $paymentId, $amount)
    {
        $order                  = $this->order->load($entityId);
        $existingWebhookData    = $order->getRzpWebhookData();

        if ($post['event'] === 'payment.authorized')
        {
            $amount = $post['payload']['payment']['entity']['amount'];
        }
        else if ($post['event'] === 'order.paid')
        {
            $amount = $post['payload']['order']['entity']['amount_paid'];
        }
        $webhookData = array(
            "webhook_verified_status"   => $webhookVerifiedStatus,
            "payment_id"                => $paymentId,
            "amount"                    => $amount 
        );

        if (!empty($existingWebhookData))
        {
            $existingWebhookData = unserialize($existingWebhookData); // nosemgrep
            
            if (!array_key_exists($post['event'], $existingWebhookData))
            {
                $existingWebhookData[$post['event']] = $webhookData;
            }

            $webhookDataText = serialize($existingWebhookData);
        }
        else
        {
            $eventArray         = [$post['event'] => $webhookData];
            $webhookDataText    = serialize($eventArray);
        }
        $order->setRzpWebhookData($webhookDataText);
        $order->save();
    }
}
