# Razorpay Magento Extension — Kimi Context

> Optimized for Kimi (Moonshot AI) — all versions including kimi-k1, kimi-k2, moonshot-v1-*, and future models.

---

## What This Codebase Does

This is a **Magento 1.x payment extension** that integrates Razorpay (India's payment gateway) into Magento online stores. When a customer checks out, instead of entering card details in Magento's form, they see a Razorpay popup modal.

**Key documents to read:**
1. `AGENTS.md` — Complete codebase map, all classes, all flows
2. `docs/HLD.md` — Architecture diagrams (Mermaid format)
3. `docs/LLD.md` — Step-by-step sequence diagrams
4. `docs/FLOWS.md` — Every checkout flow explained

---

## How It Works (Simple Explanation)

```
1. Customer clicks "Place Order" in Magento
         ↓
2. JavaScript intercepts the click
         ↓
3. AJAX call to Magento backend → Razorpay API → Order created
         ↓
4. Razorpay popup modal opens (hosted by Razorpay)
         ↓
5. Customer enters payment details in the popup
         ↓
6. Razorpay returns payment_id to JavaScript
         ↓
7. JavaScript submits Magento form with payment_id
         ↓
8. Magento backend captures the payment via Razorpay API
         ↓
9. Order confirmed, customer redirected to success page
```

---

## File Structure

```
app/code/community/Razorpay/Payments/
├── Block/
│   ├── Placeorder.php        → Empty block (template does the work)
│   └── Setuputils.php        → Passes API key and merchant name to JavaScript
├── Helper/
│   └── Data.php              → Makes HTTP calls to Razorpay API
├── Model/
│   └── Paymentmethod.php     → Tells Magento "I am a payment method"
├── controllers/
│   └── CheckoutController.php → Handles /razorpay/checkout/order URL
└── etc/
    ├── config.xml             → Module configuration, routes
    └── system.xml             → Admin settings fields

js/razorpay/
└── razorpay-utils.js         → JavaScript class that manages Razorpay checkout

app/design/frontend/base/default/
├── layout/razorpay.xml       → Decides what loads on each checkout page
└── template/razorpay/
    ├── setuputils.phtml       → Outputs JS to initialize RazorpayUtils
    ├── placeorder.phtml       → Intercepts Place Order button
    ├── order.phtml            → Overrides how payment step works
    └── extensions/            → Same logic for 3rd party checkout extensions
        ├── firecheckout/
        ├── iwd_opc/
        └── appzab_osc/
```

---

## Important Technical Details

### PHP
- **Magento 1 MVC** — XML-based class registration, no Composer
- **PHP 5.3+ compatible** — no modern PHP features
- **cURL** used for all API calls (no Guzzle/HttpClient)
- Auth: HTTP Basic Auth `key_id:key_secret`

### JavaScript
- **Prototype.js** (NOT jQuery) — Magento 1's built-in JS framework
- `RazorpayUtils` class manages: order creation, modal launch, form injection
- Razorpay's `checkout.js` loaded from CDN: `https://checkout.razorpay.com/v1/checkout.js`

### Currency
- Magento charges in INR (paise = INR × 100) to Razorpay API
- If store currency ≠ INR, the display amount (quote amount) is shown in the modal, but actual charge is in INR

### Admin Config Paths
- Enable: `payment/razorpay/active`
- API Key ID: `payment/razorpay/key_id`
- API Secret: `payment/razorpay/key_secret`
- Merchant Name: `payment/razorpay/merchant_name_override`

---

## Kimi-Specific Guidance

When Kimi helps with this codebase:

### Code Style Rules
- Do NOT use PHP namespaces (`namespace Razorpay\Payments;`) — Magento 1 doesn't support them
- Do NOT use `use` statements for imports
- Class names follow `Namespace_Module_Layer_Name` pattern
- Methods should return `$this` where Magento expects fluent interface (payment model)

### Common Patterns

**Reading config:**
```php
$value = Mage::getStoreConfig('payment/razorpay/key_id');
// or via model:
$value = Mage::getModel('razorpay_payments/paymentmethod')->getConfigData('key_id');
```

**Making API call:**
```php
$helper = Mage::helper('razorpay_payments');
$result = $helper->sendRequest($url, $postData);
```

**Adding JS event listener (Prototype.js):**
```javascript
Event.observe($('element-id'), 'click', function() {
    // handler
});
```

**AJAX request (Prototype.js):**
```javascript
new Ajax.Request('/razorpay/checkout/order', {
    method: 'post',
    onSuccess: function(response) {
        var data = response.responseJSON;
    },
    onFailure: function() {
        alert('Error occurred');
    }
});
```

### Debugging Tips
- Enable Magento logging: `Mage::log($message, null, 'razorpay.log')`
- Check `/var/log/razorpay.log` for debug output
- API errors thrown as `Exception` with prefix `RAZORPAY_ERROR:`, `CURL_ERROR:`, or `CAPTURE_ERROR:`

---

## Razorpay API Reference

| Endpoint | Method | Auth | Purpose |
|----------|--------|------|---------|
| `https://api.razorpay.com/v1/orders` | POST | Basic | Create payment order |
| `https://api.razorpay.com/v1/payments/:id/capture` | POST | Basic | Capture authorized payment |
| `https://api.razorpay.com/v1/payments/:id/refund` | POST | Basic | Refund (URL defined but not implemented) |

**Create Order request body:**
```json
{
    "receipt": "magento_order_id",
    "amount": 10000,
    "currency": "INR"
}
```

**Capture Payment request body:**
```json
{
    "amount": 10000
}
```
