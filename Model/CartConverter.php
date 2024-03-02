<?php
namespace Razorpay\Magento\Model;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Quote\Api\CartRepositoryInterface;


class CartConverter
{
    /**
     * @var CustomerSession
     */
    private $customerSession;

    protected $cartRepository;

    /**
     * @param CustomerSession $customerSession
     * @param CartRepositoryInterface $cartRepository
     */
    public function __construct(
        CustomerSession $customerSession,
        CartRepositoryInterface $cartRepository
    ) {
        $this->customerSession = $customerSession;
        $this->cartRepository = $cartRepository;
    }

    /**
     * Convert a guest cart to a logged-in customer cart during order placement.
     *
     * @param int $guestQuoteId
     * @return bool
     */
    public function convertGuestCartToCustomer($guestQuoteId)
    {

        $customerId = $this->customerSession->getCustomerId();

        if($customerId)
        {
            try {
                // Load the guest quote by ID
                $guestQuote = $this->cartRepository->get($guestQuoteId);

                // Set customer details to the quote
                $guestQuote->setCustomerId($customerId);
                $guestQuote->setCustomerIsGuest(0); // Set customer as not a guest

                // Save the quote
                $this->cartRepository->save($guestQuote);

                // Set the customer ID in the customer session
                $this->customerSession->setCustomerId($customerId);

                return "true";
            } catch (\Exception $e) {
                // Handle the exception
                return "false";
            }
        }
    }
}
