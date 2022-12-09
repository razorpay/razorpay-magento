<?php

namespace Razorpay\Magento\Model;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 *  Used to display Pending Orders Cron
 */
class EnablePendingOrdersCron extends \Magento\Config\Block\System\Config\Form\Field
{
	const MODULE_NAME = 'Razorpay_Magento';

	public function __construct(
		Context $context,
        array $data = []
	) {
		parent::__construct($context, $data);
	}

    protected function _getElementHtml(AbstractElement $element)
    {
		$baseUrl = $this->_storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB);
		$copyButton = "<span class='rzp-pending-order-cron-to-clipboard' style='background-color: #337ab7; color: white; border: none;cursor: pointer; padding: 2px 4px; text-decoration: none;display: inline-block;'>Copy Cron</span>
						<script type='text/javascript'>
						//<![CDATA[
						require([
						    'jquery'
						], function ($) {
							'use strict';

						    $(function() {
						        $('.rzp-pending-order-cron-to-clipboard').click(function() {
						            var temp = $('<input>');
									$('body').append(temp);
									temp.val($('.rzp-pending-order-cron-job').text()).select();
									document.execCommand('copy');
									temp.remove();
						            $('.rzp-pending-order-cron-to-clipboard').text('Copied to clipboard');

									// Send copy cron clicked track event 
									$.ajax({
										url: '". $baseUrl ."razorpay/payment/FormDataAnalytics',
										type: 'POST',
										dataType: 'json',
										data: {
											event: 'Copy Cron Clicked',
											properties:	{
												'store_name': $('#payment_us_razorpay_merchant_name_override').val(),
												'cron_copy_clicked': true,
											}
										}
									})
									
                                    setTimeout(function(){
                                        $('.rzp-pending-order-cron-to-clipboard').text('Copy Cron');
                                    },5000);
						        });
						    });
						});
						//]]>
						</script>
						";
        $element->setComment("Setup cronjob at server for moving pending orders to Cancel status after timeout. <br><br>*Please execute following command within Magento root directory to setup cronjob* <br><span style='width:300px;font-weight: bold;' class='rzp-pending-order-cron-job' >php bin/magento cron:install</span><br/>" . $copyButton);
        return $element->getElementHtml();
    }
}
