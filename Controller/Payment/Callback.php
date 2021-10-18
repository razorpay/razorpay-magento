<?php

namespace Razorpay\Magento\Controller\Payment;

use Razorpay\Api\Api;
use Razorpay\Magento\Model\PaymentMethod;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\RequestInterface;

class Callback extends \Razorpay\Magento\Controller\BaseController
{
    protected $quote;

    protected $checkoutSession;

    protected $cartManagement;

    protected $cache;

    protected $orderRepository;

    protected $logger;
    /**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Razorpay\Model\Config\Payment $razorpayConfig
     * @param \Magento\Framework\App\CacheInterface $cache
     * @param \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Razorpay\Magento\Model\Config $config,
        \Magento\Quote\Api\CartManagementInterface $cartManagement,
        \Razorpay\Magento\Model\CheckoutFactory $checkoutFactory,
        \Magento\Framework\App\CacheInterface $cache,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Quote\Model\QuoteRepository $quoteRepository,
        \Magento\Quote\Model\QuoteManagement $quoteManagement,
        \Magento\Customer\Model\CustomerFactory $customerFactory,
        \Psr\Log\LoggerInterface $logger
    ) {
        parent::__construct(
            $context,
            $customerSession,
            $checkoutSession,
            $config
        );

        $this->config          = $config;
        $this->cartManagement  = $cartManagement;
        $this->customerSession = $customerSession;
        $this->checkoutFactory = $checkoutFactory;
        $this->orderRepository = $orderRepository;
        $this->quoteRepository = $quoteRepository;
        $this->quoteManagement = $quoteManagement;
        $this->customerFactory = $customerFactory;
        $this->logger          = $logger;

        $this->objectManagement   = \Magento\Framework\App\ObjectManager::getInstance();
    }

    public function execute()
    {
        $params = $this->getRequest()->getParams();
                
        $quoteId = strip_tags($params["order_id"]);

        if(empty($quoteId) === true)
        {
            $this->messageManager->addError(__('Razorpay front-end callback: Payment Failed, As no active cart ID found.'));

            return $this->_redirect('checkout/cart');
        }
        
        $quote = $this->getQuoteObject($params, $quoteId);

        if(!$this->customerSession->isLoggedIn())
        {
            $customerId = $quote->getCustomer()->getId();

            if(!empty($customerId))
            {
                $customer = $this->customerFactory->create()->load($customerId);

                $this->customerSession->setCustomerAsLoggedIn($customer);
            }
        }

        if(isset($params['razorpay_payment_id']))
        {
            if(isset($quoteId) and
               (empty($quoteId) === false))
            {
                try
                {
                    $this->logger->info('Razorpay front-end callback: for cartId- ' . $quoteId);

                    $quote->getPayment()->setMethod(PaymentMethod::METHOD_CODE);

                    if(!$this->customerSession->isLoggedIn())
                    {
                        $quote->setCheckoutMethod($this->cartManagement::METHOD_GUEST);
                    }

                    $order = $this->quoteManagement->submit($quote);

                    $this->logger->info(__('Razorpay front-end callback: order Id- ' . $order->getId()));
                    
                    $this->checkoutSession->setLastSuccessQuoteId($quote->getId())
                                          ->setLastQuoteId($quote->getId())
                                          ->clearHelperData();

                    if(empty($order) === false)
                    {
                        $this->checkoutSession->setLastOrderId($order->getId())
                                              ->setLastRealOrderId($order->getIncrementId())
                                              ->setLastOrderStatus($order->getStatus());
                    }
                    
                    return $this->_redirect('checkout/onepage/success');

                    exit;
                }
                catch(\Exception $e)
                {
                    $quote->setIsActive(1)->setReservedOrderId(null)->save();

                    $this->logger->critical(__('Razorpay front-end callback: ' . $e->getMessage()));
                    
                    $this->messageManager->addError(__($e->getMessage()));

                    return $this->_redirect('checkout/cart');
                }
            }
            else
            {
                $this->logger->critical(__('Razorpay front-end callback: Quote ID missing on callback from RZP.' ));
            }
        }
        else
        {
            $quote->setIsActive(1)->setReservedOrderId(null)->save();

            $this->checkoutSession->replaceQuote($quote);

            $this->logger->critical(__('Razorpay front-end callback: Payment Failed with response:  ' . json_encode($params, 1) ));
            
            $this->messageManager->addError(__('Payment Failed.'));

            return $this->_redirect('checkout/cart');
        }

    }

    protected function getQuoteObject($post, $quoteId)
    {
        try
        {
            $quote = $this->quoteRepository->get($quoteId);

            $firstName = $quote->getBillingAddress()->getFirstname() ?? 'null';
            $lastName  = $quote->getBillingAddress()->getLastname() ?? 'null';
            $email     = $quote->getBillingAddress()->getEmail() ?? 'null';

            $quote->getPayment()->setMethod(PaymentMethod::METHOD_CODE);

            $store = $quote->getStore();

            if(empty($store) === true)
            {
                $store = $this->storeManagement->getStore();
            }

            $websiteId = $store->getWebsiteId();

            $customer = $this->objectManagement->create('Magento\Customer\Model\Customer');

            $customer->setWebsiteId($websiteId);

            $orderLinkCollection = $this->_objectManager->get('Razorpay\Magento\Model\OrderLink')
                                                       ->getCollection()
                                                       ->addFilter('quote_id', $quote->getId())
                                                       ->getFirstItem();

            $orderLinkData = $orderLinkCollection->getData();

            if (empty($orderLinkData['entity_id']) === false)
            {
                $email = $orderLinkData['email'] ?? $email;
            }

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
            $this->logger->critical("Razorpay front-end callback: Unable to update/get quote with quoteID:$quoteId, failed with error: ". $e->getMessage());
            return;
        }
    }

}
