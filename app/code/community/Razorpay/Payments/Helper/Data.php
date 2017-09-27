<?php

// require_once (Mage::getBaseDir() . '/lib/razorpay-php/Razorpay.php');

//use Razorpay\Api\Api;

class Razorpay_Payments_Helper_Data extends Mage_Core_Helper_Abstract
{
    const CONFIG_PATH_RAZORPAY_ENABLED  = 'payment/razorpay/active';

    const BASE_URL                      = 'https://api.razorpay.com/v1/';

    const PAYMENT_MODEL                 = 'razorpay_payments/paymentmethod';

    const KEY_ID                        = 'key_id';
    const KEY_SECRET                    = 'key_secret';

    protected $api;

    public function __construct()
    {
        $paymentModel = Mage::getModel(self::PAYMENT_MODEL);

        $paymentModel->requireAllRazorpayFiles();

        $keyId     = $paymentModel->getConfigData(self::KEY_ID);
        $keySecret = $paymentModel->getConfigData(self::KEY_SECRET);

        $this->api = new Razorpay\Api\Api($keyId, $keySecret);

        $version = Mage::getVersion() . '-' . $paymentModel::VERSION;

        $this->api->setAppDetails('Magento', $version);
    }

    public function isRazorpayEnabled()
    {
        return Mage::getStoreConfigFlag(self::CONFIG_PATH_RAZORPAY_ENABLED);
    }

    protected function verifyOrderAmount($razorpayOrderId, $order)
    {
        $razorpayOrder = $this->api->order->fetch($razorpayOrderId);

        $orderData = $this->getExpectedRazorpayOrderData($order);

        $orderData['id'] = $razorpayOrderId;

        $razorpayOrderKeys = array_keys($orderData);

        foreach ($razorpayOrderKeys as $key)
        {
            if ($razorpayOrder[$key] !== $orderData[$key])
            {
                return false;
            }
        }

        Mage::log('Razorpay Order Amount Verified');

        return true;
    }

    protected function getExpectedRazorpayOrderData($order)
    {
        $amount  = (int) ($order->getBaseGrandTotal() * 100);

        $orderId = $order->getRealOrderId();

        $currency = Razorpay_Payments_Model_Paymentmethod::CURRENCY;

        $data = array(
            'receipt'  => $orderId,
            'amount'   => $amount,
            'currency' => $currency,
        );

        Mage::log(array('expectedRazorpayOrderData' => $data));

        return $data;
    }

    protected function getRazorpayOrderData($order)
    {
        $data = $this->getExpectedRazorpayOrderData($order);

        $data['payment_capture'] = 1;

        Mage::log(array('razorpayOrderData' => $data));

        return $data;
    }

    public function createOrder($order)
    {
        $amount             = (int) ($order->getBaseGrandTotal() * 100);
        $base_currency      = $order->getBaseCurrencyCode();
        $quote_currency     = $order->getCurrencyCode();
        $quote_amount       = round($order->getGrandTotal(), 2);

        $orderId = $order->getRealOrderId();

        $currency = Razorpay_Payments_Model_Paymentmethod::CURRENCY;

        $razorpayOrderId = Mage::getSingleton('core/session')->getRazorpayOrderID();

        if ($razorpayOrderId === null)
        {
            Mage::log(array(
                'order_id'        => $orderId,
                'razorpayOrderId' => 'NULL'
            ));
        }

        try
        {
            if (($razorpayOrderId === null) or 
                (($razorpayOrderId) and ($this->verifyOrderAmount($razorpayOrderId, $order) === false)))
            {
                $data = $this->getRazorpayOrderData($order);

                $response = $this->api->order->create($data);

                $razorpayOrderId = $response['id'];

                Mage::getSingleton('core/session')->setRazorpayOrderID($razorpayOrderId);
            }
        }
        catch (Exception $e)
        {
            $message = 'Razorpay Error: ' . $e->getMessage();

            Mage::getSingleton('core/session')->addError($message);

            return array('error' => true);
        }

        //
        // order id has to be stored and fetched later from the db or session
        //
        $responseArray['razorpay_order_id'] = $razorpayOrderId;

        $bA = $order->getBillingAddress();

        $responseArray = array(
            // order id has to be stored and fetched later from the db or session
            'customer_name'     => $bA->getFirstname() . ' ' . $bA->getLastname(),
            'customer_phone'    => $bA->getTelephone() ?: '',
            'order_id'          => $orderId,
            'base_amount'       => $amount,
            'base_currency'     => $base_currency,
            'customer_email'    => $order->getData('customer_email') ?: '',
            'quote_currency'    => $quote_currency,
            'quote_amount'      => $quote_amount,
            'razorpay_order_id' => $razorpayOrderId, 
            'callback_url'      => $this->getCallbackUrl()
        );

        $order->addStatusToHistory($order->getStatus(), 'Razorpay Order ID: ' . $responseArray['razorpay_order_id']);
        $order->save();

        return $responseArray;
    }

    public function getCallbackUrl()
    {
        return Mage::getUrl('razorpay/checkout/success');
    }

    /**
     * Get order model
     *
     * @return Mage_Sales_Model_Order
     */
    public function getOrder()
    {
        if (!$this->_order) {
            $paymentInfo = $this->getInfoInstance();
            $this->_order = Mage::getModel('sales/order')
                            ->loadByIncrementId($paymentInfo->getOrder()->getRealOrderId());
        }

        return $this->_order;
    }

    protected function _getQuote()
    {
        if (!$this->_quote)
        {
            $this->_quote = Mage::getSingleton('checkout/session')->getQuote();
        }

        return $this->_quote;
    }
}
