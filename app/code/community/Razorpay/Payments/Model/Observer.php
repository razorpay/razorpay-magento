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

class Razorpay_Payments_Model_Observer extends Mage_Core_Block_Abstract {

    public function output_razorpay_redirect(Varien_Object $observer) {
        if (isset($_POST['payment']['method']) && $_POST['payment']['method'] == "razorpay") {
            $controller = $observer->getEvent()->getData('controller_action');
            $result = Mage::helper('core')->jsonDecode(
                $controller->getResponse()->getBody('default'),
                Zend_Json::TYPE_ARRAY
            );

            $js = '<script>
                document.getElementById("review-please-wait").style["display"] = "block";
                if ($$("a.top-link-cart")) {
                    $$("a.top-link-cart")[0].href = "'.Mage::getUrl('razorpay/redirect/cart', array('_secure' => true)).'";
                }
                if ($$("p.f-left").length !== 0) {
                    $$("p.f-left")[0].style["display"] = "none";
                }

                var rzphead = $$("head")[0];
                var rzpscript = new Element("script", { type: "text/javascript", src: "https://checkout.razorpay.com/v1/checkout.js" });
                rzphead.appendChild(rzpscript);
                </script>';

            if (empty($result['error'])) {
                $controller->loadLayout('checkout_onepage_review');
                $html = $js;
                $html .= $controller->getLayout()->createBlock('razorpay/redirect')->toHtml();

                $result['update_section'] = array(
                    'name' => 'razorpayiframe',
                    'html' => $html
                );
                $result['redirect'] = false;
                $result['success'] = false;
                $controller->getResponse()->clearHeader('Location');
                $controller->getResponse()->setBody(Mage::helper('core')->jsonEncode($result));
            }
        }
        return $this;
    }
}
?>
