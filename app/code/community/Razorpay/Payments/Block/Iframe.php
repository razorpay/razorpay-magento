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
 * @category   Razorpay
 * @package    Razorpay Payments (razorpay.com)
 * @copyright  Copyright (c) 2015 Razorpay
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

class Razorpay_Payments_Block_Iframe extends Mage_Core_Block_Template
{
    protected $_params = array();

    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('razorpay/iframe.phtml');
    }

    public function setParams($params)
    {
        $this->_params = $params;
        return $this;
    }

    public function getParams()
    {
        return $this->_params;
    }
}
