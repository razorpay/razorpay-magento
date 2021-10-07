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
        $this->logger->info("Razorpay Webhook Event(" . $post['event'] . ")  processing Started.");

        $paymentId  = $post['payload']['payment']['entity']['id'];
        $rzpOrderId = $post['payload']['payment']['entity']['order_id'];

        try
        {
            if($post['event'] === 'payment.authorized')
            {
                $rzpOrder = $this->getRzpOrder($rzpOrderId);

                $quoteId = $rzpOrder->receipt;

                $rzpOrderAmount = $rzpOrder->amount;

                $amountPaid     = $post['payload']['payment']['entity']['amount'];
            }
            else
            {
                $amountPaid     = $post['payload']['order']['entity']['amount_paid'];

                $rzpOrderAmount = $post['payload']['order']['entity']['amount'];

                $quoteId   = $post['payload']['order']['entity']['receipt'];
            }

            if (isset($quoteId) === false)
            {
                $this->logger->info("Razorpay Webhook: Quote ID not set for Razorpay payment_id(:$paymentId)");
                return;
            }

            $email   = $post['payload']['payment']['entity']['email'];
            $contact = $post['payload']['payment']['entity']['contact'];
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

                $paymentSignature = hash_hmac('sha256', $rzpOrderId . "|" . $paymentId, $this->config->getConfigData(Config::KEY_PRIVATE_KEY));

                $orderLinkCollection->setWebhookCount($orderLink['webhook_count'] + 1)
                                    ->setRzpPaymentId($paymentId)
                                    ->setAmountPaid($amountPaid)
                                    ->setRzpSignature($paymentSignature)
                                    ->setEmail($email)
                                    ->setContact($contact)
                                    ->save();

                return;
            }

            $this->logger->info("Razorpay Webhook Event(" . $post['event'] . ") Processed successfully for Razorpay payment_id(:$paymentId): and quoteID(: $quoteId) .");
            return;
        }
        catch (\Exception $e)
        {
            $this->logger->critical("Razorpay Webhook: Quote submitted for order creation with quoteID:$quoteId failed with error: ". $e->getMessage());
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
        catch(\Razorpay\Api\Errors\Error $e)
        {
            $this->logger->critical("Razorpay Webhook: fetching RZP order data(id:$orderIdorderId) failed with error: ". $e->getMessage());
            return;
        }
        catch(\Exception $e)
        {
            $this->logger->critical("Razorpay Webhook: fetching RZP order data(id:$orderId) failed with error: ". $e->getMessage());
            return;
        }
    }
}