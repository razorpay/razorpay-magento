<?php

class Razorpay_Payments_Helper_Data extends Mage_Core_Helper_Abstract
{
    const CONFIG_PATH_RAZORPAY_ENABLED      = 'payment/razorpay/active';

    public function __construct()
    {
        $baseUrl = 'https://api.razorpay.com/v1/';

        $this->urls = array(
            'order'     => $baseUrl . 'orders',
            'payment'   => $baseUrl . 'payments',
            'capture'   => $baseUrl . 'payments/:id/capture',
            'refund'    => $baseUrl . 'payments/:id/refund'
        );

        $this->userAgent = Mage::getModel('razorpay_payments/paymentmethod')->_getChannel();
    }

    public function isRazorpayEnabled()
    {
        return Mage::getStoreConfigFlag(self::CONFIG_PATH_RAZORPAY_ENABLED);
    }

    public function createOrder($receipt, $amount)
    {
        $currency = Razorpay_Payments_Model_Paymentmethod::CURRENCY;

        $paymentModel = Mage::getModel('razorpay_payments/paymentmethod');

        $keyId = $paymentModel->getConfigData('key_id');
        $keySecret = $paymentModel->getConfigData('key_secret');

        $url = $this->getRelativeUrl('order');

        $postData = array(
            'receipt'   => $receipt,
            'amount'    => $amount,
            'currency'  => $currency
        );

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERPWD, $keyId . ":" . $keySecret);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

        $response = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if($response === false)
        {
            $error = 'Curl error: ' . curl_error($ch);

            throw new Exception($error);
        }
        else {
            $responseArray = json_decode($response, true);

            if($httpStatus === 200 and isset($responseArray['error']) === false)
            {
                $success = true;

                $returnArray = array(
                    'rzp_order_id'  => $responseArray['id']
                );
            }
            else
            {
                if(!empty($responseArray['error']['code']))
                {
                    $error = $responseArray['error']['code'].":".$responseArray['error']['description'];
                }
                else
                {
                    $error = "RAZORPAY_ERROR:Invalid Response <br/>".$response;
                }

                throw new Exception($error);
            }
        }

        return $returnArray;
    }

    public function capturePayment($paymentId, $amount)
    {
        $currency = Razorpay_Payments_Model_Paymentmethod::CURRENCY;

        $paymentModel = Mage::getModel('razorpay_payments/paymentmethod');

        $keyId = $paymentModel->getConfigData('key_id');
        $keySecret = $paymentModel->getConfigData('key_secret');

        $url = $this->getRelativeUrl('capture', array(
            ':id'   => $paymentId
        ));

        $postData = array(
            'amount'    => $amount
        );

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERPWD, $keyId . ":" . $keySecret);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

        $response = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if($response === false)
        {
            $error = 'Curl error: ' . curl_error($ch);

            throw new Exception($error);
        }
        else
        {
            $responseArray = json_decode($response, true);

            if($httpStatus === 200 and isset($responseArray['error']) === false)
            {
                $success = true;
            }
            else
            {
                if(!empty($responseArray['error']['code']))
                {
                    $error = $responseArray['error']['code'].":".$responseArray['error']['description'];
                }
                else
                {
                    $error = "RAZORPAY_ERROR:Invalid Response <br/>".$response;
                }

                throw new Exception($error);
            }
        }

        return $success;
    }

    public function getRelativeUrl($name, $data = null)
    {
        if($data)
        {
            return strtr($this->urls[$name], $data);
        }

        return $this->urls[$name];
    }
}
