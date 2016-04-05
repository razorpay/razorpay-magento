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

    public function createOrder($receipt, $amount)
    {
        if ($this->isRazorpayEnabled())
        {
            $currency = Razorpay_Payments_Model_Paymentmethod::CURRENCY;

            $url = $this->getRelativeUrl('order');

            $postData = array(
                'receipt'   => $receipt,
                'amount'    => $amount,
                'currency'  => $currency
            );

            $response = $this->sendRequest($url, $postData);

            $returnArray = array(
                'razorpay_order_id'  => $response['id']
            );

            return $returnArray;
        }

        throw new Exception('MAGENTO_ERROR: Payment Method not available');
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

            throw new Exception('CAPTURE_ERROR: Unable to capture payment ' . $paymentId);
        }

        throw new Exception('MAGENTO_ERROR: Payment Method not available');
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

        if ($response === false)
        {
            $error = 'CURL_ERROR: ' . curl_error($ch);

            throw new Exception($error);
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

                throw new Exception($error);
            }
        }
    }

    public function getRelativeUrl($name, $data = null)
    {
        if ($data)
        {
            return strtr($this->urls[$name], $data);
        }

        return $this->urls[$name];
    }
}
