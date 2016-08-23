var RazorpayUtils = Class.create();
RazorpayUtils.prototype = {
    initialize: function(options) {
        this.options = options;
    },

    getMerchantName: function() {
        return this.options.merchant_mame;
    },

    placeOrder: function(onSuccess, onDismiss, formId, paymentIdField)
    {
        if (!formId) {
            formId = "razorpay";
        }
        if (!paymentIdField) {
            paymentIdField = "razorpay_payment_id";
        }

        var checkout;

        var options = {
            key: this.options.key_id,
            name: this.options.merchant_name,
            amount: this.options.base_amount,
            currency: this.options.currency,
            handler: onSuccess,
            order_id: this.options.razorpay_order_id,
            modal: {
                ondismiss: onDismiss
            },
            notes: {
                merchant_order_id: this.options.order_id
            },
            prefill: {
                name: this.options.customer_name,
                contact: this.options.customer_phone,
                email: this.options.customer_email
            }
        };

        if (this.options.quote_currency !== null &&
            this.options.quote_currency !== this.options.base_currency)
        {
            options['display_currency'] = this.options.quote_currency;
            options['display_amount'] = this.options.quote_amount;
        }

        checkout = new Razorpay(options);

        checkout.open();
    }
};
