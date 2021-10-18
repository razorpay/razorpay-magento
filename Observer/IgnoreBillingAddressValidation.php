<?php

namespace Razorpay\Magento\Observer;

use Razorpay\Magento\Model\PaymentMethod;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Class IgnoreBillingAddressValidation
 * @package Razorpay\Magento\Observer
 */
class IgnoreBillingAddressValidation implements ObserverInterface
{
    public function execute(Observer $observer)
    {    	
        $quote = $observer->getEvent()->getQuote();
        
        if (PaymentMethod::METHOD_CODE === $quote->getPayment()->getMethod())
        {
            $quote->getBillingAddress()->setShouldIgnoreValidation(true);
            $quote->getShippingAddress()->setShouldIgnoreValidation(true);
        }
    }
}
