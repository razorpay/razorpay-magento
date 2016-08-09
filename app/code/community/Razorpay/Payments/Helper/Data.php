<?php

class Razorpay_Payments_Helper_Data extends Mage_Core_Helper_Abstract
{
    const CONFIG_PATH_RAZORPAY_ENABLED  = 'payment/razorpay/active';

    const BASE_URL                      = 'https://api.razorpay.com/v1/';

    const PAYMENT_MODEL                 = 'razorpay_payments/paymentmethod';

    const KEY_ID                        = 'key_id';
    const KEY_SECRET                    = 'key_secret';

    protected $successHttpCodes         = array(200, 201, 202, 203, 204, 205, 206, 207, 208, 226);

    public function __construct()
    {
        $this->urls = array(
            'order'     => self::BASE_URL . 'orders',
            'payment'   => self::BASE_URL . 'payments',
            'capture'   => self::BASE_URL . 'payments/:id/capture',
            'refund'    => self::BASE_URL . 'payments/:id/refund'
        );

        $this->userAgent = Mage::getModel(self::PAYMENT_MODEL)->_getChannel();
    }

    public function isRazorpayEnabled()
    {
        return Mage::getStoreConfigFlag(self::CONFIG_PATH_RAZORPAY_ENABLED);
    }

    public function createOrder($order)
    {
        $amount             = (int) ((float) $order->getBaseGrandTotal())*100;
        $base_currency      = $order->getBaseCurrencyCode();
        $quote_currency     = $order->getCurrencyCode();
        $quote_amount       = round($order->getGrandTotal(), 2);

        $orderId = $order->getRealOrderId();

        $currency = Razorpay_Payments_Model_Paymentmethod::CURRENCY;

        $url = $this->getRelativeUrl('order');

        $postData = array(
            'receipt'   => $orderId,
            'amount'    => $amount,
            'currency'  => $currency
        );

        $response = $this->sendRequest($url, $postData);

        $responseArray = array(
            'razorpay_order_id'  => $response['id']
        );

        $bA = $order->getBillingAddress();

        $responseArray['customer_name']     = $bA->getFirstname() . " " . $bA->getLastname();
        $responseArray['customer_phone']    = $bA->getTelephone() ?: '';
        $responseArray['order_id']          = $orderId;
        $responseArray['base_amount']       = $amount;
        $responseArray['base_currency']     = $base_currency;
        $responseArray['customer_email']    = $order->getData('customer_email') ?: '';
        $responseArray['quote_currency']    = $quote_currency;
        $responseArray['quote_amount']      = $quote_amount;

        $order->addStatusToHistory($order->getStatus(), 'Razorpay Order ID: ' . $responseArray['razorpay_order_id']);
        $order->save();

        return $responseArray;
    }

    public function capturePayment($paymentId, $amount)
    {
        if ($this->isRazorpayEnabled())
        {
            $url = $this->getRelativeUrl('capture', array(
                ':id'   => $paymentId
            ));

            $postData = array(
                'amount'    => $amount
            );

            $response = $this->sendRequest($url, $postData);

            if ($response['status'] === 'captured')
            {
                return true;
            }

            throw new \Exception('CAPTURE_ERROR: Unable to capture payment ' . $paymentId);
        }

        throw new \Exception('MAGENTO_ERROR: Payment Method not available');
    }

    public function sendRequest($url, $content, $method = 'POST')
    {
        $paymentModel = Mage::getModel(self::PAYMENT_MODEL);

        $keyId = $paymentModel->getConfigData(self::KEY_ID);
        $keySecret = $paymentModel->getConfigData(self::KEY_SECRET);

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERPWD, $keyId . ":" . $keySecret);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);

        if ($method === 'POST')
        {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        $responseArray = array();

        if (curl_errno($ch))
        {
            $error = 'CURL_ERROR: ' . curl_error($ch);

            Mage::throwException($error);
        }
        else
        {
            if (!empty($response))
            {
                $responseArray = json_decode($response, true);
            }

            if (in_array($httpStatus, $this->successHttpCodes, true) and isset($responseArray['error']) === false)
            {
                return $responseArray;
            }
            else
            {
                if (!empty($responseArray['error']['code']))
                {
                    $error = $responseArray['error']['code'].": ".$responseArray['error']['description'];
                }
                else
                {
                    $error = "RAZORPAY_ERROR: Invalid Response <br/>".$response;
                }

                Mage::throwException($error);
            }
        }
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

    public function getRelativeUrl($name, $data = null)
    {
        if ($data)
        {
            return strtr($this->urls[$name], $data);
        }

        return $this->urls[$name];
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
