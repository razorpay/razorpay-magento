# Payment Flows — Razorpay Magento Extension

Complete documentation of every payment flow supported by this extension.

---

## Flow 1: Native Magento Onepage Checkout

### Trigger
Customer on `/checkout/onepage` selects Razorpay as payment method.

### Layout Handles Involved
- `checkout_onepage_index` — loads JS + setuputils.phtml
- `checkout_onepage_savepayment` — loads placeorder.phtml (after payment step)
- `checkout_onepage_paymentmethod` — loads order.phtml (payment step render)

### Step-by-Step

```
1. Page Load
   └── razorpay.xml injects into <head>:
       ├── <script src="https://checkout.razorpay.com/v1/checkout.js">
       └── razorpay/razorpay-utils.js
   └── setuputils.phtml (before_body_end) outputs:
       ├── new RazorpayUtils({orderUrl: '/razorpay/checkout/order'})
       ├── razorpayUtils.setKeyId('rzp_live_xxx')
       └── razorpayUtils.setMerchantName('Merchant Name')

2. Customer Proceeds to Payment Step
   └── Magento loads checkout_onepage_paymentmethod
   └── order.phtml overrides Payment.prototype.save():
       └── If Razorpay selected → razorpayUtils.createOrder() BEFORE native save

3. Customer Selects Razorpay + Clicks Continue
   └── Payment.save() called (overridden version)
   └── razorpayUtils.createOrder(makeNativeAjaxRequest, onFailure)
       └── AJAX POST /razorpay/checkout/order
       └── CheckoutController::orderAction():
           ├── Gets base grand total × 100 (paise)
           ├── Gets/creates reserved order ID
           ├── Calls Helper::createOrder(orderId, amount)
           │   └── POST https://api.razorpay.com/v1/orders
           │       Body: {receipt, amount, currency: INR}
           │   └── Returns {razorpay_order_id: 'order_xxx'}
           ├── Appends customer_name, phone, email, currency
           └── Returns JSON to JS
   └── RazorpayUtils.orderInfo = {razorpay_order_id, amount, currency, customer_*, order_id}
   └── makeNativeAjaxRequest called → native Magento saves payment selection

4. Customer Reaches Review Step
   └── checkout_onepage_savepayment handle loads placeorder.phtml
   └── placeorder.phtml:
       ├── Finds btn-checkout button
       ├── Removes existing onclick
       └── Adds Event.observe(btn, 'click', placeOrder)

5. Customer Clicks "Place Order"
   └── placeOrder() function:
       ├── Check payment.currentMethod == 'razorpay'
       ├── beforeStart() → disable button
       └── razorpayUtils.placeOrder(onSuccess, onUserClose, beforeStart, 'co-payment-form', 'razorpay_payment_id')

6. Razorpay Modal
   └── createHiddenInput: payment[razorpay_payment_id] in co-payment-form
   └── new Razorpay({
       key: key_id,
       name: merchantName,
       amount: paise,
       currency: INR,
       order_id: razorpay_order_id,
       handler: onSuccess,
       prefill: {name, contact, email},
       notes: {merchant_order_id},
       modal: {ondismiss: onUserClose}
   }).open()

7a. Payment Success
   └── handler(data) called with data.razorpay_payment_id = 'pay_xxx'
   └── $(paymentIdField).value = 'pay_xxx'
   └── review.save() → submits co-payment-form
   └── Magento processes order:
       └── Paymentmethod::capture() called
           ├── Reads POST payment[razorpay_payment_id]
           ├── Helper::capturePayment('pay_xxx', amount * 100)
           │   └── POST /v1/payments/pay_xxx/capture {amount: paise}
           │   └── Response status === 'captured' → returns true
           └── Sets payment status APPROVED, transactionId = pay_xxx
   └── Magento creates order with status 'processing'
   └── Customer redirected to /checkout/onepage/success

7b. Payment Dismissed
   └── modal.ondismiss() → onUserClose()
   └── Remove hidden input field from form
   └── Re-enable place order button
   └── Customer stays on review page
```

---

## Flow 2: FireCheckout Extension

### Trigger
Customer on `/firecheckout` (FireCheckout extension's one-page checkout URL).

### Layout Handle
`firecheckout_index_index`

### Key Differences from Native
- Single-page checkout (no step-by-step AJAX panels)
- Form ID: `firecheckout-form`
- Button selector: `#review-buttons-container .btn-checkout`
- Uses `checkout.save()` instead of `review.save()` for final submission

### Step-by-Step

```
1. Page Load
   └── Same JS loading as native (checkout.js + razorpay-utils.js + setuputils.phtml)
   └── extensions/firecheckout/payments.phtml executes:
       └── nativeButton = $$('#review-buttons-container .btn-checkout').first()
       └── nativeButton.setAttribute('onclick', 'processRazorpayOrder()')

2. Customer Clicks "Place Order"
   └── processRazorpayOrder() called
   └── new Validation('firecheckout-form').validate()

3. Form Valid + Razorpay Selected
   └── If razorpayUtils.isOrderSet() → go to step 4 directly
   └── Else: razorpayUtils.createOrder(placeRazorpayOrder, showErrorMessage)
       └── AJAX to /razorpay/checkout/order (same as native)

4. Razorpay Modal
   └── razorpayUtils.placeOrder(onSuccess, onUserClose, beforeStart, 'firecheckout-form', 'razorpay_payment_id')
   └── beforeStart: nativeButton.disabled = true

5a. Success
   └── onSuccess(data): $(paymentIdField).value = pay_xxx
   └── processNativeExtensionOrder() → checkout.save()

5b. Dismiss
   └── onUserClose(): nativeButton.disabled = false, nativeButton.show()

5c. Error
   └── showErrorMessage(message): alert(message), nativeButton.show()
```

---

## Flow 3: IWD OPC Extension

### Trigger
Customer on `/opc` (IWD One Page Checkout extension URL).

### Layout Handle
`opc_index_index`

### Key Differences from Native
- Uses jQuery (`$j_opc`) for DOM manipulation (IWD OPC ships jQuery)
- Must explicitly remove IWD's native submit listeners and add custom ones
- Address validation via `VarienForm` (Magento's native validator class)
- Uses `IWD.OPC.Checkout.showLoader()` / `hideLoader()` for loading state
- Uses `IWD.OPC.initSaveOrder()` to trigger native order save after Razorpay payment
- More complex flow due to event listener management

### Enable Check
Uses `isIwdOpcExtensionEnabled()` instead of `isRazorpayEnabled()`:
```php
Mage::getStoreConfigFlag('opc/global/status') AND isModuleOutputEnabled('IWD_Opc')
```

### Step-by-Step

```
1. DOM Ready
   └── $j_opc(document).ready(function() {
       ├── removeSubmitOrderEvents() → $j_opc(document).off('click', '.opc-btn-checkout')
       └── addRazorpayFlow() → $j_opc(document).on('click', '.opc-btn-checkout', processRazorpayOrder)

2. Customer Clicks "Place Order" (.opc-btn-checkout)
   └── processRazorpayOrder() called
   └── Validate billing address (VarienForm 'opc-address-form-billing')
   └── If shipping ≠ billing: validate shipping address too

3. Razorpay Selected
   └── IWD.OPC.Checkout.showLoader()
   └── If not isOrderSet: razorpayUtils.createOrder(placeRazorpayOrder, showErrorMessage)
   └── Else: placeRazorpayOrder()

4. Razorpay Modal
   └── razorpayUtils.placeOrder(onSuccess, onUserClose, beforeStart, formId, paymentIdField)
   └── beforeStart: IWD.OPC.Checkout.showLoader()
   └── onUserClose: IWD.OPC.Checkout.hideLoader()

5a. Success
   └── onSuccess(data): $(paymentIdField).value = pay_xxx
   └── processNativeExtensionOrder():
       ├── IWD.OPC.Checkout.hideLoader()
       ├── removeSubmitOrderEvents() — disable Razorpay listener temporarily
       ├── IWD.OPC.initSaveOrder()
       ├── $j_opc('.opc-btn-checkout').click() — triggers native IWD save
       ├── removeSubmitOrderEvents() again
       └── setTimeout(addRazorpayFlow(), 500) — re-enable for next time

5b. Dismiss
   └── onUserClose(): IWD.OPC.Checkout.hideLoader()

5c. Error
   └── showErrorMessage(message):
       ├── $j_opc('.opc-message-container').html(message)
       ├── $j_opc('.opc-message-wrapper').show()
       ├── IWD.OPC.Checkout.hideLoader()
       └── Re-enable button
```

---

## Flow 4: AppZab OneStepCheckout

### Trigger
Customer on AppZab's one-step checkout page.

### Layout Handle
`onestepcheckout_index_index`

### Key Differences from Native
- Form ID: `co-form` (not `co-payment-form`)
- Button: `button[type=button].btn-checkout` (type=button, not type=submit)
- Checks `isOrderSet()` and calls `review.onComplete()` if order already created
- Uses `review.loadingbox()` for loading state
- Error handling via `checkout.ajaxFailure()`

### Step-by-Step

```
1. Page Load
   └── extensions/appzab_osc/payments.phtml executes:
       └── defaultPlaceOrderButton = $$('button[type=button].btn-checkout')[0]
       └── button.setAttribute('onclick', 'processRazorpayOrder()')

2. Customer Clicks "Place Order"
   └── processRazorpayOrder()
   └── new Validation('co-form').validate()

3. Form Valid
   └── review.loadingbox() — show loading indicator
   └── If Razorpay selected:
       ├── If isOrderSet: review.onComplete() then placeRazorpayOrder()
       └── Else: razorpayUtils.createOrder(placeRazorpayOrder, onError)

4. Razorpay Modal
   └── razorpayUtils.placeOrder(onSuccess, onUserClose, beforeStart, 'co-form', 'razorpay_payment_id')
   └── beforeStart: defaultPlaceOrderButton.disabled = true

5a. Success
   └── $(paymentIdField).value = pay_xxx
   └── processNativeExtensionOrder() → review.save()

5b. Dismiss
   └── onUserClose():
       ├── $('review-please').update('')
       └── defaultPlaceOrderButton.disabled = false

5c. Error
   └── onError(message):
       ├── $('review-please').update('')
       ├── $('review-btn').disabled = ''
       └── checkout.ajaxFailure(message)
```

---

## Flow Comparison Matrix

| Aspect | Native | FireCheckout | IWD OPC | AppZab OSC |
|--------|--------|--------------|---------|-----------|
| Layout handle | `checkout_onepage_*` | `firecheckout_index_index` | `opc_index_index` | `onestepcheckout_index_index` |
| Form ID | `co-payment-form` | `firecheckout-form` | `co-payment-form` | `co-form` |
| Button selector | `button.btn-checkout` (submit) | `#review-buttons-container .btn-checkout` | `.opc-btn-checkout` | `button[type=button].btn-checkout` |
| Intercept method | `Event.observe` | `setAttribute('onclick')` | `$j_opc(document).on` | `setAttribute('onclick')` |
| Final submit | `review.save()` | `checkout.save()` | `IWD.OPC click` | `review.save()` |
| JS framework | Prototype.js | Prototype.js | jQuery (`$j_opc`) | Prototype.js |
| Enable check | `isRazorpayEnabled()` | `isRazorpayEnabled()` | `isIwdOpcExtensionEnabled()` | `isRazorpayEnabled()` |
| Double-order protection | No (order in payment step) | `isOrderSet()` | `isOrderSet()` | `isOrderSet()` |
| Loading indicator | Button disabled | Button disabled | `IWD.OPC.showLoader()` | `review.loadingbox()` |
| Error display | `alert()` + `checkout.back()` | `alert()` + show button | OPC message container | `checkout.ajaxFailure()` |

---

## Backend Order Creation Flow (Shared by All)

```
CheckoutController::orderAction()
│
├── _getQuote() — Mage::getSingleton('checkout/session')->getQuote()
├── amount = (int)(float)getBaseGrandTotal() * 100  [paise]
├── base_currency = getBaseCurrencyCode()
├── quote_currency = getQuoteCurrencyCode()
├── quote_amount = round(getGrandTotal(), 2)
│
├── If no reservedOrderId: quote->reserveOrderId()->save()
│
├── Helper::createOrder(orderId, amount)
│   └── POST https://api.razorpay.com/v1/orders
│       Headers: Authorization: Basic base64(key_id:key_secret)
│       Body: {receipt: orderId, amount: paise, currency: INR}
│       Returns: {id: 'order_xxx', ...}
│
└── Response JSON:
    {
        razorpay_order_id: 'order_xxx',
        customer_name: 'First Last',
        customer_phone: '+91...',
        order_id: 'magento_increment_id',
        amount: 10000,
        currency: 'INR',
        customer_email: 'user@example.com',
        quote_currency: 'USD',   // only if different from INR
        quote_amount: 125.00     // only if different from INR
    }
```

---

## Error Handling Matrix

| Error Type | Where Thrown | Error Format | User Impact |
|-----------|-------------|-------------|-------------|
| cURL connection error | `Helper::sendRequest()` | `CURL_ERROR: {curl_error}` | Checkout error |
| Razorpay API error | `Helper::sendRequest()` | `{error.code}: {error.description}` | Checkout error |
| Invalid response | `Helper::sendRequest()` | `RAZORPAY_ERROR: Invalid Response` | Checkout error |
| Payment method disabled | `Helper::createOrder()` | `MAGENTO_ERROR: Payment Method not available` | Checkout error |
| Capture failed | `Helper::capturePayment()` | `CAPTURE_ERROR: Unable to capture payment {id}` | Order failure |
| Capture exception | `Model::capture()` | `There was an error capturing the transaction. {e.getMessage()}` | Magento error page |
