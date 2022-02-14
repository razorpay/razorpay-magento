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

    /**
     * @var STATUS_PROCESSING
     */
    protected const STATUS_PROCESSING   = 'processing';
    protected const STATUS_PENDING      = 'pending';


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
    }

    /**
     * Processes the incoming webhook
     */
    public function execute()
    {
        $this->logger->info("Razorpay Webhook processing started.");
        
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
                    header('Status: 400 Signature Verification failed', true, 400);
                    exit;
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

                    if (($order->getStatus() === static::STATUS_PENDING) and
                        (empty($payment->getLastTransId()) === true) )
                    {
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

                        $order->setState(static::STATUS_PROCESSING)->setStatus(static::STATUS_PROCESSING);

                        $order->addStatusHistoryComment(
                            __(
                                'Actual Amount %1 of %2, with Razorpay Offer/Fee applied.',
                                "Authroized",
                                $order->getBaseCurrency()->formatTxt($amountPaid)
                            )
                        );

                        $order->save();
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
                    if (in_array($order->getStatus(), [static::STATUS_PENDING, static::STATUS_PROCESSING]))
                    {
                        $payment = $order->getPayment();

                        $amountPaid = number_format($rzpOrderAmount / 100, 2, ".", "");

                        if ($order->getStatus() === static::STATUS_PENDING)
                        {
                            $order->setState(static::STATUS_PROCESSING)->setStatus(static::STATUS_PROCESSING);
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
                            catch (\Magento\Framework\Exception\MailException $exception)
                            {
                                $this->logger->critical($e);
                            }
                            catch (\Exception $e)
                            {
                                $this->logger->critical($e);
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
}
