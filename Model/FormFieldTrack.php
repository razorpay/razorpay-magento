<?php

namespace Razorpay\Magento\Model;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;


class FormFieldTrack extends \Magento\Config\Block\System\Config\Form\Field
{
    public function __construct(
        Context $context,
        array $data = []
        )
    {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element)
    {		
        $baseUrl = $this->_storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB);

        $comment = $element->getComment();

        $copyButton = $comment . "<script type='text/javascript'>
						//<![CDATA[
						require([
						    'jquery'
						], function ($) {
							'use strict';

                            // Importing manually added validation functions
                            ". $this->jsValidation() ."

                            let elementId   = '" .$element->getHtmlId(). "'
							let element     = $('#' + elementId)

                            let fieldName = elementId.substring(20)
                            let fieldType = '". $element->getType() ."'

                            let storeName = $('#payment_us_razorpay_merchant_name_override').val()

                            // Element focus event
                            element.focus(function(){
								let onFocusData = {
                                    'store_name': storeName,
                                    'focus': 'yes',
                                    'field_name': fieldName,
                                    'field_type': fieldType, 
                                }

                                //Send event 
                                $.ajax({
                                    url: '". $baseUrl ."razorpay/payment/FormDataAnalytics',
                                    type: 'POST',
                                    dataType: 'json',
                                    data: { 
                                        'event': 'Form Field Focused', 
                                        'properties': onFocusData
                                    }
                                })
							})

                            // Invalid fields check
                            let validationCheckFormFields = function()
                            {
                                // Validation checks : checkRequiredEntry
                                if (elementId == 'payment_us_razorpay_active' ||
                                    elementId == 'payment_us_razorpay_rzp_payment_action' ||
                                    elementId == 'payment_us_razorpay_key_id' ||
                                    elementId == 'payment_us_razorpay_key_secret' ||
                                    elementId == 'payment_us_razorpay_order_status' ||
                                    elementId == 'payment_us_razorpay_auto_invoice'
                                )
                                {
                                    element.blur(function(){
                                        let elementVal = String(element.val())

                                        // Validations
                                        let checkRequiredEntryBool = checkRequiredEntry(elementVal)

                                        if (!checkRequiredEntryBool)
                                        {
                                            let validationData = {
                                                'store_name'                : storeName,
                                                'field_name'                : fieldName,
                                                'field_type'                : fieldType,
                                                'required-entry'            : checkRequiredEntryBool,
                                            }
                                            
                                            // Send event
                                            $.ajax({
                                                url: '". $baseUrl ."razorpay/payment/FormDataAnalytics',
                                                type: 'POST',
                                                dataType: 'json',
                                                data: { 
                                                    event: 'Form Field Validation Error', 
                                                    properties: validationData 
                                                }
                                            })
                                        }
                                    })
                                }
                                // Validation checks : checkRequiredEntry, checkIfValidDigits, checkIfNonNegative, checkIfInNumberRange
                                else if (elementId == 'payment_us_razorpay_pending_orders_timeout')
                                {                            
                                    element.blur(function(){
                                        let elementVal = String(element.val())

                                        // Validations
                                        let checkRequiredEntryBool      = checkRequiredEntry(elementVal)
                                        let checkIfValidDigitsBool      = checkIfValidDigits(elementVal) 
                                        let checkIfNonNegativeBool      = checkIfNonNegative(elementVal) 
                                        let checkIfInNumberRangeBool    = checkIfInNumberRange(elementVal, 20, 43200)
                                        
                                        if (
                                            !checkRequiredEntryBool || 
                                            !checkIfValidDigitsBool || 
                                            !checkIfNonNegativeBool || 
                                            !checkIfInNumberRangeBool
                                        ){
                                            let validationData = {
                                                'store_name'                    : storeName,
                                                'field_name'                    : fieldName,
                                                'field_type'                    : fieldType,
                                                'required-entry'                : checkRequiredEntryBool,
                                                'validate-digits'               : checkIfValidDigitsBool,
                                                'validate-not-negative-number'  : checkIfNonNegativeBool,
                                                'digits-range-20-43200'         : checkIfInNumberRangeBool 
                                            }
                                            
                                            // Send event 
                                            $.ajax({
                                                url: '". $baseUrl ."razorpay/payment/FormDataAnalytics',
                                                type: 'POST',
                                                dataType: 'json',
                                                data: { 
                                                    event: 'Form Field Validation Error', 
                                                    properties: validationData 
                                                }
                                            })
                                        }
                                    })
                                }
                            }

                            validationCheckFormFields()

                            let getMissingFormFields = function()
                            {
                                let formFieldMap = {
                                    'payment_us_razorpay_active'                        : $('#payment_us_razorpay_active').val(),
                                    'payment_us_razorpay_rzp_payment_action'            : $('#payment_us_razorpay_rzp_payment_action').val(),
                                    'payment_us_razorpay_key_id'                        : $('#payment_us_razorpay_key_id').val(),
                                    'payment_us_razorpay_key_secret'                    : $('#payment_us_razorpay_key_secret').val(),
                                    'payment_us_razorpay_order_status'                  : $('#payment_us_razorpay_order_status').val(),
                                    'payment_us_razorpay_auto_invoice'                  : $('#payment_us_razorpay_auto_invoice').val(),
                                    'payment_us_razorpay_pending_orders_timeout'        : $('#payment_us_razorpay_pending_orders_timeout').val(),
                                }
                                let resultMapObject = new Map()

                                for (var row in formFieldMap)
                                {
                                    if (row == 'payment_us_razorpay_active' ||
                                        row == 'payment_us_razorpay_rzp_payment_action' ||
                                        row == 'payment_us_razorpay_key_id' ||
                                        row == 'payment_us_razorpay_key_secret' ||
                                        row == 'payment_us_razorpay_order_status' ||
                                        row == 'payment_us_razorpay_auto_invoice' ||
                                        row == 'payment_us_razorpay_pending_orders_timeout'
                                    )
                                    {
                                        // Check for empty fields
                                        if (formFieldMap[row] !== 0)
                                        {
                                            if(!formFieldMap[row])
                                            {
                                                resultMapObject.set(row.substring(20), {'missing' : true})
                                            }
                                        }
                                    }
                                    
                                }
                                return resultMapObject
                            }   

                            // After Save Config click, send missing/error field data
                            if (elementId == 'payment_us_razorpay_sort_order')
                            {
                                $('#save').click(function(){
                                    let result = getMissingFormFields()

                                    if (result && result.size>0){
                                        // Send empty form fields when Save Config clicked event
                                        $.ajax({
                                            url: '". $baseUrl ."razorpay/payment/FormDataAnalytics',
                                            type: 'POST',
                                            async: false,
                                            dataType: 'json',
                                            data: { 
                                                event : 'Empty Form Fields',
                                                properties : Object.fromEntries(result)
                                            },
                                            beforeSend: function(xhr){
                                                //Empty to remove magento's default handler
                                            }
                                        })
                                    }
                                })
                            }                 
						});
						//]]>
						</script>
						";
        $element->setComment($copyButton);
        return $element->getElementHtml();
    }

    public function jsValidation()
    {
        return "
                function checkRequiredEntry(field)
                {
                    return field == ''? false : true;
                }

                function checkIfValidDigits(field)
                {
                    return !isNaN(parseFloat(field)) && isFinite(field);
                }

                function checkIfNonNegative(field)
                {
                    let fieldNum = parseInt(field)
                    
                    return fieldNum < 0? false : true;
                }

                function checkIfInNumberRange(field, x, y)
                {
                    let fieldNum = parseInt(field)

                    return (fieldNum >= x && fieldNum <=y)? true : false;
                }
            ";
    }
}