<?php

class Razorpay_Payments_Block_Setuputils extends Mage_Core_Block_Template
{
    const KEY_ID        = 'payment/razorpay/key_id';
    const MERCHANT_NAME = 'payment/razorpay/merchant_name_override';
    
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

    protected function _toHtml()
    {
        return parent::_toHtml();
    }
}
