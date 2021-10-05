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

class WebhookOrderCron extends \Razorpay\Magento\Controller\BaseController
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
        \Magento\Quote\Model\QuoteRepository $quoteRepository,
        \Magento\Sales\Api\Data\OrderInterface $order,
        \Magento\Quote\Model\QuoteManagement $quoteManagement,
        \Magento\Store\Model\StoreManagerInterface $storeManagement,
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository,
        \Magento\Framework\App\CacheInterface $cache,
        \Magento\Framework\Event\ManagerInterface $eventManager,
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

        $this->objectManagement   = \Magento\Framework\App\ObjectManager::getInstance();
        $this->quoteManagement    = $quoteManagement;
        $this->checkoutFactory    = $checkoutFactory;
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
        $this->logger->info("Razorpay Webhook Order Cron job processing started.");
        try
        {

            $orderLinkCollection = $this->_objectManager->get('Razorpay\Magento\Model\OrderLink')
                                                        ->getCollection()
                                                        ->addFilter('order_placed', 0)
                                                        ->addFieldToFilter('webhook_count',["lt" => 4])
                                                        ->addFieldToFilter('webhook_first_notified_at',["notnull" => true])
                                                        ->addFieldToFilter('amount_paid',["notnull" => true])
                                                        ->setOrder('webhook_first_notified_at')
                                                        ->setPageSize(5);

            $orderLink = $orderLinkCollection->getData();

            if(count($orderLink) > 0)
            {
                foreach ($orderLink as $orderData)
                {
                     $this->createOrder($orderData);  
                }
            }
        }
        catch (\Exception $e)
        {
            $this->logger->critical("Razorpay Webhook Order Cron: Quote submitted for order creation failed with error: ". $e->getMessage());
            return;
        }
        $this->logger->info("Razorpay Webhook Order Cron job completed.");
    }

    /**
     * Order Paid create
     * 
     * @param array $orderData
     */
    protected function createOrder($orderData = array())
    {

        $quoteId    = $orderData['quote_id'];
        $entityId   = $orderData['entity_id'];

        try
        {
            
            $orderLinkCollection = $this->_objectManager->get('Razorpay\Magento\Model\OrderLink')
                                                        ->getCollection()
                                                        ->addFilter('entity_id', $entityId)
                                                        ->getFirstItem();
            $orderLink = $orderLinkCollection->getData();
            
            $paymentId = $orderLink['rzp_payment_id'];

            $webhookWaitTime = $this->config->getConfigData(Config::WEBHOOK_WAIT_TIME) ? $this->config->getConfigData(Config::WEBHOOK_WAIT_TIME) : 300;

            //ignore webhook call for some time as per config, from first webhook call
            if ((time() - $orderLinkCollection->getWebhookFirstNotifiedAt()) < $webhookWaitTime)
            {
                $this->logger->info(__("Razorpay Webhook Cron: Order processing is active for quoteID: $quoteId and Razorpay payment_id(:$paymentId) and webhook attempt: %1", ($orderLink['webhook_count'] + 1)));
                
                return;
            }

            $orderLinkCollection->setWebhookCount($orderLink['webhook_count'] + 1)
                                ->save();

            //validate if the quote Order is still active
            $quote = $this->quoteRepository->get($quoteId);

            //exit if quote is not active
            if (!$quote->getIsActive())
            {
                $this->logger->info("Razorpay Webhook Cron: Quote order is inactive for quoteID: $quoteId and Razorpay payment_id(:$paymentId)");

                return;
            }

            //validate amount before placing order
           $quoteAmount    = (int) (number_format($quote->getGrandTotal() * 100, 0, ".", ""));
           $rzpOrderAmount = (int) (number_format($orderLink['rzp_order_amount'], 0, ".", ""));

            if ($quoteAmount !== $rzpOrderAmount)
            {
                $this->logger->critical("Razorpay Webhook Cron: Amount processed for payment doesn't match with store order amount for Razorpay payment_id(:$paymentId) and quote (:$quoteId)");

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
                    $this->logger->info("Razorpay Webhook Cron: Sales Order and payment already exist for Razorpay payment_id(:$paymentId)");

                    return;
                }
            }

            $quote = $this->getQuoteObject($orderLink, $quoteId);

            $this->logger->info("Razorpay Webhook Cron: Order creation started with quoteID:$quoteId.");

            //validate if the quote Order is still active
            $quoteUpdated = $this->quoteRepository->get($quoteId);

            //exit if quote is not active
            if (!$quoteUpdated->getIsActive())
            {
                $this->logger->info("Razorpay Webhook Cron: Quote order is inactive for quoteID: $quoteId and Razorpay payment_id(:$paymentId)");

                return;
            }

            //verify Rzp OrderLink status
            $orderLinkCollection = $this->_objectManager->get('Razorpay\Magento\Model\OrderLink')
                                                       ->getCollection()
                                                       ->addFilter('entity_id', $entityId)
                                                       ->getFirstItem();

            $orderLink = $orderLinkCollection->getData();

            if (empty($orderLink['entity_id']) === false)
            {
                if ($orderLink['order_placed'])
                {
                    $this->logger->info(__("Razorpay Webhook Cron: Quote order is inactive for quoteID: $quoteId and Razorpay payment_id(:$paymentId) with Maze OrderID (:%1) ", $orderLink['increment_order_id']));

                    return;
                }

                $amount = $orderLink['amount_paid'];
            }
           

            $this->logger->info("Razorpay Webhook Cron: Quote submitted for order creation with quoteID:$quoteId.");

            $order = $this->quoteManagement->submit($quote);

            $payment = $order->getPayment();

            $this->logger->info("Razorpay Webhook Cron: Adding payment to order for quoteID:$quoteId.");

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
                            'amount_captured' => $amount
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

            $this->logger->info("Razorpay Webhook Cron Processed successfully for Razorpay payment_id(:$paymentId): and quoteID(: $quoteId) and OrderID(: ". $order->getEntityId() .")");
            return;
        }
        catch (\Exception $e)
        {
            $this->logger->critical("Razorpay Webhook Cron: Quote submitted for order creation with quoteID:$quoteId failed with error: ". $e->getMessage());
            return;
        }
   }

    protected function getQuoteObject($post, $quoteId)
    {
        try
        {
            $quote = $this->quoteRepository->get($quoteId);

            $firstName = $quote->getBillingAddress()->getFirstname() ?? 'null';
            $lastName  = $quote->getBillingAddress()->getLastname() ?? 'null';
            $email     = $quote->getBillingAddress()->getEmail() ?? $post['email'];

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

            //skip address validation as some time billing/shipping address not set for the quote
            $quote->getBillingAddress()->setShouldIgnoreValidation(true);
            $quote->getShippingAddress()->setShouldIgnoreValidation(true);

            $quote->setStore($store);

            $quote->collectTotals();

            $quote->save();

            return $quote;
        }
        catch (\Exception $e)
        {
            $this->logger->critical("Razorpay Webhook Cron: Unable to update/get quote with quoteID:$quoteId, failed with error: ". $e->getMessage());
            return;
        }
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
