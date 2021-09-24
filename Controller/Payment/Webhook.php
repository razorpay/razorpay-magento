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
                    $postData = file_get_contents('php://input');

                    $this->rzp->utility->verifyWebhookSignature($postData, $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'], $webhookSecret);
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
                        return $this->paymentAuthorized($post);

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
     * Payment Authorized webhook
     *
     * @param array $post
     */
    protected function paymentAuthorized(array $post)
    {
        $this->logger->info("Razorpay Webhook Payment Authorized processing Started.");

        if($this->config->getPaymentAction() !== 'authorize')
        {
            $this->logger->info("Razorpay Webhook: Not consuming this event, as Payment action is not set to Authorize for payment_id(:$paymentId)");
            return;
        }

        $paymentId  = $post['payload']['payment']['entity']['id'];
        $rzpOrderId = $post['payload']['payment']['entity']['order_id'];

        try
        {
            $rzpOrder = $this->getRzpOrder($rzpOrderId);

            $quoteId = $rzpOrder->receipt;

            $rzpOrderAmount = $rzpOrder->amount;

            if (isset($quoteId) === false)
            {
                $this->logger->info("Razorpay Webhook: Quote ID not set for Razorpay payment_id(:$paymentId)");
                return;
            }
        }
        catch(\Razorpay\Api\Errors\Error $e)
        {
            $this->logger->critical("Razorpay Webhook: fetching RZP order data(id:$rzpOrderId) PaymentId:(:paymentId) failed with error: ". $e->getMessage());
            return;
        }
        catch(\Exception $e)
        {
            $this->logger->critical("Razorpay Webhook: fetching RZP order data(id:$rzpOrderId) PaymentId:(:paymentId) failed with error: ". $e->getMessage());
            return;
        }

        try
        {
            $orderLinkCollection = $this->_objectManager->get('Razorpay\Magento\Model\OrderLink')
                                                       ->getCollection()
                                                       ->addFilter('quote_id', $quoteId)
                                                       ->addFilter('rzp_order_id', $rzpOrderId)
                                                       ->getFirstItem();

            $orderLink = $orderLinkCollection->getData();

            if (empty($orderLink['entity_id']) === false)
            {
                if ($orderLink['order_placed'])
                {
                     $this->logger->info(__("Razorpay Webhook: Quote order is inactive for quoteID: $quoteId and Razorpay payment_id(:$paymentId) with Maze OrderID (:%1) ", $orderLink['increment_order_id']));

                    return;
                }

                //set the 1st webhook notification time
                if ($orderLink['webhook_count'] < 1)
                {
                    $orderLinkCollection->setWebhookFirstNotifiedAt(time());
                }

                $orderLinkCollection->setWebhookCount($orderLink['webhook_count'] + 1)
                                    ->setRzpPaymentId($paymentId)
                                    ->save();

                $webhookWaitTime = $this->config->getConfigData(Config::WEBHOOK_WAIT_TIME) ? $this->config->getConfigData(Config::WEBHOOK_WAIT_TIME) : 300;

                //ignore webhook call for some time as per config, from first webhook call
                if ((time() - $orderLinkCollection->getWebhookFirstNotifiedAt()) < $webhookWaitTime)
                {
                    $this->logger->info(__("Razorpay Webhook: Order processing is active for quoteID: $quoteId and Razorpay payment_id(:$paymentId) and webhook attempt: %1", ($orderLink['webhook_count'] + 1)));
                    header('Status: 409 Conflict, too early for processing', true, 409);

                    exit;
                }
            }

             // Check if front-end cache flag active
            if (empty($this->cache->load("quote_Front_processing_".$quoteId)) === false)
            {
                $this->logger->info("Razorpay Webhook: Order processing is active for quoteID: $quoteId and Razorpay payment_id(:$paymentId)");
                header('Status: 409 Conflict, too early for processing', true, 409);

                exit;
            }

            $amount = number_format($post['payload']['payment']['entity']['amount']/100, 2, ".", "");

            $this->logger->info("Razorpay Webhook processing started for Razorpay payment_id(:$paymentId) and quoteId(:$quoteId)");

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

            if ($quoteAmount !== $rzpOrderAmount)
            {
                $this->logger->critical("Razorpay Webhook: Amount processed for payment doesn't match with store order amount for Razorpay payment_id(:$paymentId)");

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

            $quote = $this->getQuoteObject($post, $quoteId);

            $this->logger->info("Razorpay Webhook: Order creation started with quoteID:$quoteId.");

            //validate if the quote Order is still active
            $quoteUpdated = $this->quoteRepository->get($quoteId);

            //exit if quote is not active
            if (!$quoteUpdated->getIsActive())
            {
                $this->logger->info("Razorpay Webhook: Quote order is inactive for quoteID: $quoteId and Razorpay payment_id(:$paymentId)");

                return;
            }

            //verify Rzp OrderLink status
            $orderLinkCollection = $this->_objectManager->get('Razorpay\Magento\Model\OrderLink')
                                                       ->getCollection()
                                                       ->addFilter('quote_id', $quoteId)
                                                       ->addFilter('rzp_order_id', $rzpOrderId)
                                                       ->getFirstItem();

            $orderLink = $orderLinkCollection->getData();

            if (empty($orderLink['entity_id']) === false)
            {
                if ($orderLink['order_placed'])
                {
                    $this->logger->info(__("Razorpay Webhook: Quote order is inactive for quoteID: $quoteId and Razorpay payment_id(:$paymentId) with Maze OrderID (:%1) ", $orderLink['increment_order_id']));

                    return;
                }
            }

            //Now start processing the new order creation through webhook
            $this->cache->save("started", "quote_processing_$quoteId", ["razorpay"], 30);

            $this->logger->info("Razorpay Webhook: Quote submitted for order creation with quoteID:$quoteId.");

            $order = $this->quoteManagement->submit($quote);

            $payment = $order->getPayment();

            $this->logger->info("Razorpay Webhook: Adding payment to order for quoteID:$quoteId.");

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

            $this->logger->info("Razorpay Webhook Processed successfully for Razorpay payment_id(:$paymentId): and quoteID(: $quoteId) and OrderID(: ". $order->getEntityId() ." or : " . $order->getRealOrderId() . ")");
            return;
        }
        catch (\Exception $e)
        {
            $this->logger->critical("Razorpay Webhook: Quote submitted for order creation with quoteID:$quoteId failed with error: ". $e->getMessage());
            return;
        }
    }

    /**
     * Order Paid webhook
     * 
     * @param array $post
     */
    protected function orderPaid(array $post)
    {
        $paymentId = $post['payload']['payment']['entity']['id'];
        $rzpOrderId = $post['payload']['order']['entity']['id'];

        if($this->config->getPaymentAction() !== 'authorize_capture')
        {
            $this->logger->info("Razorpay Webhook: Not consuming this event, as Payment action is not set to 'Authorize and Capture' for payment_id(:$paymentId)");
            return;
        }

        if (isset($post['payload']['order']['entity']['receipt']) === false)
        {
            $this->logger->info("Razorpay Webhook: Quote ID not set for Razorpay payment_id(:$paymentId)");
            return;
        }

        $quoteId   = $post['payload']['order']['entity']['receipt'];

        try
        {
            $orderLinkCollection = $this->_objectManager->get('Razorpay\Magento\Model\OrderLink')
                                                       ->getCollection()
                                                       ->addFilter('quote_id', $quoteId)
                                                       ->addFilter('rzp_order_id', $rzpOrderId)
                                                       ->getFirstItem();

            $orderLink = $orderLinkCollection->getData();

            if (empty($orderLink['entity_id']) === false)
            {
                if ($orderLink['order_placed'])
                {
                     $this->logger->info(__("Razorpay Webhook: Quote order is inactive for quoteID: $quoteId and Razorpay payment_id(:$paymentId) with Maze OrderID (:%1) ", $orderLink['increment_order_id']));

                    return;
                }

                //set the 1st webhook notification time
                if ($orderLink['webhook_count'] < 1)
                {
                    $orderLinkCollection->setWebhookFirstNotifiedAt(time());
                }

                $orderLinkCollection->setWebhookCount($orderLink['webhook_count'] + 1)
                                    ->setRzpPaymentId($paymentId)
                                    ->save();


                // Check if front-end cache flag active
                if (empty($this->cache->load("quote_Front_processing_".$quoteId)) === false)
                {
                    $this->logger->info("Razorpay Webhook: Order processing is active for quoteID: $quoteId and Razorpay payment_id(:$paymentId)");
                    header('Status: 409 Conflict, too early for processing', true, 409);

                    exit;
                }

                $webhookWaitTime = $this->config->getConfigData(Config::WEBHOOK_WAIT_TIME) ? $this->config->getConfigData(Config::WEBHOOK_WAIT_TIME) : 300;

                //ignore webhook call for some time as per config, from first webhook call
                if ((time() - $orderLinkCollection->getWebhookFirstNotifiedAt()) < $webhookWaitTime)
                {
                    $this->logger->info(__("Razorpay Webhook: Order processing is active for quoteID: $quoteId and Razorpay payment_id(:$paymentId) and webhook attempt: %1", ($orderLink['webhook_count'] + 1)));
                    header('Status: 409 Conflict, too early for processing', true, 409);

                    exit;
                }
            }

             // Check if front-end cache flag active
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

            if ($quoteAmount !== $post['payload']['order']['entity']['amount'])
            {
                $this->logger->critical("Razorpay Webhook: Amount processed for payment doesn't match with store order amount for Razorpay payment_id(:$paymentId)");

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

            $quote = $this->getQuoteObject($post, $quoteId);

            $this->logger->info("Razorpay Webhook: Order creation started with quoteID:$quoteId.");

            //validate if the quote Order is still active
            $quoteUpdated = $this->quoteRepository->get($quoteId);

            //exit if quote is not active
            if (!$quoteUpdated->getIsActive())
            {
                $this->logger->info("Razorpay Webhook: Quote order is inactive for quoteID: $quoteId and Razorpay payment_id(:$paymentId)");

                return;
            }

            //verify Rzp OrderLink status
            $orderLinkCollection = $this->_objectManager->get('Razorpay\Magento\Model\OrderLink')
                                                       ->getCollection()
                                                       ->addFilter('quote_id', $quoteId)
                                                       ->addFilter('rzp_order_id', $rzpOrderId)
                                                       ->getFirstItem();

            $orderLink = $orderLinkCollection->getData();

            if (empty($orderLink['entity_id']) === false)
            {
                if ($orderLink['order_placed'])
                {
                    $this->logger->info(__("Razorpay Webhook: Quote order is inactive for quoteID: $quoteId and Razorpay payment_id(:$paymentId) with Maze OrderID (:%1) ", $orderLink['increment_order_id']));

                    return;
                }
            }

            //Now start processing the new order creation through webhook

            $this->cache->save("started", "quote_processing_$quoteId", ["razorpay"], 30);

            $this->logger->info("Razorpay Webhook: Quote submitted for order creation with quoteID:$quoteId.");

            $order = $this->quoteManagement->submit($quote);

            $payment = $order->getPayment();

            $this->logger->info("Razorpay Webhook: Adding payment to order for quoteID:$quoteId.");

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

            $this->logger->info("Razorpay Webhook Processed successfully for Razorpay payment_id(:$paymentId): and quoteID(: $quoteId) and OrderID(: ". $order->getEntityId() ." or : " . $order->getRealOrderId() . ")");
            return;
        }
        catch (\Exception $e)
        {
            $this->logger->critical("Razorpay Webhook: Quote submitted for order creation with quoteID:$quoteId failed with error: ". $e->getMessage());
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
            $this->logger->critical("Razorpay Webhook: Unable to update/get quote with quoteID:$quoteId, failed with error: ". $e->getMessage());
            return;
        }
    }

    /**
     * Get the Order from RZP
     *
     * @param string $orderId
     */
    public function getRzpOrder($orderId)
    {
        $order = $this->api->order->fetch($orderId);

        return $order;
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