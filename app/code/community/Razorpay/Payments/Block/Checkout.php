<?php

class Razorpay_Payments_Block_Checkout extends Mage_Core_Block_Template
{
    const KEY_ID        = 'payment/razorpay/key_id';
    const MERCHANT_NAME = 'payment/razorpay/merchant_name_override';

    protected $_template = 'razorpay/checkout.phtml';

    /**
     * Returns key_id from store config
     *
     * @return string
     */
    public function getKeyId()
    {
        return Mage::getStoreConfig(self::KEY_ID);
    }

    /*
     * Returns merchant_name from store config
     *
     * @return string
     */
    public function getMerchantName()
    {
        return Mage::getStoreConfig(self::MERCHANT_NAME);
    }

    public function fields()
    {
        $order = $this->getOrder();

        $razorpay = $order->getPayment()->getMethodInstance();

        $fields = $razorpay->getFields($order);

        return $fields;
    }

    public function getSuccessUrl()
    {
        return Mage::getUrl('razorpay/checkout/success');
    }

    public function getMagentoOrderId()
    {
        $order = $this->getOrder();

        return $order->getRealOrderId();
    }

    /**
     * Render block HTML
     *
     * @return string
     */
    protected function _toHtml()
    {
        return parent::_toHtml();
    }
}