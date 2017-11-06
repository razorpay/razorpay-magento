<?php

class Razorpay_Payments_Model_Paymentmethod extends Mage_Payment_Model_Method_Abstract
{
    const CHANNEL_NAME                  = 'Razorpay/Magento%s_%s/%s';

    /**
     * The name of the method on magento
     */
    const METHOD_CODE                   = 'razorpay';

    const CURRENCY                      = 'INR';

    /**
     * The version of this razorpay plugin
     */
    const VERSION                       = '1.1.28';

    const KEY_ID                        = 'key_id';
    const KEY_SECRET                    = 'key_secret';

    /**
     * The algorithm we use to encrypt our razorpay_signature
     */
    const SHA256                        = 'sha256';

    const RAZORPAY_PAYMENT_ID           = 'razorpay_payment_id';
    const RAZORPAY_ORDER_ID             = 'razorpay_order_id';
    const RAZORPAY_SIGNATURE            = 'razorpay_signature';

    /**
     * Method code of the plugin
     * @var string
     */
    protected $_code                    = self::METHOD_CODE;

    /**
     * Tells us that this plugin is a payment gateway
     * @var bool
     */
    protected $_isGateway               = true;

    /**
     * Tells us that this plugin cna perform the authorize action
     * @var bool
     */
    protected $_canAuthorize            = true;

    /**
     * Tells us that this plugin can be used for admin orders
     * @var bool
     */
    protected $_canUseInternal          = true;

    /**
     * Tells us that this plugin can not be used for multishipping
     * @var bool
     */
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

    public function getOrderPlaceRedirectUrl()
    {
        $url = Mage::getUrl('razorpay/checkout/index');

        return $url;
    }

    public function validateSignature()
    {
        $requestFields = Mage::app()->getRequest()->getPost();

        $session = Mage::getSingleton('checkout/session');
        $orderId = $session->getLastRealOrderId();

        // If $orderId isn't valid, $order is returned to be null
        $order = $this->loadMagentoOrder($orderId);

        if ((empty($order) === false) and
            (empty($requestFields[self::RAZORPAY_PAYMENT_ID]) === false))
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
                $paymentId = $requestFields[self::RAZORPAY_PAYMENT_ID];

                $this->markOrderPaid($order, $paymentId);
            }
            else
            {
                $this->updateOrderFailed($order, $errorMessage);
            }
        }
        else
        {
            $success = false;

            $this->handleErrorCase($order, $requestFields);
        }

        return $success;
    }

    /**
     * @param $orderId
     * @return false|Mage_Core_Model_Abstract|null
     */
    public function loadMagentoOrder($orderId)
    {
        $order = Mage::getModel('sales/order');

        try
        {
            $order->loadByIncrementId($orderId);
        }
        catch (TypeError $e)
        {
            Mage::log(json_encode(['message' => $e->getMessage()]));

            return null;
        }

        return $order;
    }

    public function markOrderPaid($order, $paymentId)
    {
        $amount = $order->getBaseGrandTotal();
        $currencyAmount = $order->getGrandTotal();

        $order->sendNewOrderEmail();
        $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true);
        $order->addStatusHistoryComment('Payment Successful. Razorpay Payment Id:'.$paymentId);
        $order->setBaseTotalPaid($amount);
        $order->setTotalPaid($currencyAmount);
        $order->save();
    }

    public function verifyWebhookSignature($payload, $actualSignature, $webhookSecret)
    {
        $expectedSignature = hash_hmac(self::SHA256, $payload, $webhookSecret);

        if (function_exists('hash_equals') === true)
        {
            $verified = hash_equals($expectedSignature, $actualSignature);
        }
        else
        {
            $verified = $this->hash_equals($expectedSignature, $actualSignature);
        }

        if ($verified === false)
        {
            throw new Exception('Webhook signature failed');
        }
    }

    /**
     * This method is to verify the razorpay payment signature sent across in the post body
     *
     * @param $requestFields
     * @throws Exception
     */
    protected function verifyPaymentSignature($requestFields)
    {
        $razorpayPaymentId = $requestFields[self::RAZORPAY_PAYMENT_ID];
        $razorpayOrderId   = Mage::getSingleton('core/session')->getRazorpayOrderID();
        $actualSignature   = $requestFields[self::RAZORPAY_SIGNATURE];

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

    protected function handleErrorCase($order, $requestFields)
    {
        $errorMessage = "Error processing order";

        if (empty($order) === true)
        {
            //
            // If we weren't able to load the order, we simply return
            //
            return;
        }
        else if (isset($requestFields['error']) === true)
        {
            //
            // If error is set in the request fields, we set the errorMessage accordingly
            //

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
