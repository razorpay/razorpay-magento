<?php
namespace Razorpay\Magento\Model;

use Magento\Quote\Model\QuoteFactory;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;

class CartConverter
{
    /**
     * @var QuoteFactory
     */
    private $quoteFactory;

    /**
     * @var CustomerFactory
     */
    private $customerFactory;

    /**
     * @var CustomerSession
     */
    private $customerSession;

    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @var AccountManagementInterface
     */
    private $accountManagement;

    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @param QuoteFactory $quoteFactory
     * @param CustomerFactory $customerFactory
     * @param CustomerSession $customerSession
     * @param CheckoutSession $checkoutSession
     * @param AccountManagementInterface $accountManagement
     * @param CustomerRepositoryInterface $customerRepository
     */
    public function __construct(
        QuoteFactory $quoteFactory,
        CustomerFactory $customerFactory,
        CustomerSession $customerSession,
        CheckoutSession $checkoutSession,
        AccountManagementInterface $accountManagement,
        CustomerRepositoryInterface $customerRepository
    ) {
        $this->quoteFactory = $quoteFactory;
        $this->customerFactory = $customerFactory;
        $this->customerSession = $customerSession;
        $this->checkoutSession = $checkoutSession;
        $this->accountManagement = $accountManagement;
        $this->customerRepository = $customerRepository;
    }

    /**
     * Convert a guest cart to a logged-in customer cart during order placement.
     *
     * @param int $guestQuoteId
     * @param string $customerEmail
     * @param string $customerPassword
     * @return bool
     */
    public function convertGuestCartToCustomer($guestQuoteId, $customerEmail, $customerPassword)
    {
        try {
            // Load guest quote
            $quote = $this->quoteFactory->create()->load($guestQuoteId);

            // Check if the customer is already logged in
            if (!$this->customerSession->isLoggedIn()) {
                // If not logged in, create a customer account
                $customer = $this->customerFactory->create();
                $customer->setEmail($customerEmail);
                $customer->setPassword($customerPassword);

                // Ensure that the correct CustomerInterface type is used
                $customer = $this->convertToCustomerInterface($customer);
                $this->accountManagement->createAccount($customer);

                // Log in the customer
                $this->customerSession->loginById($customer->getId());
            } else {
                // If already logged in, load the customer
                $customer = $this->customerRepository->getById($this->customerSession->getCustomerId());
            }

            // Assign the quote to the customer
            $quote->setCustomerId($customer->getId());
            $quote->setCustomer($customer);
            $quote->save();

            // Place the order
            $order = $this->checkoutSession->getQuote()->convert();
            $order->setCustomerId($customer->getId());
            $order->setCustomer($customer);
            $order->setCustomerIsGuest(false);
            $order->save();

            return $customer->getId();
        } catch (\Exception $e) {
            // Handle exceptions
            return "false";
        }
    }

     /**
     * Convert the customer to CustomerInterface type.
     *
     * @param \Magento\Customer\Model\Customer $customer
     * @return CustomerInterface
     */
    private function convertToCustomerInterface(\Magento\Customer\Model\Customer $customer)
    {
        // You may need to adjust this conversion based on your specific needs
        return $this->customerRepository->getById($customer->getId());
    }
}
