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
        'Magento_Customer/js/customer-data'
    ],
    function (Component, quote, $, ko, additionalValidators, setPaymentInformationAction, url, customer, placeOrderAction, fullScreenLoader, messageList,customerData) {
        'use strict';

        return Component.extend({
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
                var self = this._super();              //Resolves UI Error on Checkout


                if(!self.razorpayDataFrameLoaded) {
                    $.getScript("https://checkout.razorpay.com/v1/checkout.js", function() {
                        self.razorpayDataFrameLoaded = true;
                    });
                }

                return self;
            },

            placeOrder: function (event) {
                var self = this;

                if (event) {
                    event.preventDefault();
                }

                if(!self.orderId) {
                    this.isPlaceOrderActionAllowed(false);
                    this.getPlaceOrderDeferredObject()
                        .fail(
                            function () {
                                self.isPlaceOrderActionAllowed(true);
                            }
                        ).done(
                        function (orderId) {
                            self.getRzpOrderId(orderId);
                            self.orderId =  orderId;
                        }
                    );
                }else{
                    self.getRzpOrderId(self.orderId);
                }

                return;

            },

            doCheckoutPayment: function(rzpResponse){

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

                self.renderIframe(rzpResponse);

                this.isPaymentProcessing = $.Deferred();

                $.when(this.isPaymentProcessing).fail(
                    function (result) {
                        self.handleError(result);
                    }
                );

                return;

            },

            /**
             * @override
             */
             /** Process Payment */
            preparePayment: function (context, event) {

                if(!additionalValidators.validate()) {   //Resolve checkout aggreement accept error
                    return false;
                }
                fullScreenLoader.startLoader();
                this.placeOrder(event);

                return;

            },


            getRzpOrderId: function (orderId) {
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
                            if (response.is_hosted) {
                                self.renderHosted(response);
                            } else {
                                self.doCheckoutPayment(response);
                            }
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
                    timeout: 720,
                    handler: function (data) {
                        self.rzp_response = data;
                        self.validateOrder(data);
                    },
                    callback_url: url.build('razorpay/payment/callback?order_id=' + data.order_id),
                    order_id: data.rzp_order,
                    modal: {
                        ondismiss: function(data) {
                            //reset the cart
                            self.resetCart(data);
                            fullScreenLoader.stopLoader();
                            self.isPaymentProcessing.reject("Payment Closed");
                            self.isPlaceOrderActionAllowed(true);
                        }
                    },
                    notes: {
                        merchant_order_id: data.order_id,
                    },
                    prefill: {
                        name: this.user.name,
                        contact: this.user.contact,
                        email: this.user.email
                    },
                    _: {
                        integration: 'magento',
                        integration_version: data.module_version,
                        integration_parent_version: data.maze_version,
                        integration_type: 'plugin',
                    }
                };

                if (data.quote_currency !== 'INR')
                {
                    options.display_currency = data.quote_currency;
                    options.display_amount = data.quote_amount;
                }

                this.rzp = new Razorpay(options);

                this.rzp.open();
            },

            validateOrder: function(data){

                var self = this;
                fullScreenLoader.startLoader();

                $.ajax({
                    type: 'POST',
                    url: url.build('razorpay/payment/validate'),
                    data: JSON.stringify(data),
                    dataType: 'json',
                    contentType: 'application/json',

                    /**
                     * Success callback
                     * @param {Object} response
                     */
                    success: function (response) {
                        fullScreenLoader.stopLoader();

                        require('Magento_Customer/js/customer-data').reload(['cart']);

                        if (!response.success) {
                            fullScreenLoader.stopLoader();
                            self.isPaymentProcessing.reject(response.message);
                            self.handleError(response);
                            self.isPlaceOrderActionAllowed(true);
                        }

                        window.location.replace(url.build(response.redirect_url));
                    },

                    /**
                     * Error callback
                     * @param {*} response
                     */
                    error: function (response) {
                        fullScreenLoader.stopLoader();
                        self.isPaymentProcessing.reject(response.message);
                        self.handleError(response);
                    }
                });

            },

            resetCart: function(data){

                var self = this;
                fullScreenLoader.startLoader();

                $.ajax({
                    type: 'POST',
                    url: url.build('razorpay/payment/resetCart'),
                    data: JSON.stringify(data),
                    dataType: 'json',
                    contentType: 'application/json',

                    /**
                     * Success callback
                     * @param {Object} response
                     */
                    success: function (response) {
                        fullScreenLoader.stopLoader();
                        self.isPaymentProcessing.reject('order_failed');
                        require('Magento_Customer/js/customer-data').reload(['cart']);

                        if (response.success) {
                            window.location.replace(url.build(response.redirect_url));
                        }
                    },

                    /**
                     * Error callback
                     * @param {*} response
                     */
                    error: function (response) {
                        fullScreenLoader.stopLoader();
                        self.isPaymentProcessing.reject(response.message);
                        self.handleError(response);
                    }
                });

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
            },

            createInputFieldsFromOptions: function (options, form) {
                var self = this;

                function visitNestedOption(options, parentKey) {
                    for (let curKey in options) {
                      if (options.hasOwnProperty(curKey)) {
                        const value = options[curKey];
                        let prepareKey = parentKey ? `${parentKey}[${curKey}]` : curKey;

                        if (typeof value === 'object') {
                          visitNestedOption(value, prepareKey);
                        } else {
                          // Exception: Rename key -> key_id (merchant key)
                          if (prepareKey === 'key') {
                            prepareKey = 'key_id';
                          }

                          form.appendChild(self.createHiddenInput(prepareKey, value));
                        }
                      }
                    }
                }
              visitNestedOption(options);
            },

            createHiddenInput: function(key, value) {
              var input = document.createElement('input');

              input.type = 'hidden';
              input.name = key;
              input.value = value;

              return input;
            },

            renderHosted: function(data) {
                var self = this,
                billing_address;
               
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

                this.merchant_order_id = data.order_id;
               
                var opts = {
                    key: self.getKeyId(),
                    name: self.getMerchantName(),
                    amount: data.amount,
                    order_id: data.rzp_order,
                    notes: {
                        merchant_order_id: data.order_id
                    },
                    prefill: {
                        name: this.user.name,
                        contact: this.user.contact,
                        email: this.user.email
                    },
                    callback_url: url.build('razorpay/payment/callback?order_id=' + data.order_id),
                    cancel_url  : url.build('checkout/cart'),
                    _: {
                        integration: 'magento',
                        integration_version: data.module_version,
                        integration_parent_version: data.maze_version,
                    },
                    __referer : window.location.href
                }
                const options = JSON.parse(JSON.stringify(opts));

                var form = document.createElement('form'),
                    method = 'POST',
                    input,
                    key;

                form.method = method;
                form.action = data.embedded_url;

                self.createInputFieldsFromOptions(options, form);

                document.body.appendChild(form);

                customerData.invalidate(['cart']);

                form.submit();
            }
        });
    }


);
