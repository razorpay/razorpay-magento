<?php

/*
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * @category   Craig Christenson
 * @package    Tco (2Checkout.com)
 * @copyright  Copyright (c) 2010 Craig Christenson
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

class Razorpay_Payments_RedirectController extends Mage_Core_Controller_Front_Action {

    protected $order;
    
    public function getCheckout() {
        return Mage::getSingleton('checkout/session');
    }

    protected function _expireAjax() {
        if (!Mage::getSingleton('checkout/session')->getQuote()->hasItems()) {
            $this->getResponse()->setHeader('HTTP/1.1','403 Session Expired');
            exit;
        }
    }

    public function indexAction() {
        $this->loadLayout();
        $block = $this->getLayout()->createBlock('razorpay/redirect');
        $this->getLayout()->getBlock('content')->append($block);
        $this->renderLayout();
    }

    public function successAction() {
        $post = $this->getRequest()->getPost();
        
        $razorpay_payment_id = $post['razorpay_payment_id'];
        $merchant_order_id = $post['merchant_order_id'];

        $session = Mage::getSingleton('checkout/session');
        $session->setQuoteId($merchant_order_id);
        Mage::getSingleton('checkout/session')->getQuote()->setIsActive(false)->save();
        
        $order = Mage::getModel('sales/order');
        $order->loadByIncrementId($session->getLastRealOrderId());
        
        $key_id = Mage::getStoreConfig('payment/razorpay/username');
        $key_secret = Mage::getStoreConfig('payment/razorpay/password');
        
        $amount = round($order->getGrandTotal(), 2)*100;

        $success = false;
        $error = "";

        try {
            $url = 'https://api.razorpay.com/v1/payments/'.$razorpay_payment_id.'/capture';
            $fields_string="amount=$amount";

            //cURL Request
            $ch = curl_init();

            //set the url, number of POST vars, POST data
            curl_setopt($ch,CURLOPT_URL, $url);
            curl_setopt($ch,CURLOPT_USERPWD, $key_id . ":" . $key_secret);
            curl_setopt($ch,CURLOPT_TIMEOUT, 60);
            curl_setopt($ch,CURLOPT_POST, 1);
            curl_setopt($ch,CURLOPT_POSTFIELDS, $fields_string);
            curl_setopt($ch,CURLOPT_RETURNTRANSFER, TRUE);

            //execute post
            $result = curl_exec($ch);
            $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            $array = json_decode($result, true);

            //close connection
            curl_close($ch);

            //Check success response
            if($http_status === 200 and isset($array['error']) === false){
                $success = true;    
            }
            else {
                $error = $array['error']['code'].":".$array['error']['description'];
                $success = false;
            }
        }
        catch (Exception $e) {
            $success = false;
            $error ="MAGNETO_ERROR:Request to Razorpay Failed";
        }

        if ($success === true) {
            $this->_redirect('checkout/onepage/success');
            $order->sendNewOrderEmail();
            $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true)->save();
            $order->addStatusHistoryComment('Payment Successful. Razorpay Payment Id:'.$razorpay_payment_id);
            $order->save();
        } else {
            $this->_redirect('checkout/onepage/failure');
            $order->addStatusHistoryComment($error.' Check Razorpay Dashboard for details of Payment Id:'.$razorpay_payment_id);
            $order->save();
        }
    }

    public function cartAction() {
        $session = Mage::getSingleton('checkout/session');
        if ($session->getLastRealOrderId()) {
            $order = Mage::getModel('sales/order')->loadByIncrementId($session->getLastRealOrderId());
            if ($order->getId()) {
                $order->cancel()->save();
            }
            $quote = Mage::getModel('sales/quote')->load($order->getQuoteId());
            $quote->setIsActive(true)->save();
        }
        $this->_redirect('checkout/cart');
    }

}
