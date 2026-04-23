# Skill: Debug Payment Failure

**Trigger phrases:** "payment failing", "capture error", "checkout not working", "Razorpay error", "order not being created", "payment_id missing"

---

## Diagnostic Tree

```
Payment failure reported
│
├── Error shown at checkout → go to Section A
├── Order created but payment not captured → go to Section B
├── Razorpay modal doesn't open → go to Section C
├── Modal opens but payment fails → go to Section D
└── Order placed but wrong status → go to Section E
```

---

## Section A — Error at Checkout (Magento exception)

### Check 1: Is Razorpay enabled?
```php
// In PHP console or temporary debug block:
var_dump(Mage::getStoreConfigFlag('payment/razorpay/active'));
// Must be: bool(true)
```

### Check 2: Are API keys set?
```php
// Check that keys are not still the default placeholder values.
// NEVER dump or log key_secret — it is a live credential.
$model = Mage::getModel('razorpay_payments/paymentmethod');
$keyId = $model->getConfigData('key_id');
if ($keyId === 'Key ID' || empty($keyId)) {
    echo 'ERROR: key_id is not configured';
}
// Verify key_secret is set WITHOUT revealing its value:
$keySecret = $model->getConfigData('key_secret');
if ($keySecret === 'Key Secret' || empty($keySecret)) {
    echo 'ERROR: key_secret is not configured';
}
unset($keySecret); // discard — do not log or print
```

### Check 3: cURL connectivity
```bash
# Use a .netrc file or environment variable to avoid credentials appearing in shell history.
# Option A — environment variable (credentials not in command history):
export RZP_KEY_ID="rzp_test_xxx"
export RZP_KEY_SECRET="your_secret"
curl -u "${RZP_KEY_ID}:${RZP_KEY_SECRET}" \
  -X POST https://api.razorpay.com/v1/orders \
  -d "receipt=test&amount=100&currency=INR"
unset RZP_KEY_ID RZP_KEY_SECRET
```
Expected: `{"id":"order_xxx",...}` — if error, check key credentials.
> **Security:** Never paste literal `key_secret` values into terminal commands, chat messages, or log files.

### Check 4: PHP exception in Magento logs
```bash
tail -100 var/log/system.log
tail -100 var/log/exception.log
```
Look for `CURL_ERROR:`, `RAZORPAY_ERROR:`, `BAD_REQUEST_ERROR:`.

---

## Section B — Order Created, Payment Not Captured

### Root cause: `razorpay_payment_id` missing from POST

The JS must inject the payment ID into the form before submission.

**Debug step 1:** Add logging to `Paymentmethod::capture()`:
```php
$requestFields = Mage::app()->getRequest()->getPost();
Mage::log('POST payment fields: ' . print_r($requestFields['payment'], true), null, 'razorpay_debug.log');
```
Check `var/log/razorpay_debug.log` — does `razorpay_payment_id` appear?

**Debug step 2:** In browser console, before form submission:
```javascript
console.log('Form data:', $('co-payment-form').serialize(true));
```
Check if `payment[razorpay_payment_id]` is in the serialized data.

**Debug step 3:** Verify `createHiddenInput` runs in `RazorpayUtils.placeOrder()`:
```javascript
// Add to razorpay-utils.js temporarily:
console.log('Hidden input created for:', paymentIdField);
```

### Root cause: Amount mismatch during capture

The `CheckoutController::orderAction()` amount and `Paymentmethod::capture()` amount must match.

Check in `Helper::capturePayment()`:
```php
Mage::log('Capturing: ' . $paymentId . ' for amount: ' . $amount, null, 'razorpay_debug.log');
```

The Razorpay API will return:
```
BAD_REQUEST_ERROR: The amount is greater than the amount authorized
```

---

## Section C — Razorpay Modal Doesn't Open

### Check 1: Is `checkout.js` loaded?
In browser DevTools → Network tab → filter "checkout.razorpay" — should see 200.

### Check 2: Is `razorpay-utils.js` loaded?
Network tab → filter "razorpay-utils" — should see 200.
Common cause: Layout handle mismatch. Check `razorpay.xml` has the right handle.

### Check 3: Is `razorpayUtils` initialized?
```javascript
// In browser console:
console.log(typeof razorpayUtils);  // Should be 'object'
console.log(razorpayUtils.keyId);   // Should be your key_id
```

If `undefined`: `setuputils.phtml` block is not loading. Check layout XML.

### Check 4: Is `createOrder` being called?
```javascript
// Browser console, check network requests
// Look for XHR to /razorpay/checkout/order
```
If not called: Button intercept not working. Check the button selector in the phtml.

### Check 5: Is `orderInfo` populated?
```javascript
console.log(razorpayUtils.orderInfo);  // Should be object with razorpay_order_id
```
If `undefined`: `createOrder` AJAX failed. Check Network tab for error on `/razorpay/checkout/order`.

---

## Section D — Modal Opens But Payment Fails

This is typically a Razorpay-side issue. Check:

1. **Test vs Live keys**: `rzp_test_` prefix for test, `rzp_live_` for live
2. **Order amount**: Minimum ₹1 (100 paise) required
3. **Currency**: Only `INR` supported by this extension
4. **Order ID**: The `order_id` passed to modal must match what Razorpay created

Check browser console for Razorpay's own error messages.

---

## Section E — Wrong Order Status After Payment

Config path: `payment/razorpay/order_status` — default is `processing`.

```php
var_dump(Mage::getStoreConfig('payment/razorpay/order_status'));
```

If status is `pending` or `pending_payment`, the capture may have failed silently.
Check `var/log/exception.log` for `There was an error capturing the transaction`.

---

## Enable Detailed Logging

Add to `Paymentmethod::capture()` temporarily:
```php
Mage::log('Razorpay capture called for order: ' . $orderId, null, 'razorpay.log');
Mage::log('Payment ID from POST: ' . $paymentId, null, 'razorpay.log');
Mage::log('Amount (Magento): ' . $amount, null, 'razorpay.log');
Mage::log('Amount (paise): ' . $_preparedAmount, null, 'razorpay.log');
```

Enable Magento payment debug logging:
`System > Config > Payment Methods > Razorpay > Debug` (if the field exists)
Or in `config.xml`, set `<debug>1</debug>` under razorpay defaults.

---

## Common Error Messages Reference

| Error | Location | Cause | Fix |
|-------|----------|-------|-----|
| `CURL_ERROR: Could not resolve host` | `Helper::sendRequest` | No internet / DNS issue | Check server outbound connectivity |
| `CURL_ERROR: Operation timed out` | `Helper::sendRequest` | API call >60s | Check Razorpay API status |
| `BAD_REQUEST_ERROR: The id provided is invalid` | `capturePayment` | Wrong `payment_id` | Verify JS passes correct ID |
| `BAD_REQUEST_ERROR: This payment has already been captured` | `capturePayment` | Double submission | Check if form submitted twice |
| `MAGENTO_ERROR: Payment Method not available` | `Helper` | `active=0` | Enable Razorpay in admin |
| `CAPTURE_ERROR: Unable to capture payment` | `capturePayment` | API returned non-"captured" status | Check Razorpay dashboard |
| `There was an error capturing the transaction` | `Paymentmethod::capture` | Any of the above | Check logs for inner message |
