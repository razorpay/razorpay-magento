<?php

/*
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the MIT License
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/MIT
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * @category   Razorpay
 * @package    Razorpay Payments (razorpay.com)
 * @copyright  Copyright (c) 2015 Razorpay
 * @license    http://opensource.org/licenses/MIT  MIT License
 */

class Razorpay_Payments_Model_Checkout extends Mage_Payment_Model_Method_Abstract {

    protected $_code  = 'razorpay';
    protected $_paymentMethod = 'shared';

    public function getCheckout() {
        return Mage::getSingleton('checkout/session');
    }

    public function getOrderPlaceRedirectUrl() {
        return Mage::getUrl('razorpay/redirect');
    }

    //get Username
    public function getKeyId() {
        $sid = $this->getConfigData('username');
        return $sid;
    }

    //get Checkout Display
    public function getDisplay() {
        $display = true;
        return $display;
    }

    //get order
    public function getQuote() {
        $orderIncrementId = $this->getCheckout()->getLastRealOrderId();
        $order = Mage::getModel('sales/order')->loadByIncrementId($orderIncrementId);
        return $order;
    }

    //get HTML form data
    public function getFormFields() {
        $order_id       = $this->getCheckout()->getLastRealOrderId();
        $order          = Mage::getModel('sales/order')->loadByIncrementId($order_id);
        $amount         = round($order->getBaseGrandTotal(), 2);
        $currency_code  = $order->getBaseCurrencyCode();
        $b              = $order->getBillingAddress();

        $fields = array();
        $fields['key_id']				= $this->getKeyId();
        $fields['merchant_order_id']	= $order_id;
        $fields['customer_email']		= $order->getData('customer_email');
        $fields['customer_name']		= $b->getFirstname()." ".$b->getLastname();
        $fields['customer_phone']		= $b->getTelephone();       
        $fields['submit_url']           = Mage::getUrl('razorpay/redirect/success', array('_secure' => true));
        $fields['return_url']           = Mage::getUrl('razorpay/redirect/cart', array('_secure' => true));
        $fields['currency_code']        = $currency_code;
        $fields['amount']				= $amount*100;
        $fields['order_id']		        = $order_id;
        $fields['store_name']           = str_replace(array("\r", "\n"), "", $order->getData('store_name'));

        return $fields;
    }

}
