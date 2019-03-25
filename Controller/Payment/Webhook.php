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
        mail("seher@kdc.in","Test Webhook 1",var_dump($post),"From: webmaster@m23.aws.rzp.re");
        var_dump($post);
    }    
    protected function getPostData() : array
    {
        $request = file_get_contents('php://input');
        return json_decode($request, true);
    }
}
