(function () {
  "use strict";

  var oneClickButton = {
    options: {
      addToFormSelector: "#cart-occ-div",
      actionSelector: ".action",
      buttonTemplateSelector: "#cart-occ-div",
      buttonSelector: "#cart-occ-template",
      confirmationSelector: "#one-click-confirmation",
      spinnerId: "magic-razorpay-spinner",
    },

    cookie: {
      name: "occ_status",
      enabled: "enabled",
      disabled: "disabled"
    },

    requestType: {
      JSON: "json",
      FORM_DATA: "formData",
    },

    init() {
      if (this.getCookie(this.cookie.name) !== this.cookie.disabled) {
        this._createButton();
      }
    },

    _createButton() {
      var buttonTemplate = document.querySelector(this.options.buttonTemplateSelector).innerHTML;
      this._bindButton();
    },

    _bindButton() {
      var button = document.querySelector(this.options.buttonSelector);
      if (button) {
        button.addEventListener("click", this._cartCheckout.bind(this));
      }
    },

    _cartCheckout() {
      this._toggleButton(false);
      this._toggleLoader(true);

      var placeOrderUrl = this.buildUrl("razorpay/oneclick/placeorder");
      var data = { page: "cart" };

      this.ajaxRequest(placeOrderUrl, "POST", data, this.requestType.FORM_DATA, 
        this.renderIframe.bind(this), 
        (request) => {
          this._toggleLoader(false);
          this._handleError(request);
          this._toggleButton(true);
        }
      );
    },

    orderSuccess(data) {
      this._toggleButton(true);
      var completeOrderUrl = this.buildUrl("razorpay/oneclick/completeorder");

      this.ajaxRequest(completeOrderUrl, "POST", data, this.requestType.JSON, 
        (response) => {
          this._toggleLoader(true);
          if (window.analytics?.MagicMxAnalytics?.purchase) {
            analytics.MagicMxAnalytics.purchase({ ...response, merchantAnalyticsConfigs: {} }).finally(() => {
              window.location.href = this.buildUrl("checkout/onepage/success");
            });
          } else {
            window.location.href = this.buildUrl("checkout/onepage/success");
          }
        },
        () => {
          this._toggleLoader(false);
          window.location.href = this.buildUrl("checkout/onepage/failure");
        }
      );
    },

    abandonedCart(rzp_order_id) {
      var url = this.buildUrl("razorpay/oneclick/abandonedQuote");
      var data = { rzp_order_id };

      this.ajaxRequest(url, "POST", data, this.requestType.FORM_DATA, 
        (response) => console.log(response), 
        (error) => console.error("Payment complete fail", error)
      );
    },

    renderIframe(data) {
      var rzpOptions = {
        key: data.rzp_key_id,
        amount: data.totalAmount,
        one_click_checkout: true,
        show_coupons: data.allow_coupon_application,
        handler: this.orderSuccess.bind(this),
        order_id: data.rzp_order_id,
        modal: { ondismiss: () => this.abandonedCart(data.rzp_order_id) },
        _: { integration: "magento", integration_type: "plugin" },
        prefill: { name: "", contact: "", email: "" },
      };

      var rzp = new Razorpay(rzpOptions);
      rzp.open();
    },

    _toggleButton(enabled) {
      var button = document.querySelector(this.options.buttonTemplateSelector);
      if (button) button.classList.toggle("disabled", !enabled);
    },

    _toggleLoader(show) {
      show ? this._showLoader() : this._hideLoader();
    },

    _showLoader() {
      if (window.Razorpay?.showLoader) {
        window.Razorpay.showLoader();
      } else if (!this._isLoaderVisible()) {
        document.body.appendChild(this._createLoaderTemplate(this.options.spinnerId));
      }
    },

    _hideLoader() {
      var spinner = document.getElementById(this.options.spinnerId);
      if (spinner) spinner.remove();
    },

    _isLoaderVisible() {
      return document.getElementById(this.options.spinnerId) !== null;
    },

    _createLoaderTemplate(id) {
      var template = `
        <div id="${id}" style="position: fixed; top: 0; left: 0; z-index: 2147483647; width: 100%; height: 100%; background: rgba(0,0,0,0.4);">
          <style>
            @keyframes rotate { 0% { transform: rotate(0); } 100% { transform: rotate(360deg); } }
            #${id}::after {
              content: "";
              position: absolute;
              width: 80px;
              height: 80px;
              left: calc(50% - 40px);
              top: calc(50% - 40px);
              border-radius: 50%;
              border: 4px solid rgba(59, 124, 245, 1);
              border-color: rgb(59, 124, 245) transparent rgb(59, 124, 245) rgb(59, 124, 245);
              animation: rotate 1s linear infinite;
            }
          </style>
        </div>`;
      return document.createRange().createContextualFragment(template);
    },

    getCookie(name) {
      return document.cookie.split("; ").find(row => row.startsWith(name + "="))?.split("=")[1];
    },

    buildUrl(path) {
      return `/${path}`;
    },

    ajaxRequest(url, method, data, type, successCallback, errorCallback) {
      var xhr = new XMLHttpRequest();
      xhr.open(method, url, true);

      if (type === this.requestType.JSON) {
        xhr.setRequestHeader("Content-Type", "application/json");
        xhr.send(JSON.stringify(data));
      } else if (type === this.requestType.FORM_DATA) {
        var formData = new FormData();
        Object.entries(data).forEach(([key, value]) => formData.append(key, value));
        xhr.send(formData);
      } else {
        throw new Error("Unsupported type: " + type);
      }

      xhr.onload = () => xhr.status >= 200 && xhr.status < 300 
        ? successCallback(JSON.parse(xhr.responseText))
        : errorCallback(xhr);

      xhr.onerror = () => errorCallback(xhr);
    },

    _handleError() {
      this._toggleButton(true);
    },
  };

  document.addEventListener("DOMContentLoaded", oneClickButton.init.bind(oneClickButton));
})();
