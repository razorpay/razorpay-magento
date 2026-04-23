# Context: Standard Checkout Flow

> For AI agents: This file provides deep context on the native Magento onepage checkout flow.
> Cross-reference with `docs/LLD.md#flow-1` for the sequence diagram.

---

## Entry Points

The checkout flow starts from two different AJAX requests Magento makes as the customer progresses through checkout steps:

### 1. `checkout_onepage_paymentmethod` (Payment Step)
When customer reaches the payment step, Magento renders blocks for this layout handle.
`order.phtml` template is loaded here — it overrides `Payment.prototype.save`.

**Why it overrides Payment.save:**
Magento's native `Payment.save()` immediately POSTs the selected payment method to the backend and moves to the next step. We need to inject an extra step (creating a Razorpay order) before Magento saves the payment selection. By overriding `Payment.save`, we:
1. Create a Razorpay order via AJAX first
2. Store the order info in `razorpayUtils.orderInfo`
3. THEN call the native AJAX request (passed as `makeNativeAjaxRequest`)

### 2. `checkout_onepage_savepayment` (Review Step)
After customer clicks "Continue" on payment step, this layout handle renders the review page blocks.
`placeorder.phtml` is loaded here — it intercepts the "Place Order" button.

**Why it intercepts the button:**
The native Magento "Place Order" button submits the checkout form directly. We need to:
1. First open the Razorpay modal for the customer to pay
2. Get the `razorpay_payment_id` from Razorpay
3. Inject it into the form as a hidden input
4. THEN submit the form (via `review.save()`)

---

## JavaScript Object Lifecycle

```
Page Load
  └─► RazorpayUtils initialized (setuputils.phtml)
        ├── orderUrl: '/razorpay/checkout/order'
        ├── keyId: 'rzp_live_xxx'
        └── merchantName: 'My Store'

Payment Step
  └─► Payment.save() overridden (order.phtml)
  └─► Customer selects Razorpay + clicks Continue
  └─► RazorpayUtils.createOrder() called
        └─► orderInfo stored:
              {razorpay_order_id, amount, currency, customer_*}

Review Step
  └─► Button listener attached (placeorder.phtml)
  └─► Customer clicks "Place Order"
  └─► RazorpayUtils.placeOrder() called
        └─► Razorpay modal opens (uses stored orderInfo)
  └─► Customer pays
  └─► razorpay_payment_id stored in form
  └─► review.save() → form submitted
```

---

## The Hidden Input Pattern

When the Razorpay modal successfully returns a `razorpay_payment_id`, we inject it into the checkout form as a hidden input:

```javascript
// Created by createHiddenInput in RazorpayUtils.placeOrder()
<input type="hidden" name="payment[razorpay_payment_id]" id="razorpay_payment_id" value="pay_xxx">
```

This is appended to the form with ID `co-payment-form`.

When `review.save()` submits the form, Magento receives this as `$_POST['payment']['razorpay_payment_id']`, which `Paymentmethod::capture()` reads:
```php
$paymentId = $requestFields['payment']['razorpay_payment_id'];
```

**Cleanup:** If the user closes the modal without paying, `onUserClose()` removes this hidden input to prevent stale data.

---

## Amount Calculation Details

```php
// In CheckoutController::orderAction()
$amount = (int) ((float) $this->_getQuote()->getBaseGrandTotal()) * 100;
```

- `getBaseGrandTotal()` returns the total in the store's BASE currency (always INR in Indian stores)
- Cast to `float` first to handle any precision issues
- Multiply by 100 to convert to paise
- Cast to `int` to ensure no decimal values

**Multi-currency display:**
```php
$quote_currency = $this->_getQuote()->getQuoteCurrencyCode();
$quote_amount = round($this->_getQuote()->getGrandTotal(), 2);
```

If a store has currency conversion enabled:
- `getBaseCurrencyCode()` → `INR` (base/store currency)
- `getQuoteCurrencyCode()` → e.g., `USD` (customer's displayed currency)
- Razorpay charges in INR but shows display amount in USD

---

## Session Management

The checkout quote is fetched via singleton:
```php
Mage::getSingleton('checkout/session')->getQuote()
```

The session expires check (`_expireAjax()`) is defined but not used in the current controller — it returns a 403 if the quote has no items.

---

## Quote Reserve Order ID

Magento reserves an order ID (increment ID) before the order is actually placed:
```php
if (!$orderId) {
    $this->_getQuote()->reserveOrderId()->save();
    $orderId = $this->_getQuote()->getReservedOrderId();
}
```

This ID is passed to Razorpay as `receipt`, creating a traceable link between Razorpay orders and Magento orders before the Magento order is even created.

---

## Capture vs Authorize

The extension configures `payment_action = authorize_capture`. In Magento's payment model:
- `authorize()` is a no-op (`return $this`)
- `capture()` does the actual Razorpay API call

This means the Razorpay payment is **captured (charged) immediately** when the order is placed. There is no separate authorize-then-capture flow.

The capabilities in `Paymentmethod.php`:
```php
protected $_canOrder                = true;
protected $_canAuthorize            = true;
protected $_canCapture              = true;
protected $_canCapturePartial       = false;
protected $_canRefund               = false;  // Not implemented
protected $_canVoid                 = false;
```
