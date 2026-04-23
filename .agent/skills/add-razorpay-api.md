# Skill: Add a New Razorpay API Endpoint

**Trigger phrases:** "add Razorpay X API", "call Razorpay's X endpoint", "integrate Razorpay X feature"

---

## What This Does

Adds a new Razorpay REST API call to the extension (e.g., fetch payment details, create virtual account, issue refund, etc.). All API calls go through `Helper/Data.php` using the shared `sendRequest()` method which handles authentication automatically.

---

## Razorpay API Basics

- **Base URL:** `https://api.razorpay.com/v1/`
- **Auth:** HTTP Basic — `key_id:key_secret` (configured in Magento admin)
- **Amounts:** Always in paise (INR × 100) — e.g., ₹100 = `10000`
- **Error format:** `{"error": {"code": "...", "description": "..."}}`

---

## Step 1 — Register the URL

File: `app/code/community/Razorpay/Payments/Helper/Data.php`

In `__construct()`, add to the `$this->urls` array:

```php
public function __construct()
{
    $this->urls = array(
        'order'       => self::BASE_URL . 'orders',
        'payment'     => self::BASE_URL . 'payments',
        'capture'     => self::BASE_URL . 'payments/:id/capture',
        'refund'      => self::BASE_URL . 'payments/:id/refund',
        // ADD NEW URL HERE:
        'your_endpoint' => self::BASE_URL . 'your/endpoint/path',
        // With path param example:
        'payment_fetch' => self::BASE_URL . 'payments/:id',
    );
    // ...
}
```

Use `:id` (or any `:param`) as placeholder — `getRelativeUrl()` does string replacement.

---

## Step 2 — Add the Method

Add a new `public` method to `Razorpay_Payments_Helper_Data`:

```php
/**
 * Fetch payment details from Razorpay
 *
 * @param string $paymentId  Razorpay payment ID (e.g. pay_xxx)
 * @return array
 * @throws Exception
 */
public function fetchPayment($paymentId)
{
    if ($this->isRazorpayEnabled())
    {
        $url = $this->getRelativeUrl('payment_fetch', array(':id' => $paymentId));

        // For GET requests, pass empty array and specify method
        $response = $this->sendRequest($url, array(), 'GET');

        return $response;
    }

    throw new Exception('MAGENTO_ERROR: Payment Method not available');
}
```

**For POST with body:**
```php
public function createVirtualAccount($orderId, $amount)
{
    if ($this->isRazorpayEnabled())
    {
        $url = $this->getRelativeUrl('virtual_account');

        $postData = array(
            'receivers'  => array(array('types' => array('bank_account'))),
            'description' => 'Order #' . $orderId,
            'amount'     => $amount,
            'currency'   => Razorpay_Payments_Model_Paymentmethod::CURRENCY,
        );

        $response = $this->sendRequest($url, $postData);

        return $response;
    }

    throw new Exception('MAGENTO_ERROR: Payment Method not available');
}
```

---

## Step 3 — Call the Method

From a controller action or payment model:

```php
$helper = Mage::helper('razorpay_payments');

try {
    $result = $helper->fetchPayment($paymentId);
    // $result is the decoded JSON array from Razorpay
} catch (Exception $e) {
    Mage::throwException($e->getMessage());
}
```

---

## Step 4 — Handle the Response

`sendRequest()` already:
- Throws on cURL errors (`CURL_ERROR: ...`)
- Throws on Razorpay API errors (`BAD_REQUEST_ERROR: ...`)
- Returns decoded JSON array on success

You only need to validate business logic:
```php
if (empty($result['id'])) {
    throw new Exception('RAZORPAY_ERROR: Unexpected response format');
}
```

---

## HTTP Methods Supported by `sendRequest()`

| Method | When to Use |
|--------|------------|
| `POST` (default) | Create, capture, refund |
| `GET` | Fetch/read resources |
| `PATCH` | Update resources |

Pass as third arg: `$this->sendRequest($url, $data, 'GET')`

---

## Common Razorpay API Endpoints (not yet in extension)

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `GET /v1/payments/:id` | GET | Fetch payment details |
| `POST /v1/payments/:id/refund` | POST | Issue refund |
| `GET /v1/orders/:id` | GET | Fetch order details |
| `POST /v1/virtual_accounts` | POST | Create virtual account |
| `GET /v1/settlements` | GET | List settlements |
| `POST /v1/paymentlinks` | POST | Create payment link |

Full API docs: https://razorpay.com/docs/api/

---

## Security Checklist

- [ ] New endpoint URL added to `$this->urls` in `__construct()`
- [ ] Method wrapped in `if ($this->isRazorpayEnabled())` guard
- [ ] `key_secret` never logged or returned to browser
- [ ] Amounts validated as integers (paise), not floats
- [ ] Exception messages don't expose raw API credentials
