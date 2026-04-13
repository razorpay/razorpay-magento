(function () {
  "use strict";

  const oneClickButton = {
    options: {
      ADD_TO_CART_FORM_SELECTOR: "#product_addtocart_form",
      BUY_NOW_BUTTON_SELECTOR: "#buy-now-magic",
      CART_BUTTON_SELECTOR: "#cart-occ-template",
      SPINNER_ID: "magic-razorpay-spinner",
    },

    cookie: {
      STATUS: "occ_status",
      DISABLED: "disabled",
    },

    requestType: {
      JSON: "json",
      FORM_DATA: "formData",
      URL_ENCODED_FORM_DATA: "urlEncodedFormData",
    },

    init() {
      try {
        // Check if one-click checkout is not disabled
        if (this.getCookie(this.cookie.STATUS) !== this.cookie.DISABLED) {
          this.loadRazorpayScript();

          // Handle product page
          const buyNowButton = document.querySelector(
            this.options.BUY_NOW_BUTTON_SELECTOR
          );
          const productForm = document.querySelector(
            this.options.ADD_TO_CART_FORM_SELECTOR
          );
          if (buyNowButton && productForm) {
            this.setupProductPageButton(buyNowButton, productForm);
          }

          // Handle cart page
          this.setupCartPageButtonObserver();
        }
      } catch (error) {
        console.error("Error initializing one-click checkout:", error);
      }
    },

    loadRazorpayScript() {
      try {
        const script = document.createElement("script");
        script.src = "https://checkout.razorpay.com/v1/magic-checkout.js";
        script.async = true;
        document.head.appendChild(script);
      } catch (error) {
        console.error("Failed to load Razorpay script:", error);
      }
    },

    setupProductPageButton(button, form) {
      // Use native form validation
      form.setAttribute("novalidate", "");

      button.addEventListener("click", (event) => {
        event.preventDefault();
        event.stopPropagation();
        // Check form validity first, before checking viewport
        if (!form.checkValidity()) {
          form.reportValidity();
          return;
        }

        // If form is not partially in view, scroll to it
        if (!this.isPartiallyInViewport(form, 0.5)) {
          form.scrollIntoView({
            behavior: "smooth",
            block: "center",
          });
          return;
        }

        // Place order only if form is valid and in view
        this.placeOrderFromProductPage(form);
      });
    },

    isPartiallyInViewport(el, percentVisible = 0.5) {
      const rect = el.getBoundingClientRect();
      const windowHeight =
        window.innerHeight || document.documentElement.clientHeight;
      const windowWidth =
        window.innerWidth || document.documentElement.clientWidth;

      const vertInView =
        rect.top <= windowHeight && rect.top + rect.height >= 0;
      const horInView = rect.left <= windowWidth && rect.left + rect.width >= 0;

      const visibleHeight = Math.min(
        rect.height,
        windowHeight - Math.max(0, rect.top)
      );
      const visibleWidth = Math.min(
        rect.width,
        windowWidth - Math.max(0, rect.left)
      );

      const visibleArea = visibleHeight * visibleWidth;
      const totalArea = rect.height * rect.width;

      const percentageVisible = visibleArea / totalArea;

      return vertInView && horInView && percentageVisible >= percentVisible;
    },

    setupCartPageButtonObserver() {
      let listenerAdded = false;

      const observer = new MutationObserver(() => {
        try {
          const button = document.querySelector(
            this.options.CART_BUTTON_SELECTOR
          );
          if (button && !listenerAdded) {
            this.bindCartPageButton(button);
            listenerAdded = true;
            observer.disconnect();
          }
        } catch (error) {
          console.error("Error in button observer:", error);
          observer.disconnect();
        }
      });

      observer.observe(document.body, {
        childList: true,
        subtree: true,
      });
    },

    bindCartPageButton(button) {
      button.removeEventListener(
        "click",
        this.placeOrderFromCartPage.bind(this)
      );
      button.addEventListener("click", this.placeOrderFromCartPage.bind(this));
    },

    placeOrderFromProductPage(form) {
      try {
        this.toggleButton(false);
        this.showRazorpayLoader();

        const formData = new FormData(form);
        let data = Object.fromEntries(formData.entries());
        delete data.allconfsizetooltip;

        this.ajaxRequest(
          this.buildUrl("razorpay/oneclick/placeorder"),
          "POST",
          data,
          this.requestType.URL_ENCODED_FORM_DATA,
          this.renderIframe.bind(this),
          (request) => {
            this.handleError(request);
          }
        );
      } catch (error) {
        this.handleError(error);
      }
    },

    placeOrderFromCartPage() {
      try {
        this.toggleButton(false);
        this.showRazorpayLoader();

        var placeOrderUrl = this.buildUrl("razorpay/oneclick/placeorder");
        var data = {
          page: "cart",
          quoteId: window.magicCartConfig ? window.magicCartConfig.quoteId : "",
        };

        this.ajaxRequest(
          placeOrderUrl,
          "POST",
          data,
          this.requestType.FORM_DATA,
          this.renderIframe.bind(this),
          (request) => {
            this.handleError(request);
          }
        );
      } catch (error) {
        this.handleError(error);
      }
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
  prefill: { name: "", contact: "", email: "", coupon_code:data.coupon_code },
  };

      const rzp = new Razorpay(rzpOptions);
      this.toggleLoader(false);
      rzp.open();
    },

    orderSuccess(data) {
      try {
        this.toggleButton(true);
        this.toggleLoader(true);
        var completeOrderUrl = this.buildUrl("razorpay/oneclick/completeorder");

        this.ajaxRequest(
          completeOrderUrl,
          "POST",
          data,
          this.requestType.FORM_DATA,
          (response) => {
            if (window.analytics?.MagicMxAnalytics?.purchase) {
              analytics.MagicMxAnalytics.purchase({
                ...response,
                merchantAnalyticsConfigs: {},
              }).finally(() => {
                window.location.href = this.buildUrl(
                  "checkout/onepage/success"
                );
              });
            } else {
              window.location.href = this.buildUrl("checkout/onepage/success");
            }
          },
          (req) => {
            window.location.href = this.buildUrl("checkout/onepage/failure");
            this.handleError(req);
          }
        );
      } catch (error) {
        this.handleError(error);
      }
    },

    abandonedCart(rzp_order_id) {
      try {
        var url = this.buildUrl("razorpay/oneclick/abandonedQuote");
        var data = { rzp_order_id };

        this.ajaxRequest(
          url,
          "POST",
          data,
          this.requestType.FORM_DATA,
          (response) => console.log(response),
          (error) => this.handleError(error)
        );
      } catch (error) {
        this.handleError(error);
      }
    },

    toggleButton(enabled) {
      const buyNowButton = document.querySelector(
        this.options.BUY_NOW_BUTTON_SELECTOR
      );
      const cartButton = document.querySelector(
        this.options.CART_BUTTON_SELECTOR
      );

      if (buyNowButton) buyNowButton.classList.toggle("disabled", !enabled);
      if (cartButton) cartButton.classList.toggle("disabled", !enabled);
    },

    toggleLoader(show) {
      show ? this.showLoader() : this.hideLoader();
    },

    showLoader() {
      if (!this.isLoaderVisible())
        document.body.appendChild(
          this.createLoaderTemplate(this.options.SPINNER_ID)
        );
    },

    showRazorpayLoader() {
      if (window.Razorpay?.showLoader) {
        window.Razorpay.showLoader();
      }
    },

    hideLoader() {
      var spinner = document.getElementById(this.options.SPINNER_ID);
      if (spinner) spinner.remove();
    },

    isLoaderVisible() {
      return document.getElementById(this.options.SPINNER_ID) !== null;
    },

    handleError(err) {
      console.error(err);
      this.toggleLoader(false);
      this.toggleButton(true);
    },

    createLoaderTemplate(id) {
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
      return document.cookie
        .split("; ")
        .find((row) => row.startsWith(name + "="))
        ?.split("=")[1];
    },

    buildUrl(path) {
      return `/${path}`;
    },

    ajaxRequest(url, method, data, type, successCallback, errorCallback) {
      var xhr = new XMLHttpRequest();
      xhr.open(method, url, true);

      switch (type) {
        case this.requestType.JSON:
          xhr.setRequestHeader("Content-Type", "application/json");
          xhr.send(JSON.stringify(data));
          break;
        case this.requestType.FORM_DATA:
          var formData = new FormData();
          Object.entries(data).forEach(([key, value]) =>
            formData.append(key, value)
          );
          xhr.send(formData);
          break;
        case this.requestType.URL_ENCODED_FORM_DATA:
          xhr.setRequestHeader(
            "Content-Type",
            "application/x-www-form-urlencoded; charset=UTF-8"
          );
          xhr.send(new URLSearchParams(data).toString());
          break;
        default:
          throw new Error("Unsupported type: " + type);
      }

      xhr.onload = () =>
        xhr.status >= 200 && xhr.status < 300
          ? successCallback(JSON.parse(xhr.responseText))
          : errorCallback(xhr);

      xhr.onerror = () => errorCallback(xhr);
    },
  };

  document.addEventListener(
    "DOMContentLoaded",
    oneClickButton.init.bind(oneClickButton)
  );
})();