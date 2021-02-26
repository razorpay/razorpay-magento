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
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Payment\State\CaptureCommand;

class Webhook extends \Razorpay\Magento\Controller\BaseController  implements CsrfAwareActionInterface
{
    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $checkoutSession;

    /**
     * @var \Magento\Sales\Api\Data\OrderInterface
     */
    protected $order;

    protected $api;

    protected $logger;


    protected $objectManagement;

    protected $storeManager;

    protected $customerRepository;

    protected $cache;


    /**
     * @var \Magento\Sales\Model\Service\InvoiceService
     */
    protected $_invoiceService;
    protected $_invoiceSender;

    /**
     * @var \Magento\Framework\DB\Transaction
     */
    protected $_transaction;


    /**
     * @var ManagerInterface
     */
    private $eventManager;

    const STATUS_APPROVED = 'APPROVED';
    const STATUS_PROCESSING = 'processing';

    /**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Razorpay\Magento\Model\Config $config
     * @param \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param \Magento\Sales\Model\Service\InvoiceService $invoiceService
     * @param \Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender
     * @param \Magento\Framework\DB\Transaction $transaction
     * @param \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Razorpay\Magento\Model\Config $config,
        OrderRepositoryInterface $orderRepository,
        \Magento\Sales\Api\Data\OrderInterface $order,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender,
        \Magento\Framework\DB\Transaction $transaction,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
        \Psr\Log\LoggerInterface $logger
    ) 
    {
        parent::__construct(
            $context,
            $customerSession,
            $checkoutSession,
            $config
        );

        $keyId                 = $this->config->getConfigData(Config::KEY_PUBLIC_KEY);
        $keySecret             = $this->config->getConfigData(Config::KEY_PRIVATE_KEY);

        $this->api             = new Api($keyId, $keySecret);
        $this->order           = $order;
        $this->logger          = $logger;


        $this->orderRepository    = $orderRepository;

        $this->_invoiceService = $invoiceService;
        $this->_invoiceSender = $invoiceSender;
        $this->_transaction = $transaction;
        $this->orderSender        = $orderSender;
        $this->objectManagement   = \Magento\Framework\App\ObjectManager::getInstance();

    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(
        RequestInterface $request
    ): ?InvalidRequestException {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }


    /**
     * Processes the incoming webhook
     */
    public function execute()
    {  
        $post = $this->getPostData(); 

        if (json_last_error() !== 0)
        {
            return;
        }

        $this->logger->info("Razorpay Webhook processing started.");
       
        if (($this->config->isWebhookEnabled() === true) && 
            (empty($post['event']) === false))
        { 
            if (isset($_SERVER['HTTP_X_RAZORPAY_SIGNATURE']) === true)
            {
                $webhookSecret = $this->config->getWebhookSecret();

                //
                // To accept webhooks, the merchant must configure 
                // it on the magento backend by setting the secret
                // 
                if (empty($webhookSecret) === true)
                {
                    return;
                }

                try
                { 
                    $this->rzp->utility->verifyWebhookSignature(json_encode($post), $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'], $webhookSecret);
                }
                catch (Errors\SignatureVerificationError $e)
                {
                    $this->logger->warning(
                        $e->getMessage(), 
                        [
                            'data'  => $post,
                            'event' => 'razorpay.magento.signature.verify_failed'
                        ]);

                    //Set the validation error in response
                    header('Status: 400 Signature Verification failed', true, 400);    
                    exit;
                }

                switch ($post['event'])
                {
                    case 'payment.authorized':
                        return;

                    case 'order.paid':
                        return $this->orderPaid($post);    

                    default:
                        return;
                }
            }
        }

        $this->logger->info("Razorpay Webhook processing completed.");
    }

    /**
     * Order Paid webhook
     * 
     * @param array $post
     */
    protected function orderPaid(array $post)
    {
        $paymentId = $post['payload']['payment']['entity']['id'];

        if (isset($post['payload']['payment']['entity']['notes']['merchant_order_id']) === false)
        {
            $this->logger->info("Razorpay Webhook: Order ID not set for Razorpay payment_id(:$paymentId)");
            return;
        }

        $orderId   = $post['payload']['payment']['entity']['notes']['merchant_order_id'];

        
        $amount    = number_format($post['payload']['payment']['entity']['amount']/100, 2, ".", "");

        $this->logger->info("Razorpay Webhook processing started for Order ID(:$orderId) and Razorpay payment_id(:$paymentId)");

        # fetch the related sales order and verify the payment ID with rzp payment id
        # To avoid duplicate order entry for same quote 
        $collection = $this->_objectManager->get('Magento\Sales\Model\Order')
                                           ->getCollection()
                                           ->addFieldToSelect('entity_id')
                                           ->addFilter('increment_id', $orderId)
                                           ->getFirstItem();
        
        $salesOrder = $collection->getData();

        if (empty($salesOrder['entity_id']) === false)
        {
            $order = $this->order->load($salesOrder['entity_id']);
            $orderRzpPaymentId = $order->getPayment()->getLastTransId();

            if ($orderRzpPaymentId === $paymentId)
            {
                $this->logger->info("Razorpay Webhook: Sales Order and payment already exist for Razorpay payment_id(:$paymentId)");
                return;
            }

            //exit if order is not active
            if ($order->getStatus() !== $this->config->getNewOrderStatus() && $order->getState() !== 'new')
            {
                $this->logger->info("Razorpay Webhook: Order (with Id: $orderId) is already processed with Payment (ID: $orderRzpPaymentId )");
                    return;
            }

               

            //validate amount before placing order
            $orderAmount = (int) (number_format($order->getGrandTotal() * 100, 0, ".", ""));

            if ($orderAmount !== $post['payload']['payment']['entity']['amount'])
            {
                $this->logger->info("Razorpay Webhook: Amount paid doesn't match with store order amount for Razorpay payment_id(:$paymentId)");
                    return;
            }

            $order->setState(static::STATUS_PROCESSING)->setStatus(static::STATUS_PROCESSING);

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

            $order->save();

            $this->orderRepository->save($order);

            //update/disable the quote
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $quote = $objectManager->get('Magento\Quote\Model\Quote')->load($order->getQuoteId());
            $quote->setIsActive(false)->save();

            $this->logger->info("Razorpay Webhook Order processed for Order ID(:$orderId) and Razorpay payment_id(:$paymentId)");

            if($order->canInvoice() and
                ($this->config->getPaymentAction()  === \Razorpay\Magento\Model\PaymentMethod::ACTION_AUTHORIZE_CAPTURE) and
                $this->config->canAutoGenerateInvoice())
            {
                $invoice = $this->_invoiceService->prepareInvoice($order);
                $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                $invoice->setTransactionId($paymentId);
                $invoice->register();
                $invoice->save();
                $transactionSave = $this->_transaction->addObject($invoice)
                                                      ->addObject($invoice->getOrder());
                $transactionSave->save();

                $this->_invoiceSender->send($invoice);
                //send notification code
                $order->setState(static::STATUS_PROCESSING)->setStatus(static::STATUS_PROCESSING);
                $order->addStatusHistoryComment(
                    __('Notified customer about invoice #%1.', $invoice->getId())
                )
                ->setIsCustomerNotified(true)
                ->save();

                $this->logger->info("Razorpay Webhook Invoice Created for Order ID(:$orderId) and Razorpay payment_id(:$paymentId)");
            }

            //send Order email, after successfull payment
            try
            {
                $this->orderSender->send($order);
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
        
        $this->logger->info("Razorpay Webhook processing Completed for Order ID(:$orderId) and Razorpay payment_id(:$paymentId)");

    }

    /**
     * @return Webhook post data as an array
     */
    protected function getPostData() : array
    {
        $request = file_get_contents('php://input');

        return json_decode($request, true);
    }
}