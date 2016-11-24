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

    public function indexAction()
    {
        if (Mage::helper('razorpay_payments')->isRazorpayEnabled() === false)
        {
            return;
        }

        $session = $this->_getCheckoutSession();

        $order = Mage::getModel('sales/order');
        $order->loadByIncrementId($session->getLastRealOrderId());

        $this->loadLayout();

        $razorpay_block = $this->getLayout()
                    ->createBlock('razorpay_payments/checkout')
                    ->setOrder($order);

        $this->getLayout()->getBlock('content')->append($razorpay_block);

        Mage::app()->getLayout()->getBlock('head')->addJs('razorpay/razorpay-utils.js');

        $this->renderLayout();
    }

    public function successAction()
    {
        $response = $this->getRequest()->getPost();

        $model = Mage::getModel('razorpay_payments/paymentmethod');

        $success = $model->validateSignature($response); 

        // Unsetting the session variable upon completion of signature verification
        Mage::getSingleton('core/session')->unsRazorpayOrderID();

        if ($success === true)
        {
            $this->_redirect('checkout/onepage/success');
        }
        else
        {
            $this->_redirect('checkout/onepage/failure');
        }
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
