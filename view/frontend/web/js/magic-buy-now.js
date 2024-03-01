define([
    'jquery',
    'mage/translate',
    'Magento_Ui/js/modal/alert',
    'Magento_Checkout/js/model/full-screen-loader',
    'mage/url',
    'underscore',
    'mage/cookies'
], function ($, $t, alert, fullScreenLoader, url, _) {
    "use strict";

    $.widget('pmclain.oneClickButton', {
        options: {
            addToFormSelector: '#product_addtocart_form',
            isAvailableUrl: '',
            submitUrl: '',
            callbackURL: '',
            key: '',
            actionSelector: '.actions',
            buttonTemplateSelector: '#occ-template',
            buttonSelector: '#product-oneclick-button',
            confirmationSelector: '#one-click-confirmation'
        },

        cookie: 'occ_status',
        cookieEnabled: 'enabled',
        cookieDisabled: 'disabled',

        _create: function () {
            this._initButton();
        },

        _initButton: function () {
            var self = this;

            console.log("-----");
            switch ($.mage.cookies.get(this.cookie)) {
                case this.cookieEnabled:
                    this._createButton();
                    break;
                case this.cookieDisabled:
                    break;
                default:
                    self._createButton();
            }
        },

        initObservable: function() {
            var self = this._super();              //Resolves UI Error on Checkout

            $.getScript("https://checkout.razorpay.com/v1/magic-checkout.js", function() {
                self.razorpayDataFrameLoaded = true;
            });

            return self;
        },

        _createButton: function () {
            var button = $(this.options.buttonTemplateSelector).html();
            this._parent().find(this.options.actionSelector).prepend(button);
            this._bind();
        },

        _bind: function () {
            var self = this;
            this._parent().find(self.options.buttonSelector).on('click touch', function() {
                if (self._parent().valid()) {
                    self._buyNow();
                }
            });
        },

        _parent: function () {
            return $(this.options.addToFormSelector);
        },

        _buyNow: function () {
            var self = this;
            self._disableButton();

            fullScreenLoader.startLoader();

            $.ajax({
                url: self.options.submitUrl,
                data: self._parent().serialize(),
                type: 'POST',
                dataType: 'json',
                showLoader: true,
                success: function (data) {
                    console.log("*******")
                    console.log(data)

                    self.renderIframe(data);
                },
                error: function (request) {
                    self._orderError(request);
                    self.enableButton();
                }
            })
        },

        orderSuccess: function (data) {
            var self = this;
            self.enableButton();
            fullScreenLoader.startLoader();

            $.ajax({
                url: self.options.callbackURL,
                data: data,
                type: 'POST',
                dataType: 'json',
                showLoader: true,
                success: function (data) {
                    console.log("Payment complete data")
                    console.log(data)
                    var successUrl = url.build('checkout/onepage/success', {})
                    console.log(successUrl)
                    window.location.href = successUrl;
                },
                error: function (error) {
                    console.log("Payment complete fail")
                    console.log(error)
                }
            })
        },

        renderIframe: function(data) {
            var self = this;

            var options = {
                key: self.options.key,
                name: 'Razorpay',
                amount: data.totalAmount,
                handler: function (data) {
                    fullScreenLoader.startLoader();

                    self.orderSuccess(data);
                },
                order_id: data.rzp_order_id,
                modal: {
                    ondismiss: function(data) {
                        //reset the cart
                        // self.resetCart(data);
                        // fullScreenLoader.stopLoader();
                        // self.isPaymentProcessing.reject("Payment Closed");
                        // self.isPlaceOrderActionAllowed(true);
                        self.enableButton();

                    }
                },
                notes: {
                },
                // callback_url : self.options.callbackURL,
                prefill: {
                    name: 'Chetan',
                    contact: '7795619055',
                    email: 'chetan@mail.com'
                },
                _: {
                    integration: 'magento',
                    // integration_version: '',
                    // integration_parent_version: data.maze_version,
                    integration_type: 'plugin',
                }
            };

            // if (data.quote_currency !== 'INR')
            // {
            //     options.display_currency = data.quote_currency;
            //     options.display_amount = data.quote_amount;
            // }

            this.rzp = new Razorpay(options);

            this.rzp.open();
        },

        _getOrderTemplate: function (order) {
            _.templateSettings.variable = 'order';

            var template = _.template($('script.order-template').html());
            var output = template(order);

            delete _.templateSettings.variable;

            return output;
        },

        _orderError: function (request) {
            console.log(request);
            this.enableButton();
        },

        _disableButton: function () {
            var button = this._parent().find(this.options.buttonSelector);
            button.addClass('disabled');
            button.find('span').text($t('One Click Checkout'));
            button.attr('title', $t('One Click Checkout'));
        },

        _afterOrderButton: function () {
            var button = this._parent().find(this.options.buttonSelector);
            button.find('span').text($t('Purchased'));
            button.attr('title', $t('Purchased'));
        },

        enableButton: function () {
            var button = this._parent().find(this.options.buttonSelector);
            button.removeClass('disabled');
            button.find('span').text($t('One Click Checkout'));
            button.attr('title', $t('One Click Checkout'));
        }
    });

    return $.pmclain.oneClickButton;
});