<?php

namespace Razorpay\Magento\Model;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use Magento\Framework\Data\Form\Element\AbstractElement;


class FormFieldTrack extends \Magento\Config\Block\System\Config\Form\Field
{
    public function __construct(
        Context $context,
        array $data = [],
        ?SecureHtmlRenderer $secureRenderer = null
        )
    {
        parent::__construct($context, $data, $secureRenderer);
    }

    protected function _getElementHtml(AbstractElement $element)
    {		
        $copyButton = "<script type='text/javascript'>
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
                                //console.log(onFocusData)

                                //Send event 
                                $.ajax({
                                    url: '/magento/pub/razorpay/payment/FormDataAnalytics',
                                    type: 'POST',
                                    dataType: 'json',
                                    data: { 
                                        'event': 'Form Field Focused', 
                                        'properties': onFocusData
                                    },
                                    success: function(result, status, xhr) {
                                        // console.log('success')
                                    },
                                    error: function(xhr, status, error) {
                                        // console.log('fail')
                                        // console.log(error)
                                    }
                                })
							})

                            // Keep track if any form fields modified
                            element.change(function(){
                                if (!localStorage.getItem('changesMade') || localStorage.getItem('changesMade') === 'false')
                                {
                                    localStorage.setItem('changesMade', 'true')
                                }
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
                                                url: '/magento/pub/razorpay/payment/FormDataAnalytics',
                                                type: 'POST',
                                                dataType: 'json',
                                                data: { 
                                                    event: 'Form Field Validation Error', 
                                                    properties: validationData 
                                                },
                                                success: function(result, status, xhr) {
                                                    // console.log('success')
                                                },
                                                error: function(xhr, status, error) {
                                                    // console.log('fail')
                                                    // console.log(error)
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
                                            //console.log(validationData);   
                                            
                                            // Send event 
                                            $.ajax({
                                                url: '/magento/pub/razorpay/payment/FormDataAnalytics',
                                                type: 'POST',
                                                dataType: 'json',
                                                data: { 
                                                    event: 'Form Field Validation Error', 
                                                    properties: validationData 
                                                },
                                                success: function(result, status, xhr) {
                                                    // console.log('success')
                                                },
                                                error: function(xhr, status, error) {
                                                    // console.log('fail')
                                                    // console.log(error)
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
                                let resultMapObject = {}

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
                                                resultMapObject[row.substring(20)] = {missing: 'true'}
                                            }
                                        }
                                    }
                                    
                                }
                                //console.log(resultMapObject)
                                return resultMapObject
                            }   

                            // After Save Config click, send missing/error field data
                            if (elementId == 'payment_us_razorpay_sort_order')
                            {
                                $('#save').click(function(){
                                    let result = getMissingFormFields()
                                    //console.log(result)
                                    //console.log(result.size)
                                    if (result && result.size>0){
                                        // Send event
                                        $.ajax({
                                            url: '/magento/pub/razorpay/payment/FormDataAnalytics',
                                            type: 'POST',
                                            async: false,
                                            dataType: 'json',
                                            data: { 
                                                event : 'Empty Form Fields',
                                                properties : result
                                            },
                                            beforeSend: function(xhr){
                                                //Empty to remove magento's default handler
                                            },
                                            success: function(result, status, xhr) {
                                                //console.log('success')
                                            },
                                            error: function(xhr, status, error) {
                                                //console.log('fail')
                                            }
                                        })
                                    }

                                    if (!localStorage.getItem('saveConfigClicked')){
                                        localStorage.setItem('saveConfigClicked', 'yes')
                                    }
                                    else{
                                        // Check if any field modified
                                        if (localStorage.getItem('changesMade') === 'true')
                                        {
                                            localStorage.setItem('changesMade', 'false') 
                                            // Send event
                                            $.ajax({
                                                url: '/magento/pub/razorpay/payment/FormDataAnalytics',
                                                type: 'POST',
                                                async: false,
                                                dataType: 'json',
                                                data: { 
                                                    event : 'Config Modified',
                                                    properties : {'store_name': storeName}
                                                },
                                                beforeSend: function(xhr){
                                                    //Empty to remove magento's default handler
                                                },
                                                success: function(result, status, xhr) {
                                                    //console.log('success')
                                                },
                                                error: function(xhr, status, error) {
                                                    // console.log('fail')
                                                    // console.log(error)
                                                }
                                            })   
                                        }
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