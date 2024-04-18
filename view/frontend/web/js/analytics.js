define(['jquery'], function ($) {

    let lineItemsArr = [];
    let checkoutCart = {};

    let enabledAnalyticsTools = {
        // clevertap: false,
        // webengage: false,
        ga4: false,
        // googleAds: false,
    };

    const GA4 = {
        // GTAG: currentScript.getAttribute('data-ga4-id') || '',
        COUPON: '',
        // CUSTOM_EVENTS:
        // currentScript.getAttribute('data-ga4-custom-event-names') === 'true',
        EVENTS: {
            BEGIN_CHECKOUT: 'begin_checkout',
            ADD_SHIPPING_INFO: 'add_shipping_info',
            ADD_PAYMENT_INFO: 'add_payment_info',
            SELECT_PROMOTION: 'select_promotion',
            PURCHASE: 'purchase',
        },
        handlers: {
            setCoupon: code => (GA4.COUPON = code),
            resetCoupon: () => (GA4.COUPON = ''),
            getCustomEventName: e => {
                const CUSTOM_EVENTS = {
                    [GA4.EVENTS.BEGIN_CHECKOUT]: 'beginCheckout',
                    [GA4.EVENTS.ADD_SHIPPING_INFO]: 'addShippingInfo',
                    [GA4.EVENTS.ADD_PAYMENT_INFO]: 'addPaymentInfo',
                    [GA4.EVENTS.SELECT_PROMOTION]: 'selectPromotion',
                    [GA4.EVENTS.PURCHASE]: 'Purchase',
                };
                return CUSTOM_EVENTS[e];
            },
            formatLineItems() {
                if (!lineItemsArr) return [];
                const items = lineItemsArr.map((element, index) => {
                    let itemCategories = {};
                    let cartItem = checkoutCart?.items?.[index];
                    cartItem.category.forEach((val, idx) => {
                        itemCategories = {...itemCategories, [`item_category${idx}`] :val}
                    });
                    const item = {
                        index,
                        item_id: element.sku || element.id,
                        item_name: element.title || element.name,
                        price: element.price.toFixed(2),
                        quantity: element.quantity,
                        discount: element.discount.toFixed(2),
                        item_brand: element.brand || element.vendor || '',
                        item_variant: element.id || '',
                        affiliation: cartItem.affiliation,
                        ...itemCategories,
                    };
                    if (GA4.COUPON) item.coupon = GA4.COUPON;
                    return item;
                });
                return items;
            },
            beginCheckout(data) {
                const payload = GA4.handlers.generatePayload(data);
                GA4.handlers.triggerEvent(GA4.EVENTS.BEGIN_CHECKOUT, payload);
            },
            addShippingInfo(data) {
                const payload = GA4.handlers.generatePayload(data);
                GA4.handlers.triggerEvent(GA4.EVENTS.ADD_SHIPPING_INFO, payload);
            },
            addPaymentInfo(data) {
                const payload = GA4.handlers.generatePayload(data);
                GA4.handlers.triggerEvent(GA4.EVENTS.ADD_PAYMENT_INFO, payload);
            },
            selectPromotion(data) {
                const payload = GA4.handlers.generatePayload(data);
                GA4.handlers.triggerEvent(GA4.EVENTS.SELECT_PROMOTION, payload);
            },
            purchase(data) {
                // debugger
                const {
                    order_id,
                    total_amount,
                    total_tax,
                    shipping_fee,
                    promotions,
                    cod_fee = 0,
                } = data;
                const items = GA4.handlers.formatLineItems() || [];
                const shipping = +shipping_fee + +(cod_fee ?? 0);
                const value = total_amount - shipping;
                GA4.handlers.triggerEvent(GA4.EVENTS.PURCHASE, {
                    transaction_id: order_id,
                    currency: getCurrency(),
                    items,
                    tax: total_tax,
                    value: (value / 100).toFixed(2),
                    shipping: (shipping / 100).toFixed(2),
                    coupon: data.promotions[0]?.code || GA4.COUPON || '',
                });
            },
            generatePayload(data) {
                if (data.appliedCouponCode)
                    GA4.handlers.setCoupon(data.appliedCouponCode);

                const items = GA4.handlers.formatLineItems() || [];
                const value = getTotalAmount(data) - getTotalDiscount(data);
                const payload = {
                    items,
                    value: isNaN(value) ? 0 : value,
                    currency: getCurrency(),
                };
                if (GA4.COUPON) payload.coupon = GA4.COUPON;
                if (data.paymentMode) payload.payment_type = data.paymentMode;
                return payload;
            },
            triggerEvent(event, payload) {
                if (GA4.GTAG) {
                    payload.send_to = GA4.GTAG;
                }
                if (GA4.CUSTOM_EVENTS) {
                    dataLayer.push({ecommerce: null});
                    dataLayer.push({
                        event: GA4.handlers.getCustomEventName(event),
                        ecommerce: payload,
                    });
                    return;
                }
                window.gtag('event', event, payload);
            },
        },
    };

    function getTotalAmount(data) {
        return data.isScriptCouponApplied && _isEmpty(checkoutCart)
            ? getOriginalAmount(data.lineItems) / 100
            : (data.totalAmount || checkoutCart.original_total_price) / 100;
    }

    function getTotalDiscount(data) {
        return data.isScriptCouponApplied && _isEmpty(checkoutCart)
            ? getDiscount(data.lineItems) / 100
            : ((checkoutCart.total_discount || 0) +
                (data.couponDiscount || data.couponDiscountValue || 0)) /
            100;
    }

    function getCurrency() {
        return checkoutCart?.items?.currency ?? "INR";
    }

    function _isEmpty(obj) {
        return Object.keys(obj).length === 0;
    }

    function getDiscount(lineItemsArray) {
        return lineItemsArray.reduce(
            (acc, curr) => acc + (curr.price - curr.offer_price),
            0
        );
    }

    function getOriginalAmount(lineItemsArray) {
        return lineItemsArray.reduce(
            (acc, curr) => acc + curr.quantity * +curr.price,
            0
        );
    }

    const ga4Handlers = {
        initiate: GA4.handlers.beginCheckout,
        shipping_selected: GA4.handlers.addShippingInfo,
        payment_initiated: GA4.handlers.addPaymentInfo,
        coupon_applied: GA4.handlers.selectPromotion,
        coupon_failed: GA4.handlers.resetCoupon,
        purchase: GA4.handlers.purchase,
    };

    let analyticsEvents = {
        ga4: ga4Handlers,
    };

    function sendAnalyticsEvents(eventName, data) {
        Object.entries(enabledAnalyticsTools).forEach(method => {
            const toolName = method[0];
            const enabled = method[1];
            if (
                enabled &&
                typeof analyticsEvents[toolName][eventName] === 'function'
            ) {
                try {
                    analyticsEvents[toolName][eventName](data);
                } catch (e) {
                    console.error(e);
                }
            }
        });
    }

    const MagicMxAnalytics = {
        initiate(data, checkoutCartObj = {}, analyticsTools) {
            checkoutCart = { ...checkoutCartObj };
            enabledAnalyticsTools = {...enabledAnalyticsTools, ...analyticsTools};
            data.lineItems.forEach(lineItem => {
                lineItemsArr.push({
                    name: lineItem.name,
                    id: lineItem.variant_id,
                    sku: lineItem.sku,
                    price: lineItem.offer_price / 100,
                    mrp: lineItem.price / 100,
                    quantity: lineItem.quantity,
                    cropped_URL: lineItem.product_url?.slice(1),
                    // todo: prfs returniong null
                    product_url_link: `${document.location.origin}${lineItem.product_url}`,
                    image: lineItem.image_url?.replace('.jpg', '_530x@2x.jpg'),
                    brand: document.location.host,
                    currency: getCurrency(),
                    size: 'NA',
                    discount: (lineItem.price - lineItem.offer_price) / 100,
                });
            });

            sendAnalyticsEvents('initiate', data);
        },

        shipping_selected(data) {
            sendAnalyticsEvents('shipping_selected', data);
        },

        payment_initiated(data) {
            sendAnalyticsEvents('payment_initiated', data);
        },

        coupon_applied(data) {
            sendAnalyticsEvents('coupon_applied', data);
        },

        coupon_failed(data) {
            sendAnalyticsEvents('coupon_failed', data);
        },

        payment_failed(data) {
            sendAnalyticsEvents('payment_failed', data);
        },

        purchase(data = {}) {
            return Object.entries(enabledAnalyticsTools).map(method => {
                const toolName = method[0];
                const enabled = method[1];
                if (
                    enabled &&
                    typeof analyticsEvents[toolName].purchase === 'function'
                ) {
                    return analyticsEvents[toolName].purchase(data);
                }
                return Promise.resolve();
            });
        },

        user_data(data) {
            sendAnalyticsEvents('user_data', data);
        },

        reset() {
            lineItemsArr = [];
        },
    };

    return {
        MagicMxAnalytics: MagicMxAnalytics
    };
});