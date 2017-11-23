define(
    [
        'Magento_Checkout/js/view/payment/default',
        'Magento_Checkout/js/model/quote',
        'jquery',
        'ko',
        'Magento_Checkout/js/model/payment/additional-validators',
        'Magento_Checkout/js/action/set-payment-information',
        'mage/url',
        'Magento_Customer/js/model/customer',
        'Magento_Checkout/js/action/place-order',
        'Magento_Checkout/js/model/full-screen-loader',
        'Magento_Ui/js/model/messageList',
        'Magento_Checkout/js/action/redirect-on-success'
    ],
    function (Component, quote, $, ko, additionalValidators, setPaymentInformationAction, url, customer, placeOrderAction, fullScreenLoader, messageList, redirectOnSuccessAction) {
        'use strict';

        return Component.extend({
            redirectAfterPlaceOrder: false,
            defaults: {
                template: 'Razorpay_Magento/payment/razorpay-form',
                razorpayDataFrameLoaded: false,
                rzp_response: {}
            },
            getMerchantName: function() {
                return window.checkoutConfig.payment.razorpay.merchant_name;
            },

            getKeyId: function() {
                return window.checkoutConfig.payment.razorpay.key_id;
            },

            context: function() {
                return this;
            },

            isShowLegend: function() {
                return true;
            },

            getCode: function() {
                return 'razorpay';
            },

            isActive: function() {
                return true;
            },

            isAvailable: function() {
                return this.razorpayDataFrameLoaded;
            },

            handleError: function (error) {
                if (_.isObject(error)) {
                    this.messageContainer.addErrorMessage(error);
                } else {
                    this.messageContainer.addErrorMessage({
                        message: error
                    });
                }
            },

            initObservable: function() {
                var self = this;

                if(!this.razorpayDataFrameLoaded) {
                    $.getScript("https://checkout.razorpay.com/v1/checkout.js", function() {
                        this.razorpayDataFrameLoaded = true;
                    });
                }

                return this;
            },

            afterPlaceOrder: function() {
                var self = this,
                    billing_address,
                    rzp_order_id;

                fullScreenLoader.startLoader();
                this.messageContainer.clear();

                this.amount = quote.totals()['base_grand_total'] * 100;
                billing_address = quote.billingAddress();

                this.user = {
                    name: billing_address.firstname + ' ' + billing_address.lastname,
                    contact: billing_address.telephone,
                };

                if (!customer.isLoggedIn()) {
                    this.user.email = quote.guestEmail;
                }
                else
                {
                    this.user.email = customer.customerData.email;
                }

                this.isPaymentProcessing = $.Deferred();

                $.when(this.isPaymentProcessing).fail(
                    function (result) {
                        self.handleError(result);
                    }
                );

                self.getRzpOrderId();

                return;
            },

            getRzpOrderId: function () {
                var self = this;

                $.ajax({
                    type: 'POST',
                    url: url.build('razorpay/payment/order'),

                    /**
                     * Success callback
                     * @param {Object} response
                     */
                    success: function (response) {
                        fullScreenLoader.stopLoader();
                        if (response.success) {
                            self.renderIframe(response);
                        } else {
                            self.isPaymentProcessing.reject(response.message);
                        }
                    },

                    /**
                     * Error callback
                     * @param {*} response
                     */
                    error: function (response) {
                        fullScreenLoader.stopLoader();
                        self.isPaymentProcessing.reject(response.message);
                    }
                });
            },

            renderIframe: function(data) {
                var self = this;

                this.merchant_order_id = data.order_id;

                var options = {
                    key: self.getKeyId(),
                    name: self.getMerchantName(),
                    amount: data.amount,
                    handler: function (data) {
                        self.rzp_response = data;
                        $.ajax({
                            type: 'POST',
                            url: url.build('razorpay/payment/authorize'),
                            data: data,

                            success: function() {
                                // On successful signature verification,
                                // we redirect to success page
                                redirectOnSuccessAction.execute();
                            },

                            error: function() {
                                // On signature verification failure,
                                // we redirect to failure page
                                $.mage.redirect('onepage/failure');
                            }
                        });
                    },
                    order_id: data.rzp_order,
                    modal: {
                        ondismiss: function() {
                            // TODO: Is this case handled?
                            self.isPaymentProcessing.reject("Payment Closed");
                        }
                    },
                    notes: {
                        merchant_order_id: data.order_id
                    },
                    prefill: {
                        name: this.user.name,
                        contact: this.user.contact,
                        email: this.user.email
                    }
                };

                if (data.quote_currency !== 'INR')
                {
                    options.display_currency = data.quote_currency;
                    options.display_amount = data.quote_amount;
                }

                this.rzp = new Razorpay(options);

                // TODO: Payment screen opens and then redirection happens

                this.rzp.open();
            },

            getData: function() {
                return {
                    "method": this.item.method,
                    "po_number": null,
                    "additional_data": {
                        rzp_payment_id: this.rzp_response.razorpay_payment_id,
                        order_id: this.merchant_order_id,
                        rzp_signature: this.rzp_response.razorpay_signature
                    }
                };
            }
        });
    }
);
