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



class Razorpay_Payments_Block_Redirect extends Mage_Core_Block_Abstract
{
    protected function _toHtml()
    {
        $razorpay = Mage::getModel('razorpay/checkout');

        $fields = $razorpay->getFormFields();

        $html = "<form name=\"razorpay-form\" id=\"razorpay-form\" action=\"".$fields['submit_url']."\" method=\"POST\">
                    <input type=\"hidden\" name=\"razorpay_payment_id\" id=\"razorpay_payment_id\" />
                    <input type=\"hidden\" name=\"merchant_order_id\" id=\"order_id\" value=\"".$fields['order_id']."\"/>
                </form>";
        
        $js = '<script>';

        $js .= "var razorpay_options = {
                    'key': '".$fields['key_id']."',
                    'amount': '".$fields['amount']."',
                    'name': '".$fields['store_name']."',
                    'description': 'Order# ".$fields['order_id']."',
                    'currency': '".$fields['currency_code']."',
                    'handler': function (transaction) {
                        document.getElementById('razorpay_payment_id').value = transaction.razorpay_payment_id;
                        document.getElementById('razorpay-form').submit();
                    },
                    'prefill': {
                        'name': '".$fields['customer_name']."',
                        'email': '".$fields['customer_email']."',
                        'contact': '".$fields['customer_phone']."'
                    },
                    notes: {
                        'magento_order_id': '".$fields['order_id']."'
                    },
                    netbanking: true
                };
                
                function razorpaySubmit(){                  
                    var rzp1 = new Razorpay(razorpay_options);
                    rzp1.open();
                    rzp1.modal.options.backdropClose = false;
                }    
                ";

        $js .= 'var checkoutOrderBtn = $$("button.btn-checkout");
                checkoutOrderBtn[0].removeAttribute("onclick");
                checkoutOrderBtn[0].observe("click", razorpaySubmit);

                new PeriodicalExecuter(function(pe) {
                    if (typeof window["Razorpay"] != "undefined")
                    {
                        setTimeout(function(){ razorpaySubmit(); }, 500);
                        pe.stop();
                    }
                }, 0.10);
                ';

        $js .= '</script>';

        return $html.$js;
    }
}

?>
