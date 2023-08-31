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
     * @var UPDATE_ORDER_CRON_STATUS
     */
    protected const DEFAULT = 0;
    protected const PAYMENT_AUTHORIZED_COMPLETED = 1;
    protected const ORDER_PAID_AFTER_MANUAL_CAPTURE = 2;
    protected const INVOICE_GENERATED = 3;
    protected const INVOICE_GENERATION_NOT_POSSIBLE = 4;
    protected const PAYMENT_AUTHORIZED_CRON_REPEAT = 5;
    
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
        $this->logger->info("Razorpay Webhook processing started." );
        
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

                    if($post['event'] == 'order.paid')sleep(1);

                    $this->setWebhookData($post, $orderWebhookData['entity_id'], true, $paymentId, $amountPaid);

                    $this->setWebhookNotifiedAt($orderWebhookData['entity_id']);
                }
            }
        }
        $this->logger->info("Razorpay Webhook processing completed.");
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

        $orderLink = $this->_objectManager->get('Razorpay\Magento\Model\OrderLink')
                        ->load($order->getEntityId(), 'order_id');

        $orderLink->setRzpWebhookNotifiedAt(time());
        $orderLink->save();
    }
    /*
    0->default
    1->payment.authorized -- 4
    2->order.paid webhook received after manual capture
    3->invoice generated
    4->not possible for invoice
    5->payment.authorized repeated on cron
    When order.paid triggered now change 4 to 1 for payment.authorized
    */
    protected function setWebhookData($post, $entityId, $webhookVerifiedStatus, $paymentId, $amount)
    {
        $order                  = $this->order->load($entityId);

        $orderLink = $this->_objectManager->get('Razorpay\Magento\Model\OrderLink')
                        ->load($order->getEntityId(), 'order_id');
                            // ->getCollection()
                            // ->addFilter('order_id', $order->getEntityId())
                            // ->getFirstItem();

        $existingWebhookData    = $orderLink->getRzpWebhookData();

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
        $orderLink->setRzpWebhookData($webhookDataText);
        
        if ($post['event'] === 'order.paid' and
            $orderLink->getRzpUpdateOrderCronStatus() == static::PAYMENT_AUTHORIZED_CRON_REPEAT)
        {
            $this->logger->info('Order paid received after manual capture for id: ' . $order->getIncrementId());
            $orderLink->setRzpUpdateOrderCronStatus(static::ORDER_PAID_AFTER_MANUAL_CAPTURE);
        }
        $orderLink->save();

        $this->logger->info('Webhook data saved for id:' . $order->getIncrementId() . 'event:' . $post['event']);
    }
}
