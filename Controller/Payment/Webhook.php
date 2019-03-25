<?php 
namespace Razorpay\Magento\Controller\Payment;
use Razorpay\Api\Api;
use Razorpay\Api\Errors;
use Razorpay\Magento\Model\Config;
use Razorpay\Magento\Model\PaymentMethod;
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
        $this->catalogSession     = $catalogSession;
        $this->quoteRepository    = $quoteRepository;
        $this->storeManagement    = $storeManagement;
        $this->customerRepository = $customerRepository;
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
                
                mail("seher@kdc.in","Test Webhook 1",$post,"From: webmaster@m23.aws.rzp.re");
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
                    $this->api->utility->verifyWebhookSignature(json_encode($post), 
                                                                $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'], 
                                                                $webhookSecret);
                }
                catch (Errors\SignatureVerificationError $e)
                {
                    $this->logger->warning(
                        $e->getMessage(), 
                        [
                            'data'  => $post,
                            'event' => 'razorpay.wc.signature.verify_failed'
                        ]);
                    return;
                }
                switch ($post['event'])
                {
                    case 'payment.authorized':
                        return $this->paymentAuthorized($post);
                    default:
                        return;
                }
            }
        }
    }
    /**
     * Payment Authorized webhook
     * 
     * @param array $post
     */
    protected function paymentAuthorized(array $post)
    {
        $quoteId   = $post['payload']['payment']['entity']['notes']['merchant_order_id'];
        $amount    = $post['payload']['payment']['entity']['amount'] / 100;
        $paymentId = $post['payload']['payment']['entity']['id'];
        $quote = $this->getQuoteObject($post, $quoteId);
        $order = $this->quoteManagement->submit($quote);
        $payment = $order->getPayment();
        $this->logger->warning('Debug Log --------------------- 1');
        if (empty($payment->getLastTransId()) === false)
        {
            return;
        }
        $this->logger->warning('Debug Log --------------------- 2');
        $payment->setAmountPaid($amount)
                ->setLastTransId($paymentId)
                ->setTransactionId($paymentId)
                ->setIsTransactionClosed(true)
                ->setShouldCloseParentTransaction(true);
        $payment->save();
        $this->logger->warning('Debug Log --------------------- 3');
    }
    protected function getQuoteObject($post, $quoteId)
    {
        $email = $post['payload']['payment']['entity']['email'];
        $quote = $this->quoteRepository->get($quoteId);
        $firstName = $quote->getBillingAddress()['customer_firstname'] ?? 'null';
        $lastName = $quote->getBillingAddress()['customer_lastname'] ?? 'null';
        $quote->getPayment()->setMethod(PaymentMethod::METHOD_CODE);
        $store = $this->storeManagement->getStore();
        $websiteId = $store->getWebsiteId();
        $customer = $this->objectManagement->create('Magento\Customer\Model\Customer');
        $customer->setWebsiteId($websiteId);
        $customer = $customer->loadByEmail($email);
        if (empty($customer->getEntityId()) === true)
        {
            $customer->setWebsiteId($websiteId)
                     ->setStore($store)
                     ->setFirstname($firstName)
                     ->setLastname($lastName)
                     ->setEmail($email);
            $customer->save();
        }
        $customer = $this->customerRepository->getById($customer->getEntityId());
        $quote->assignCustomer($customer);
        $quote->setStore($store);
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
