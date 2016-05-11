<?php

class Razorpay_Payments_CheckoutController extends Mage_Core_Controller_Front_Action
{
    protected $order;

    protected function _expireAjax()
    {
        if (!Mage::getSingleton('checkout/session')->getQuote()->hasItems())
        {
            $this->getResponse()->setHeader('HTTP/1.1','403 Session Expired');
            exit;
        }
    }

    public function orderAction()
    {
        $amount             = (int) ((float) $this->_getQuote()->getBaseGrandTotal())*100;
        $base_currency      = $this->_getQuote()->getBaseCurrencyCode();
        $quote_currency     = $this->_getQuote()->getQuoteCurrencyCode();
        $quote_amount       = round($this->_getQuote()->getGrandTotal(), 2);

        $orderId = $this->_getQuote()->getReservedOrderId();

        if (!$orderId)
        {
            $this->_getQuote()->reserveOrderId()->save();
            $orderId = $this->_getQuote()->getReservedOrderId();
        }

        $helper = Mage::helper('razorpay_payments');

        $responseArray = $helper->createOrder($orderId, $amount);

        $bA = $this->_getQuote()->getBillingAddress();

        $responseArray['customer_name']     = $bA->getFirstname() . " " . $bA->getLastname();
        $responseArray['customer_phone']    = $bA->getTelephone() ?: '';
        $responseArray['order_id']          = $orderId;
        $responseArray['amount']            = $amount;
        $responseArray['currency']          = $base_currency;
        $responseArray['customer_email']    = $this->_getQuote()->getData('customer_email') ?: '';
        $responseArray['quote_currency']    = $quote_currency;
        $responseArray['quote_amount']      = $quote_amount;

        $this->getResponse()
            ->setHttpResponseCode(200)
            ->setHeader('Content-type', 'application/json', true)
            ->setBody(json_encode($responseArray));

        return $this;
    }

    /**
    * Return checkout quote object
    *
    * @return Mage_Sales_Model_Quote
    */
    protected function _getQuote()
    {
        if (!$this->_quote)
        {
            $this->_quote = Mage::getSingleton('checkout/session')->getQuote();
        }

        return $this->_quote;
    }

    /**
    * Returns checkout model instance, native onepage checkout is used
    *
    * @return Mage_Checkout_Model_Type_Onepage
    */
    protected function _getCheckout()
    {
        return Mage::getSingleton('checkout/type_onepage');
    }

    protected function _getCheckoutSession()
    {
        return Mage::getSingleton('checkout/session');
    }
}
