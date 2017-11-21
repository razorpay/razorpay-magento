<?php

class Razorpay_Payments_Helper_Data extends Mage_Core_Helper_Abstract
{
    const CONFIG_PATH_RAZORPAY_ENABLED  = 'payment/razorpay/active';

    /**
     * Razorpay API's base url
     */
    const BASE_URL                      = 'https://api.razorpay.com/v1/';

    const PAYMENT_MODEL                 = 'razorpay_payments/paymentmethod';

    const REQUEST_TIMEOUT               = 60;

    const KEY_ID                        = 'key_id';
    const KEY_SECRET                    = 'key_secret';

    /**
     * @var array Contains all the urls for Razorpay API calls
     */
    protected $urls                     = array(
        'order'   => self::BASE_URL . 'orders',
        'payment' => self::BASE_URL . 'payments',
        'refund'  => self::BASE_URL . 'payments/:id/refund'
    );

    /**
     * @var array Contains all the success Http codes
     */
    protected $successHttpCodes         = array(200, 201, 202, 203, 204, 205, 206, 207, 208, 226);

    /**
     * @var Mage_Sales_Model_Order
     */
    protected $_order;

    /**
     * @var Mage_Sales_Model_Quote
     */
    protected $_quote;

    public function isRazorpayEnabled()
    {
        return Mage::getStoreConfigFlag(self::CONFIG_PATH_RAZORPAY_ENABLED);
    }

    protected function verifyOrderAmount($razorpayOrderId, $order)
    {
        $url = $this->getRelativeUrl('order') . '/' . $razorpayOrderId;

        $razorpayOrder = $this->sendRequest($url, 'GET');

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
        $orderCurrency = $order->getBaseCurrencyCode();

        $orderAmount = (int) ($order->getBaseGrandTotal() * 100);

        if ($orderCurrency !== Razorpay_Payments_Model_Paymentmethod::CURRENCY)
        {
            $orderAmount = $this->getOrderAmountInInr($orderAmount, $orderCurrency, false);
        }

        $data = array(
            'receipt'  => $order->getRealOrderId(),
            'amount'   => $orderAmount,
            'currency' => Razorpay_Payments_Model_Paymentmethod::CURRENCY,
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

    /**
     * @param $orderAmount
     * @param $orderCurrency
     * @param bool $create
     * @return float
     */
    protected function getOrderAmountInInr($orderAmount, $orderCurrency, $create = true)
    {
        $amount = Mage::getSingleton('core/session')->getOrderAmount();

        //
        // For create step, we always re-calculate the INR amount
        // For validation step, we only calculate if the amount is not stored in the session
        //
        if (($amount === null) or ($create === true))
        {
            $url = "http://api.fixer.io/latest?base=$orderCurrency";

            $rates = json_decode(file_get_contents($url), true);

            $amount = ceil($orderAmount * $rates['rates'][Razorpay_Payments_Model_Paymentmethod::CURRENCY]);

            Mage::getSingleton('core/session')->setOrderAmount($amount);
        }

        return $amount;
    }

    public function createOrder($order)
    {
        $orderId = $order->getRealOrderId();

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

                $orderUrl = $this->getRelativeUrl('order');

                $response = $this->sendRequest($orderUrl, 'POST', $data);

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

        $amount            = (int) ($order->getBaseGrandTotal() * 100);
        $baseCurrency      = $order->getBaseCurrencyCode();

        $quoteCurrency     = $order->getOrderCurrencyCode();
        $quoteAmount       = round($order->getGrandTotal(), 2);

        // For eg. If base currency is USD
        if ($baseCurrency !== Razorpay_Payments_Model_Paymentmethod::CURRENCY)
        {
            $amount = $this->getOrderAmountInInr($amount, $baseCurrency);
        }

        $responseArray = array(
            // order id has to be stored and fetched later from the db or session
            'customer_name'     => $bA->getFirstname() . ' ' . $bA->getLastname(),
            'customer_phone'    => $bA->getTelephone() ?: '',
            'order_id'          => $orderId,
            'base_amount'       => $amount,
            'base_currency'     => Razorpay_Payments_Model_Paymentmethod::CURRENCY,
            'customer_email'    => $order->getData('customer_email') ?: '',
            'quote_currency'    => $quoteCurrency,
            'quote_amount'      => $quoteAmount,
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

    public function getRelativeUrl($name, $data = null)
    {
        if (empty($data) === false)
        {
            return strtr($this->urls[$name], $data);
        }

        return $this->urls[$name];
    }

    public function sendRequest($url, $method = 'POST', $content = array())
    {
        $ch = $this->getCurlHandle($url, $method, $content);

        $response = curl_exec($ch);

        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $curlError = curl_error($ch);

        curl_close($ch);

        if ($response === false)
        {
            $error = 'CURL_ERROR: ' . $curlError;
            Mage::throwException($error);
        }
        else
        {
            $responseArray = json_decode($response, true);

            if ((in_array($httpStatus, $this->successHttpCodes, true)) and
                (isset($responseArray['error']) === false))
            {
                return $responseArray;
            }
            else
            {
                if (empty($responseArray['error']['code']) === false)
                {
                    $error = $responseArray['error']['code'] . ': ' . $responseArray['error']['description'];
                }
                else
                {
                    $error = 'RAZORPAY_ERROR: Invalid Response <br/>' . $response;
                }

                Mage::throwException($error);
            }
        }
    }

    private function getCurlHandle($url, $method = 'POST', $content = array())
    {
        $paymentModel = Mage::getModel(self::PAYMENT_MODEL);

        $keyId = $paymentModel->getConfigData(self::KEY_ID);

        $keySecret = $paymentModel->getConfigData(self::KEY_SECRET);

        $method = strtoupper($method);

        //cURL Request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERPWD, $keyId . ":" . $keySecret);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::REQUEST_TIMEOUT);

        if (is_array($content) === true)
        {
            $data = http_build_query($content);
        }
        else if (is_string($content) === true)
        {
            $data = $content;
        }

        switch ($method)
        {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                break;

            case 'PATCH':
            case 'PUT':
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                break;
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_CAINFO, dirname(__FILE__) . '/ca-bundle.crt');

        return $ch;
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

    /**
     * Get quote model
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
}
