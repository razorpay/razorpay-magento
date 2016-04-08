var RazorpayUtils = Class.create();
RazorpayUtils.prototype = {
    initialize: function(urls) {
        this.orderUrl = urls.orderUrl;
        this.keyId = '';
        this.merchantName = 'razorpay';
    },

    setKeyId: function(key) {
        this.keyId = key;
    },

    setMerchantName: function(merchant) {
        this.merchantName = merchant;
    },

    getMerchantName: function() {
        return this.merchantName;
    },

    isOrderSet: function() {
        return !!this.orderInfo;
    },

    createHiddenInput: function(attributes, formId){
        var element, attr, value;
        element = document.createElement('input');
        for (attr in attributes) {
            if (attributes.hasOwnProperty(attr)) {
                value = attributes[attr];
                element.setAttribute(attr, value);
            }
        }
        element.setAttribute('type', 'hidden');
        element.setAttribute('value', '');
        if ($(element.id)) {
            $(element.id).remove();
        }
        $(formId).appendChild(element);
    },

    createOrder: function(onSuccess, onFailure) {
        var self = this;

        function success(event) {
            self.orderInfo = event.responseJSON;

            if (typeof onSuccess === 'function') {
                onSuccess();
            }
        };

        new Ajax.Request(
            this.orderUrl,
            {
                method:'post',
                onSuccess: success,
                onFailure: onFailure
            }
        );
    },

    placeOrder: function(onSuccess, onUserClose, beforeStart, formId, paymentIdField)
    {
        var checkout;

        if (typeof beforeStart == 'function') {
            beforeStart();
        }

        this.createHiddenInput({name: "payment[" + paymentIdField +"]", id: paymentIdField}, formId);

        var options = {
            key: this.keyId,
            name: this.merchantName,
            amount: this.orderInfo.amount,
            handler: onSuccess,
            order_id: this.orderInfo.razorpay_order_id,
            modal: {
                ondismiss: function() {
                    onUserClose();
                }
            },
            notes: {
                merchant_order_id: this.orderInfo.order_id
            },
            prefill: {
                name: this.orderInfo.customer_name,
                contact: this.orderInfo.customer_phone,
                email: this.orderInfo.customer_email
            }
        };

        checkout = new Razorpay(options);

        checkout.open();
    }
};
