define([
    'jquery',
    'mage/translate',
    'Magento_Ui/js/modal/alert',
    'Magento_Checkout/js/model/full-screen-loader',
    'mage/url',
    'Razorpay_Magento/js/analytics',
    'underscore',
    'mage/cookies'
], function ($, $t, alert, fullScreenLoader, url, analytics, _) {
    "use strict";

    $.widget('pmclain.oneClickButton', {
        options: {
            addToFormSelector: '#product_addtocart_form',
            actionSelector: '.actions',
            buttonTemplateSelector: '#occ-template',
            buttonSelector: '#product-oneclick-button',
            confirmationSelector: '#one-click-confirmation',
            spinnerId: 'magic-razorpay-spinner'
        },

        cookie: 'occ_status',
        cookieEnabled: 'enabled',
        cookieDisabled: 'disabled',

        _create: function () {
            this._initButton();
        },

        _initButton: function () {
            var self = this;

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
            self.toggleLoader(true);

            var placeOrder = url.build('razorpay/oneclick/placeorder', {})

            $.ajax({
                url: placeOrder,
                data: self._parent().serialize(),
                type: 'POST',
                dataType: 'json',
                success: function (data) {
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

            var completeOrder = url.build('razorpay/oneclick/completeorder', {})

            $.ajax({
                url: completeOrder,
                data: data,
                type: 'POST',
                dataType: 'json',
                success: async function (data) {
                    self.toggleLoader(true);

                    if (analytics.MagicMxAnalytics.purchase) {
                        try {
                            await Promise.all(analytics.MagicMxAnalytics.purchase({
                                ...data,
                                merchantAnalyticsConfigs: {},
                            }));
                        } catch (error) {
                            console.error(error);
                        }
                    }
                    var successUrl = url.build('checkout/onepage/success', {})
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
                key: data.rzp_key_id,
                name: '',
                amount: data.totalAmount,
                one_click_checkout: true,
                show_coupons: data.allow_coupon_application,
                handler: function (data) {
                    self.toggleLoader(true);
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
                    name: '',
                    contact: '',
                    email: ''
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

            this.rzp.on('mx-analytics', eventData => {
                console.log(analytics.MagicMxAnalytics);
                console.log("mx-analytics data", eventData);
                const enabledAnalyticsTools = {
                    ga4: window.gtag,
                };

                analytics.MagicMxAnalytics?.[eventData.event]?.(
                    {
                        ...eventData,
                    },
                    data,
                    enabledAnalyticsTools
                );
            });
            self.toggleLoader(false);

            this.rzp.open();
        },

        _orderError: function (request) {
            console.log(request);
            this.enableButton();
        },

        _disableButton: function () {
            var button = this._parent().find(this.options.buttonSelector);
            button.addClass('disabled');
        },

        enableButton: function () {
            var button = this._parent().find(this.options.buttonSelector);
            button.removeClass('disabled');
        },

        hide: function() {
            document.getElementById(this.options.spinnerId)?.remove();
            // unblockPageScroll();
        },

        show: function() {
            if (typeof (window.Razorpay)?.showLoader === 'function') {
                (window.Razorpay).showLoader?.();
            } else {
                if (this.isVisible()) {
                    return;
                }
                document.body.appendChild(this.createTemplate(this.options.spinnerId));
            }
            // blockPageScroll();
        },

        isVisible: function() {
            return document.getElementById(this.options.spinnerId) !== null;
        },

        createTemplate: function(id) {
            const templateStr = `
              <div id="${id}" style="position: fixed; top: 0; left: 0; z-index: 2147483647; width: 100%; height: 100%; background: rgb(0,0,0); opacity: 0.4;">
              <style>
                @keyframes rotate { 0% { transform: rotate(0); } 100% { transform: rotate(360deg); } }
                #${id}::after {
                  content: "";
                  --length: 80px;
                  position: absolute;
                  width: var(--length);
                  height: var(--length);
                  left: calc(50% - var(--length) / 2);
                  top: calc(50% - var(--length) / 2);
                  border-radius: 50%;
                  border: 4px solid;
                  border-color: rgb(59, 124, 245) transparent rgb(59, 124, 245) rgb(59, 124, 245) !important;
                  animation: 1s linear 0s infinite normal none running rotate;
                  box-sizing: border-box;
                }
               </style>
              </div>`;

            return document.createRange().createContextualFragment(templateStr);
        },

        toggleLoader: function(flag) {
            var self = this;

            flag ? self.show() : self.hide();
        }
    });

    return $.pmclain.oneClickButton;
});