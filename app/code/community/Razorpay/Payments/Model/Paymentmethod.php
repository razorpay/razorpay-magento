<?php

class Razorpay_Payments_Model_Paymentmethod extends Mage_Payment_Model_Method_Abstract
{
    const CHANNEL_NAME                  = 'Razorpay/Magento%s/%s';
    const METHOD_CODE                   = 'razorpay';
    const CURRENCY                      = 'INR';
    const VERSION                       = '0.2.0';

    protected $_code                    = self::METHOD_CODE;
    protected $_canOrder                = true;
    protected $_isInitializeNeeded      = false;
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
     * Authorizes specified amount
     * 
     * @param Varien_Object $payment
     * @param decimal $amount
     */
    public function authorize(Varien_Object $payment, $amount)
    {
        return $this;
    }

    /**
     * Captures specified amount
     * 
     * @param Varien_Object $payment
     * @param decimal $amount
     * @return Razorpay_Payments_Model_Paymentmethod
     */
    public function capture(Varien_Object $payment, $amount)
    {
        $helper = Mage::helper('razorpay_payments');

        $requestFields = Mage::app()->getRequest()->getPost();

        $paymentId = $requestFields['payment']['rzp_payment_id'];

        $order = $payment->getOrder();
        $orderId = $order->getIncrementId();

        $_preparedAmount = $amount * 100;

        try {
            $result = $helper->capturePayment($paymentId, $_preparedAmount);

            $this->_debug($orderId.' - '.$amount);
            $this->_debug($result);

            if ($result)
            {
                $payment->setStatus(self::STATUS_APPROVED)
                    ->setAmountPaid($amount)
                    ->setLastTransId($paymentId)
                    ->setTransactionId($paymentId)
                    ->setIsTransactionClosed(true)
                    ->setShouldCloseParentTransaction(true);
            }
            else
            {
                Mage::throwException('There was an error capturing the transaction.');
            }
        }
        catch (Exception $e)
        {
            Mage::throwException(
                'There was an error capturing the transaction.' .
                ' ' . $e->getMessage()
            );
        }

        return $this;
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
        return sprintf(self::CHANNEL_NAME, $edition, self::VERSION);
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
