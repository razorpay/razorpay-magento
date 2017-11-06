<?php

class Razorpay_Payments_CheckoutController extends Mage_Core_Controller_Front_Action
{
    /**
     * The config that will tell us if webhook has been enabled
     */
    const WEBHOOK_ENABLED  = 'webhook_enabled';

    /**
     * The config that will tell us the user's webhook secret
     */
    const WEBHOOK_SECRET   = 'webhook_secret';

    /**
     * The razorpay payments model class
     */
    const PAYMENT_MODEL    = 'razorpay_payments/paymentmethod';

    /**
     * @var Mage_Sales_Model_Quote
     */
    protected $_quote;

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

        $state = $order->getState();

        if (($state === 'processing') or
            ($state === 'canceled'))
        {
            Mage::throwException("This order cannot be paid for. It is in $state state.");
        }

        $order->setState('new', true);

        $this->loadLayout();

        $razorpayBlock = $this->getLayout()
                              ->createBlock('razorpay_payments/checkout')
                              ->setOrder($order);

        $this->getLayout()->getBlock('content')->append($razorpayBlock);

        Mage::app()->getLayout()->getBlock('head')->addJs('razorpay/razorpay-utils.js');

        $this->renderLayout();
    }

    public function successAction()
    {
        $model = Mage::getModel('razorpay_payments/paymentmethod');

        $success = $model->validateSignature();

        // Unsetting the session variable upon completion of signature verification
        Mage::getSingleton('core/session')->unsRazorpayOrderID();

        if ($success === true)
        {
            $this->_getQuote()->delete();

            $this->_redirect('checkout/onepage/success');
        }
        else
        {
            $this->_redirect('checkout/onepage/failure');
        }
    }

    /**
     * This method is used to process the data sent as part of the razorpay webhook.
     * The webhook relative url is /razorpay/checkout/webhook
     */
    public function webhookAction()
    {
        $paymentModel = Mage::getModel(self::PAYMENT_MODEL);

        $webhookEnabled = $paymentModel->getConfigData(self::WEBHOOK_ENABLED);

        $webhookSecret = $paymentModel->getConfigData(self::WEBHOOK_SECRET);

        var_dump($webhookEnabled, $webhookSecret); die;
    }

    /**
    * Return checkout quote object
    *
    * @return Mage_Sales_Model_Quote
    */
    protected function _getQuote()
    {
        if (isset($this->_quote) === false)
        {
            $this->_quote = Mage::getSingleton('checkout/session')->getQuote();
        }

        return $this->_quote;
    }

    /**
     * Returns checkout model instance, native onepage checkout is used
     *
     * @return Mage_Core_Model_Abstract
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
