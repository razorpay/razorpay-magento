<?php

class Razorpay_Payments_Model_Paymentmethod extends Mage_Payment_Model_Method_Abstract
{
    const CHANNEL_NAME                  = 'Razorpay/Magento%s_%s/%s';
    const METHOD_CODE                   = 'razorpay';
    const CURRENCY                      = 'INR';
    const VERSION                       = '1.1.7';

    protected $_code                    = self::METHOD_CODE;
    protected $_canOrder                = false;
    protected $_isInitializeNeeded      = false;
    protected $_isGateway               = true;
    protected $_canAuthorize            = true;
    protected $_canCapture              = true;
    protected $_canCapturePartial       = false;
    protected $_canRefund               = false;
    protected $_canVoid                 = false;
    protected $_canUseInternal          = false;
    protected $_canUseCheckout          = true;
    protected $_canRefundInvoicePartial = false;
    protected $_canUseForMultishipping  = false;

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Check method for processing with base currency
     *
     * @param string $currencyCode
     * @return boolean
     */
    public function canUseForCurrency($currencyCode)
    {
        if ($currencyCode === 'INR')
        {
            return true;
        }

        return false;
    }

    /**
     * Can be edit order (renew order)
     *
     * @return bool
     */
    public function canEdit()
    {
        return false;
    }

    /**
     * Authorizes specified amount
     *
     * @param Varien_Object $payment
     * @param decimal $amount
     */
    public function authorize(Varien_Object $payment, $amount)
    {
        return $this;
    }

    public function getOrderPlaceRedirectUrl()
    {
        $url = Mage::getUrl('razorpay/checkout/index');

        return $url;
    }

    /**
     * Captures specified amount
     *
     * @param Varien_Object $payment
     * @param decimal $amount
     * @return Razorpay_Payments_Model_Paymentmethod
     */
    public function captureOrder(Varien_Object $order, $amount)
    {
        $helper = Mage::helper('razorpay_payments');

        $requestFields = Mage::app()->getRequest()->getPost();

        $paymentId = $requestFields['razorpay_payment_id'];

        $_preparedAmount = $amount * 100;

        $result = false;
        $error = null;

        try {
            $result = $helper->capturePayment($paymentId, $_preparedAmount);

            $this->_debug($orderId.' - '.$amount);
            $this->_debug($result);

            $result = true;
        }
        catch (Exception $e)
        {
            $result = false;
            $error = $e->getMessage();
        }

        return [$result, $error];
    }

    public function capturePayment($response)
    {
        $order = Mage::getModel('sales/order');
        $payment = Mage::getModel('sales/order_payment');
        $order->loadByIncrementId($response['magento_order_id']);

        $payment->setOrder($order);

        $success = false;

        $paymentId = null;

        if (empty($response['razorpay_payment_id']) === false)
        {
            $paymentId = $response['razorpay_payment_id'];

            try
            {
                $amount = $order->getBaseGrandTotal();

                list($success, $error) = $this->captureOrder($payment, $amount);
            }
            catch (\Exception $e)
            {
                $success = false;
            }
        }

        if ($success === true)
        {
            $order->sendNewOrderEmail();
            $order->setState(Mage_Sales_Model_Order::STATE_PAYMENT_REVIEW, true);
            $order->addStatusHistoryComment('Payment Successful. Razorpay Payment Id:'.$paymentId);
            $order->save();
        }
        else
        {
            if (isset($error))
            {
                $desc = $error . (isset($paymentId) ? '. Payment ID: ' . $paymentId : '. Most probably user closed the popup.');
            }
            else
            {
                $desc = 'Payment failed. Most probably user closed the popup.';
            }

            $order->setState(Mage_Sales_Model_Order::STATE_CANCELED, true);
            $order->addStatusHistoryComment($desc);
            $order->save();
        }

        return $success;
    }

    public function getFields($order)
    {
        $helper = Mage::helper('razorpay_payments');

        $responseArray = $helper->createOrder($order);

        $responseArray['key_id'] = $this->getConfigData('key_id');
        $responseArray['merchant_name'] = $this->getConfigData('merchant_name');
        $responseArray['failure_url'] = Mage::getUrl('razorpay/checkout/failure');

        return $responseArray;
    }

    /**
     * Format param "channel" for transaction
     *
     * @return string
     */
    public function _getChannel()
    {
        $edition = 'CE';
        if (Mage::getEdition() == Mage::EDITION_ENTERPRISE)
        {
            $edition = 'EE';
        }
        return sprintf(self::CHANNEL_NAME, $edition, Mage::getVersion(), self::VERSION);
    }

    /**
     * Retrieve information from payment configuration
     *
     * @param string $field
     * @param int|string|null|Mage_Core_Model_Store $storeId
     *
     * @return mixed
     */
    public function getConfigData($field, $storeId = null)
    {
        if (null === $storeId)
        {
            if (Mage::app()->getStore()->isAdmin())
            {
                $storeId = Mage::getSingleton('adminhtml/session_quote')->getStoreId();
            }
            else
            {
                $storeId = $this->getStore();
            }
        }
        $path = 'payment/'.$this->getCode().'/'.$field;

        return Mage::getStoreConfig($path, $storeId);
    }
}
