<?php

class Razorpay_Payments_Model_Paymentmethod extends Mage_Payment_Model_Method_Abstract
{
    const CHANNEL_NAME                  = 'Razorpay/Magento%s_%s/%s';
    const METHOD_CODE                   = 'razorpay';
    const CURRENCY                      = 'INR';
    const VERSION                       = '1.1.25';
    const KEY_ID                        = 'key_id';
    const KEY_SECRET                    = 'key_secret';
    const SHA256                        = 'sha256';

    protected $_code                    = self::METHOD_CODE;
    protected $_canOrder                = false;
    protected $_isInitializeNeeded      = false;
    protected $_isGateway               = true;
    protected $_canAuthorize            = true;
    protected $_canCapture              = false;
    protected $_canCapturePartial       = false;
    protected $_canRefund               = false;
    protected $_canVoid                 = false;
    protected $_canUseInternal          = true;
    protected $_canUseCheckout          = true;
    protected $_canRefundInvoicePartial = false;
    protected $_canUseForMultishipping  = false;

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
     * Authorizes specified amount
     *
     * @param Varien_Object $payment
     * @param $amount
     * @return $this
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

    public function validateSignature()
    {
        $requestFields = Mage::app()->getRequest()->getPost();

        $paymentId = $requestFields['razorpay_payment_id'];        

        $session = Mage::getSingleton('checkout/session');
        $order = Mage::getModel('sales/order');
        $orderId = $session->getLastRealOrderId();
        $order->loadByIncrementId($orderId);

        if ((empty($orderId) === false) and 
            (isset($requestFields['razorpay_payment_id']) === true))
        {
            $success = true;

            $errorMessage = 'Payment failed. Most probably user closed the popup.';

            try
            {
                $this->verifyPaymentSignature($requestFields);
            }
            catch (Exception $e)
            {
                $success = false;

                $errorMessage = 'Payment to Razorpay Failed. ' .  $e->getMessage();
            }

            if ($success === true)
            {
                $order->sendNewOrderEmail();
                $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true);
                $order->addStatusHistoryComment('Payment Successful. Razorpay Payment Id:'.$paymentId);
                $order->save();
            }
            else
            {
                $this->updateOrderFailed($order, $errorMessage);
            }
        }
        else
        {
            $success = false;

            $this->handleErrorCase($order, $orderId, $requestFields);
        }

        return $success;
    }

    protected function verifyPaymentSignature($requestFields)
    {
        $razorpayPaymentId = $requestFields['razorpay_payment_id'];
        $razorpayOrderId   = Mage::getSingleton('core/session')->getRazorpayOrderID();
        $actualSignature   = $requestFields['razorpay_signature'];

        $payload = $razorpayOrderId . '|' . $razorpayPaymentId;

        $secret = $this->getConfigData(self::KEY_SECRET);

        $expectedSignature = hash_hmac(self::SHA256, $payload, $secret);

        $verified = $this->hash_equals($actualSignature, $expectedSignature);

        if ($verified === false)
        {
            throw new Exception('Signature verification failed');
        }
    }

    /**
     * Taken from https://stackoverflow.com/questions/10576827/secure-string-compare-function
     * under the MIT license
     *
     * @param $actualSignature
     * @param $expectedSignature
     * @return bool
     */
    protected function hash_equals($actualSignature, $expectedSignature)
    {
        if (function_exists('hash_equals'))
        {
            return hash_equals($actualSignature, $expectedSignature);
        }

        if (strlen($actualSignature) !== strlen($expectedSignature))
        {
            return false;
        }

        $result = 0;

        for ($i = 0; $i < strlen($actualSignature); $i++)
        {
            $result |= ord($actualSignature[$i]) ^ ord($expectedSignature[$i]);
        }

        return ($result == 0);
    }

    protected function handleErrorCase($order, $orderId, $requestFields)
    {
        if (empty($orderId) === true)
        {
            $errorMessage = 'An error occurred while processing the order';
        }
        else if (isset($requestFields['error']) === true)
        {
            $error = $requestFields['error'];

            $errorMessage = 'An error occurred. Description : ' 
            . $error['description'] 
            . '. Code : ' . $error['code'];

            if (isset($error['field']) === true)
            {
                $errorMessage .= '. Field : ' . $error['field'];
            }
        }

        $this->updateOrderFailed($order, $errorMessage);
    }

    protected function updateOrderFailed($order, $errorMessage)
    {
        $order->setState(Mage_Sales_Model_Order::STATE_CANCELED, true);
        $order->addStatusHistoryComment($errorMessage);
        $order->save();
        $this->updateInventory($order);   
    }

    protected function updateInventory($order)
    {
        $items = $order->getAllItems();

        foreach ($items as $item)
        {
            $item->cancel();
        }
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
