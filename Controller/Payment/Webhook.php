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

class Webhook extends \Razorpay\Magento\Controller\BaseController
{
    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $checkoutSession;

    /**
     * @var \Magento\Quote\Model\QuoteRepository
     */
    protected $quoteRepository;

    /**
     * @var \Magento\Sales\Api\Data\OrderInterface
     */
    protected $order;

    protected $api;

    protected $logger;

    protected $quoteManagement;

    protected $objectManagement;

    protected $storeManager;

    protected $customerRepository;

    protected $cache;

    /**
     * @var ManagerInterface
     */
    private $eventManager;

    const STATUS_APPROVED = 'APPROVED';

    /**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Razorpay\Model\CheckoutFactory $checkoutFactory
     * @param \Razorpay\Magento\Model\Config $config
     * @param \Magento\Catalog\Model\Session $catalogSession
     * @param \Magento\Quote\Model\QuoteRepository $quoteRepository,
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param \Magento\Quote\Model\QuoteManagement $quoteManagement
     * @param \Magento\Store\Model\StoreManagerInterface $storeManagement
     * @param \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository
     * @param \Magento\Framework\App\CacheInterface $cache
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Razorpay\Magento\Model\CheckoutFactory $checkoutFactory,
        \Razorpay\Magento\Model\Config $config,
        \Magento\Catalog\Model\Session $catalogSession,
        \Magento\Quote\Model\QuoteRepository $quoteRepository,
        \Magento\Sales\Api\Data\OrderInterface $order,
        \Magento\Quote\Model\QuoteManagement $quoteManagement,
        \Magento\Store\Model\StoreManagerInterface $storeManagement,
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository,
        \Magento\Framework\App\CacheInterface $cache,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Psr\Log\LoggerInterface $logger,
        \Razorpay\Magento\Model\LogHandler $handler
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
        $this->handler         = $handler;
        $this->logger->setHandlers ( [$this->handler] );

        $this->objectManagement   = \Magento\Framework\App\ObjectManager::getInstance();
        $this->quoteManagement    = $quoteManagement;
        $this->checkoutFactory    = $checkoutFactory;
        $this->catalogSession     = $catalogSession;
        $this->quoteRepository    = $quoteRepository;
        $this->storeManagement    = $storeManagement;
        $this->customerRepository = $customerRepository;
        $this->eventManager       = $eventManager;
        $this->cache = $cache;
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

        if (isset($post['payload']['payment']['entity']['notes']['merchant_quote_id']) === false)
        {
            $this->logger->info("Razorpay Webhook: Quote ID not set for Razorpay payment_id(:$paymentId)");
            return;
        }

        $quoteId   = $post['payload']['payment']['entity']['notes']['merchant_quote_id'];

        if (empty($this->cache->load("quote_Front_processing_".$quoteId)) === false)
        {
            $this->logger->info("Razorpay Webhook: Order processing is active for quoteID: $quoteId and Razorpay payment_id(:$paymentId)");
            header('Status: 409 Conflict, too early for processing', true, 409);
            exit;
        }

        $amount    = number_format($post['payload']['payment']['entity']['amount']/100, 2, ".", "");

        $this->logger->info("Razorpay Webhook processing started for Razorpay payment_id(:$paymentId)");

        $payment_created_time = $post['payload']['payment']['entity']['created_at'];

        //validate if the quote Order is still active
        $quote = $this->quoteRepository->get($quoteId);

        //exit if quote is not active
        if (!$quote->getIsActive())
        {
            $this->logger->info("Razorpay Webhook: Quote order is inactive for quoteID: $quoteId and Razorpay payment_id(:$paymentId)");
                return;
        }

        //validate amount before placing order
        $quoteAmount = (int) (number_format($quote->getGrandTotal() * 100, 0, ".", ""));

        if ($quoteAmount !== $post['payload']['payment']['entity']['amount'])
        {
            $this->logger->info("Razorpay Webhook: Amount paid doesn't match with store order amount for Razorpay payment_id(:$paymentId)");
                return;
        }

        # fetch the related sales order and verify the payment ID with rzp payment id
        # To avoid duplicate order entry for same quote 
        $collection = $this->_objectManager->get('Magento\Sales\Model\Order')
                                           ->getCollection()
                                           ->addFieldToSelect('entity_id')
                                           ->addFilter('quote_id', $quoteId)
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
        }

        //Set cache flag for webhook processing
        $this->cache->save("started", "quote_processing_$quoteId", ["razorpay"], 30);

        $quote = $this->getQuoteObject($post, $quoteId);

        //before creating order let wait for 15 sec and re-verify if the quote is active or not
        $this->logger->info("Razorpay Webhook: Waiting for 15 sec with quoteID:$quoteId.");

        sleep(15);

        $this->logger->info("Razorpay Webhook: Waiting of 15 sec over with quoteID:$quoteId.");

        //validate if the quote Order is still active
        $quoteUpdated = $this->quoteRepository->get($quoteId);

        //exit if quote is not active
        if (!$quoteUpdated->getIsActive())
        {
            $this->logger->info("Razorpay Webhook: Quote order is inactive for quoteID: $quoteId and Razorpay payment_id(:$paymentId)");
                return;
        }

        $order = $this->quoteManagement->submit($quote);

        $payment = $order->getPayment();        

        $payment->setAmountPaid($amount)
                ->setLastTransId($paymentId)
                ->setTransactionId($paymentId)
                ->setIsTransactionClosed(true)
                ->setShouldCloseParentTransaction(true);

        //set razorpay webhook fields
        $order->setByRazorpayWebhook(1);

        $order->save();

        //disable the quote
        $quote->setIsActive(0)->save();

        //dispatch the "razorpay_webhook_order_placed_after" event
        $eventData = [
                        'raorpay_payment_id' => $paymentId,
                        'magento_quote_id' => $quoteId,
                        'magento_order_id' => $order->getEntityId(),
                        'amount_captured' => $post['payload']['payment']['entity']['amount']
                     ];

        $transport = new DataObject($eventData);

        $this->eventManager->dispatch(
            'razorpay_webhook_order_placed_after',
            [
                'context'   => 'razorpay_webhook_order',
                'payment'   => $paymentId,
                'transport' => $transport
            ]
        );

        $this->logger->info("Razorpay Webhook Processed successfully for Razorpay payment_id(:$paymentId): and quoteID(: $quoteId) and OrderID(: ". $order->getEntityId() .")");
    }

    protected function getQuoteObject($post, $quoteId)
    {
        $quote = $this->quoteRepository->get($quoteId);

        $firstName = $quote->getBillingAddress()->getFirstname() ?? 'null';
        $lastName  = $quote->getBillingAddress()->getLastname() ?? 'null';
        $email     = $quote->getBillingAddress()->getEmail() ?? $post['payload']['payment']['entity']['email'];

        $quote->getPayment()->setMethod(PaymentMethod::METHOD_CODE);

        $store = $quote->getStore();

        if(empty($store) === true)
        {
            $store = $this->storeManagement->getStore();
        }

        $websiteId = $store->getWebsiteId();

        $customer = $this->objectManagement->create('Magento\Customer\Model\Customer');
        
        $customer->setWebsiteId($websiteId);

        //get customer from quote , otherwise from payment email
        $customer = $customer->loadByEmail($email);
        
        //if quote billing address doesn't contains address, set it as customer default billing address
        if ((empty($quote->getBillingAddress()->getFirstname()) === true) and
            (empty($customer->getEntityId()) === false))
        {   
            $quote->getBillingAddress()->setCustomerAddressId($customer->getDefaultBillingAddress()['id']);
        }

        //If need to insert new customer as guest
        if ((empty($customer->getEntityId()) === true) or
            (empty($quote->getBillingAddress()->getCustomerId()) === true))
        {
            $quote->setCustomerFirstname($firstName);
            $quote->setCustomerLastname($lastName);
            $quote->setCustomerEmail($email);
            $quote->setCustomerIsGuest(true);
        }

        $quote->setStore($store);

        $quote->collectTotals();

        $quote->save();

        return $quote;
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