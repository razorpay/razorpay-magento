# Razorpay API Reference — Used by This Extension

This document covers only the Razorpay API endpoints actually used by this Magento extension.

---

## Authentication

All API calls use **HTTP Basic Authentication**:
```
Authorization: Basic base64(key_id:key_secret)
```

Configured in Magento admin at:
`System > Configuration > Payment Methods > Razorpay > API Key / API Key Secret`

Retrieved in PHP via:
```php
$keyId = Mage::getModel('razorpay_payments/paymentmethod')->getConfigData('key_id');
$keySecret = Mage::getModel('razorpay_payments/paymentmethod')->getConfigData('key_secret');
```

---

## Base URL

```
https://api.razorpay.com/v1/
```

---

## Endpoint 1: Create Order

**Used in:** `Helper/Data.php::createOrder()`, called from `CheckoutController::orderAction()`

```
POST https://api.razorpay.com/v1/orders
```

### Request

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `receipt` | string | Yes | Magento's reserved order ID (increment ID) |
| `amount` | integer | Yes | Amount in paise (INR cents). Example: ₹100 = `10000` |
| `currency` | string | Yes | Always `INR` (hardcoded constant) |

**PHP code:**
```php
$postData = array(
    'receipt'   => $receipt,   // Magento orderId
    'amount'    => $amount,    // paise
    'currency'  => 'INR'       // Razorpay_Payments_Model_Paymentmethod::CURRENCY
);
$response = $this->sendRequest($this->getRelativeUrl('order'), $postData);
```

### Response

```json
{
    "id": "order_8o5x7pHiSHAYph",
    "entity": "order",
    "amount": 10000,
    "amount_paid": 0,
    "amount_due": 10000,
    "currency": "INR",
    "receipt": "100000001",
    "status": "created",
    "attempts": 0,
    "created_at": 1710000000
}
```

**Extracted fields used by extension:**
```php
$returnArray = array(
    'razorpay_order_id'  => $response['id']  // 'order_8o5x7pHiSHAYph'
);
```

**Then appended in controller:**
```json
{
    "razorpay_order_id": "order_8o5x7pHiSHAYph",
    "customer_name": "John Doe",
    "customer_phone": "+919876543210",
    "order_id": "100000001",
    "amount": 10000,
    "currency": "INR",
    "customer_email": "john@example.com",
    "quote_currency": "USD",
    "quote_amount": 125.00
}
```
(Note: `quote_currency` and `quote_amount` only present if store currency ≠ INR)

---

## Endpoint 2: Capture Payment

**Used in:** `Helper/Data.php::capturePayment()`, called from `Model/Paymentmethod.php::capture()`

```
POST https://api.razorpay.com/v1/payments/:id/capture
```

Where `:id` = `razorpay_payment_id` returned by the Razorpay JS modal (format: `pay_xxxxxxxxxx`)

### Request

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `amount` | integer | Yes | Amount in paise — must match authorized amount |

**PHP code:**
```php
$url = $this->getRelativeUrl('capture', array(':id' => $paymentId));
$postData = array('amount' => $amount);  // amount in paise
$response = $this->sendRequest($url, $postData);
```

### Response

```json
{
    "id": "pay_8o5x7pHiSHAYph",
    "entity": "payment",
    "amount": 10000,
    "currency": "INR",
    "status": "captured",
    "order_id": "order_8o5x7pHiSHAYph",
    "method": "card",
    "captured": true,
    ...
}
```

**Success check:**
```php
if ($response['status'] === 'captured') {
    return true;
}
throw new Exception('CAPTURE_ERROR: Unable to capture payment ' . $paymentId);
```

---

## Endpoint 3: Refund (Defined but Not Implemented)

The URL is defined in `Helper/Data.php` but no refund method is implemented:

```
POST https://api.razorpay.com/v1/payments/:id/refund
```

The payment model also has `$_canRefund = false` set.

---

## Razorpay Checkout.js Integration

This is a **frontend CDN script**, not a backend API call.

**CDN URL:** `https://checkout.razorpay.com/v1/checkout.js`

**Loaded via** `razorpay.xml` layout:
```xml
<script type="text/javascript" src="https://checkout.razorpay.com/v1/checkout.js"></script>
```

### Checkout Options Object (passed to `new Razorpay(options)`)

| Option | Source | Required | Description |
|--------|--------|----------|-------------|
| `key` | `RazorpayUtils.keyId` (from `payment/razorpay/key_id`) | Yes | Public key |
| `name` | `RazorpayUtils.merchantName` (from `merchant_name_override`) | Yes | Merchant display name |
| `amount` | `orderInfo.amount` (paise) | Yes | Payment amount |
| `currency` | `orderInfo.currency` | Yes | Always `INR` |
| `order_id` | `orderInfo.razorpay_order_id` | Yes | Created order ID |
| `handler` | `onSuccess` callback | Yes | Called with `{razorpay_payment_id}` on success |
| `prefill.name` | `orderInfo.customer_name` | No | Pre-fills customer name |
| `prefill.contact` | `orderInfo.customer_phone` | No | Pre-fills phone |
| `prefill.email` | `orderInfo.customer_email` | No | Pre-fills email |
| `notes.merchant_order_id` | `orderInfo.order_id` | No | Magento order reference |
| `modal.ondismiss` | `onUserClose` callback | No | Called when modal is closed without payment |
| `display_amount` | `orderInfo.quote_amount` | No | Show non-INR amount (cosmetic) |
| `display_currency` | `orderInfo.quote_currency` | No | Show non-INR currency (cosmetic) |

### Success Callback

```javascript
var onSuccess = function(data) {
    // data = {razorpay_payment_id: 'pay_xxx', razorpay_order_id: 'order_xxx', razorpay_signature: '...'}
    $(paymentIdField).value = data.razorpay_payment_id;
    review.save(); // or extension equivalent
};
```

---

## User-Agent String

All API requests include a custom User-Agent identifying the integration:

```
Razorpay/Magento{Edition}_{MagentoVersion}/{ExtensionVersion}
```

Examples:
- `Razorpay/MagentoCE_1.9.4.1/1.1.7`
- `Razorpay/MagentoEE_1.14.3.0/1.1.7`

**Generated by:** `Paymentmethod::_getChannel()`

---

## HTTP Response Code Handling

The extension considers these HTTP status codes as success:
```php
protected $successHttpCodes = array(200, 201, 202, 203, 204, 205, 206, 207, 208, 226);
```

Any other code + any response with an `error` key is treated as failure.

---

## Error Response Format

Razorpay API errors follow this format:
```json
{
    "error": {
        "code": "BAD_REQUEST_ERROR",
        "description": "The amount is less than minimum amount allowed",
        "field": "amount"
    }
}
```

Extracted and thrown as:
```php
$error = $responseArray['error']['code'] . ": " . $responseArray['error']['description'];
throw new Exception($error);
```
