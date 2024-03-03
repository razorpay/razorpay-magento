<?php

use Magento\Newsletter\Model\SubscriberFactory;
use Magento\Store\Model\StoreManagerInterface;

class NewsletterSubscription
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
     * NewsletterSubscription constructor.
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
            $storeId = $store->getStoreId();

            /** @var \Magento\Newsletter\Model\Subscriber $subscriber */
            $subscriber = $this->subscriberFactory->create();

            if (!$subscriber->loadByEmail($customerEmail)->getId())
            {
                $subscriber->setStoreId($storeId)
                       ->setCustomerId($customerId)
                       ->setSubscriberEmail($customerEmail)
                       ->setSubscriberStatus(\Magento\Newsletter\Model\Subscriber::STATUS_SUBSCRIBED);

            	$subscriber->save();
            }

            return true;
        } catch (\Exception $e) {
            // Handle the exception
            return false;
        }
    }
}

// Usage
$customerId = CUSTOMER_ID; // Replace with the actual customer ID

$newsletterSubscription = new NewsletterSubscription($subscriberFactory, $storeManager);
$newsletterSubscription->subscribeCustomer($customerId);
