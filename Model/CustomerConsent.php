<?php
namespace Razorpay\Magento\Model;

use Magento\Newsletter\Model\SubscriberFactory;
use Magento\Store\Model\StoreManagerInterface;

class CustomerConsent
{
    /**
     * @var SubscriberFactory
     */
    protected $subscriberFactory;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * CustomerConsent constructor.
     *
     * @param SubscriberFactory $subscriberFactory
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        SubscriberFactory $subscriberFactory,
        StoreManagerInterface $storeManager
    ) {
        $this->subscriberFactory = $subscriberFactory;
        $this->storeManager = $storeManager;
    }

    /**
     * Subscribe customer to newsletter.
     *
     * @param int $customerId
     * @param strig $customerEmail
     * @return bool
     */
    public function subscribeCustomer($customerId, $customerEmail)
    {
        try {
            $store = $this->storeManager->getStore();
            $storeId = $store->getId();  // get the current store ID

            /** @var \Magento\Newsletter\Model\Subscriber $subscriber */
            $subscriber = $this->subscriberFactory->create();

            // Check if the customer is already subscribed
            $existingSubscriber = $subscriber->loadByCustomerId($customerId);

            // If the customer is already subscribed, update the existing subscription
            if ($existingSubscriber && $existingSubscriber->getId()) {
                $existingSubscriber->setSubscriberEmail($customerEmail)
                    ->setStoreId($storeId)
                    ->setSubscriberStatus(\Magento\Newsletter\Model\Subscriber::STATUS_SUBSCRIBED);
                $existingSubscriber->save();
            } else {
                // If no existing subscription, create a new one
                $subscriber->setStoreId($storeId)
                    ->setCustomerId($customerId)
                    ->setSubscriberEmail($customerEmail)
                    ->setSubscriberStatus(\Magento\Newsletter\Model\Subscriber::STATUS_SUBSCRIBED);
                $subscriber->save();
            }

            return true;
        } catch (\Exception $e) {
            // Handle the exception (log it, etc.)
            return false;
        }
    }
}
