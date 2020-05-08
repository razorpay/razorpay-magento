<?php

namespace Razorpay\Magento\Controller\Payment;

use Razorpay\Api\Api;
use Razorpay\Magento\Model\PaymentMethod;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;

class Order extends \Razorpay\Magento\Controller\BaseController implements CsrfAwareActionInterface
{
    protected $quote;

    protected $checkoutSession;

    /**
     * @var \Magento\Quote\Model\QuoteRepository
     */
    protected $quoteRepository;

    /**
     * @var \Magento\Sales\Api\Data\OrderInterface
     */
    protected $order;

    protected $quoteManagement;

    protected $objectManagement;

    protected $storeManager;

    protected $customerRepository;

    protected $_currency = PaymentMethod::CURRENCY;

    const STATUS_APPROVED = 'APPROVED';

    /**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Razorpay\Model\CheckoutFactory $checkoutFactory
     * @param \Magento\Razorpay\Model\Config\Payment $razorpayConfig
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
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository
    ) {
        parent::__construct(
            $context,
            $customerSession,
            $checkoutSession,
            $config
        );

        $this->objectManagement   = \Magento\Framework\App\ObjectManager::getInstance();
        $this->quoteManagement    = $quoteManagement;
        $this->quoteRepository    = $quoteRepository;
        $this->storeManagement    = $storeManagement;
        $this->customerRepository = $customerRepository;
        $this->checkoutFactory    = $checkoutFactory;
        $this->catalogSession     = $catalogSession;
        $this->config             = $config;
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

    public function execute()
    {
        $amount = (int) (round($this->getQuote()->getGrandTotal(), 2) * 100);

        $receipt_id = $this->getQuote()->getId();

        if(empty($_POST) === false && isset($_POST['razorpay_payment_id']))
        {
            $quote = $this->getQuoteObject($receipt_id);

            $order = $this->quoteManagement->submit($quote);

            $this->checkoutSession->setLastSuccessQuoteId($quote->getId());
            $this->checkoutSession->setLastQuoteId($quote->getId());
            $this->checkoutSession->setLastOrderId($order->getId());
            $this->checkoutSession->setLastRealOrderId($order->getIncrementId());

            $payment = $order->getPayment();

            $payment->setAmountPaid($amount)
                    ->setLastTransId($_POST['razorpay_payment_id'])
                    ->setTransactionId($_POST['razorpay_payment_id'])
                    ->setIsTransactionClosed(true)
                    ->setShouldCloseParentTransaction(true);
            $order->save();

            return $this->_redirect('checkout/onepage/success');
        }
        else
        {
            $payment_action = $this->config->getPaymentAction();

            $maze_version = $this->_objectManager->get('Magento\Framework\App\ProductMetadataInterface')->getVersion();
            $module_version =  $this->_objectManager->get('Magento\Framework\Module\ModuleList')->getOne('Razorpay_Magento')['setup_version'];

            if ($payment_action === 'authorize')
            {
                    $payment_capture = 0;
            }
            else
            {
                    $payment_capture = 1;
            }

            $code = 400;

            try
            {
                $order = $this->rzp->order->create([
                    'amount' => $amount,
                    'receipt' => $receipt_id,
                    'currency' => $this->getQuote()->getQuoteCurrencyCode(),
                    'payment_capture' => $payment_capture
                ]);

                $responseContent = [
                    'message'   => 'Unable to create your order. Please contact support.',
                    'parameters' => []
                ];

                if (null !== $order && !empty($order->id))
                {
                    $is_hosted = false;

                    $merchantPreferences    = $this->getMerchantPreferences();

                    $responseContent = [
                        'success'           => true,
                        'rzp_order'         => $order->id,
                        'order_id'          => $receipt_id,
                        'amount'            => $order->amount,
                        'quote_currency'    => $this->getQuote()->getQuoteCurrencyCode(),
                        'quote_amount'      => round($this->getQuote()->getGrandTotal(), 2),
                        'maze_version'      => $maze_version,
                        'module_version'    => $module_version,
                        'is_hosted'         => $merchantPreferences['is_hosted'],
                        'image'             => $merchantPreferences['image'],
                    ];

                    $code = 200;

                    $this->catalogSession->setRazorpayOrderID($order->id);
                }
            }
            catch(\Razorpay\Api\Errors\Error $e)
            {
                $responseContent = [
                    'message'   => $e->getMessage(),
                    'parameters' => []
                ];
            }
            catch(\Exception $e)
            {
                $responseContent = [
                    'message'   => $e->getMessage(),
                    'parameters' => []
                ];
            }

            $response = $this->resultFactory->create(ResultFactory::TYPE_JSON);
            $response->setData($responseContent);
            $response->setHttpResponseCode($code);

            return $response;
        }
    }

    public function getOrderID()
    {
        return $this->catalogSession->getRazorpayOrderID();
    }

    protected function getMerchantPreferences()
    {
        try
        {
            $api = new Api($this->config->getKeyId(),"");

            $response = $api->request->request("GET", "preferences");
        }
        catch (Exception $e)
        {
            echo 'Magento Error : ' . $e->getMessage();
        }

        $preferences = [];

        $preferences['is_hosted'] = false;
        $preferences['image'] = $response['options']['image'];

        if(isset($response['options']['redirect']) && $response['options']['redirect'] === true)
        {
            $preferences['is_hosted'] = true;
        }

        return $preferences;
    }

    protected function getQuoteObject($quoteId)
    {
        $quote = $this->quoteRepository->get($quoteId);

        $firstName = $quote->getBillingAddress()['customer_firstname'] ?? 'null';
        $lastName  = $quote->getBillingAddress()['customer_lastname'] ?? 'null';
        $email     = $quote->getBillingAddress()['email'] ?? 'null';

        $quote->getPayment()->setMethod(PaymentMethod::METHOD_CODE);

        $store = $this->storeManagement->getStore();

        $websiteId = $store->getWebsiteId();

        $customer = $this->objectManagement->create('Magento\Customer\Model\Customer');

        $customer->setWebsiteId($websiteId);

        //get customer from quote , otherwise from payment email
        if(empty($quote->getBillingAddress()['email']) === false)
        {
            $customer = $customer->loadByEmail($quote->getBillingAddress()['email']);
        }

        //if quote billing address doesn't contains address, set it as customer default billing address
        if(empty($quote->getBillingAddress()['customer_firstname']) === true)
        {
            $quote->getBillingAddress()->setCustomerAddressId($customer->getDefaultBillingAddress()['id']);
        }

        //If need to insert new customer
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
}
