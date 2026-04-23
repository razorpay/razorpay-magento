# Razorpay Magento Extension — Claude Context

> Optimized for Claude Sonnet, Claude Opus, Claude Haiku (all versions including claude-3-*, claude-sonnet-4-*, claude-opus-4-*)

---

## Quick Start for Claude

This is a **Magento 1.x PHP payment extension** for Razorpay. It intercepts the Magento checkout flow to show the Razorpay payment modal instead of the native payment form.

**Read `AGENTS.md` first** for the full repository map and core concepts. This file adds Claude-specific guidance on top.

---

## Architecture at a Glance

```
Customer Browser ──AJAX──► CheckoutController::orderAction()
                                    │
                                    ▼
                         Helper::createOrder()
                                    │
                                    ▼
                    POST https://api.razorpay.com/v1/orders
                                    │
                                    ▼
                         Razorpay JS Modal (checkout.js)
                                    │
                          Customer pays
                                    │
                                    ▼
                  JS injects razorpay_payment_id → form submit
                                    │
                                    ▼
                    Paymentmethod::capture() ──► POST /payments/:id/capture
                                    │
                                    ▼
                         Magento Order created (status: processing)
```

---

## Code Conventions

- **Language**: PHP 5.x compatible (Magento 1 requirement). No `::class`, no closures in older patterns, no namespaces.
- **JS style**: Prototype.js (`Class.create()`, `$()`, `$$()`, `Ajax.Request`). Do NOT use jQuery syntax in extension JS files.
- **Config access**: Always via `Mage::getStoreConfig('payment/razorpay/field')` or `$paymentModel->getConfigData('field')`.
- **Helpers**: `Mage::helper('razorpay_payments')` returns `Razorpay_Payments_Helper_Data`.
- **Error handling**: Use `Mage::throwException()` inside payment models. Use `throw new Exception()` in helpers.

---

## Key Methods Reference

### `Razorpay_Payments_Helper_Data`
| Method | Signature | Purpose |
|--------|-----------|---------|
| `isRazorpayEnabled()` | `bool` | Checks `payment/razorpay/active` flag |
| `createOrder($receipt, $amount)` | `array` | POSTs to `/v1/orders`, returns `['razorpay_order_id' => ...]` |
| `capturePayment($paymentId, $amount)` | `bool` | POSTs to `/v1/payments/:id/capture` |
| `sendRequest($url, $content, $method)` | `array` | cURL wrapper with Basic Auth |
| `getRelativeUrl($name, $data)` | `string` | Resolves URL from internal map |
| `isIwdOpcExtensionEnabled()` | `bool` | Checks IWD OPC module status |

### `Razorpay_Payments_Model_Paymentmethod`
| Property/Method | Value/Purpose |
|----------------|---------------|
| `METHOD_CODE` | `'razorpay'` |
| `CURRENCY` | `'INR'` |
| `VERSION` | `'1.1.7'` |
| `authorize()` | No-op (returns `$this`) |
| `capture()` | Reads `razorpay_payment_id` from POST, calls helper to capture |
| `getConfigData($field)` | Reads from `payment/razorpay/$field` with admin store awareness |
| `_getChannel()` | Returns User-Agent string like `Razorpay/MagentoCE_2.x.x/1.1.7` |

### `Razorpay_Payments_CheckoutController`
| Action | Route | Purpose |
|--------|-------|---------|
| `orderAction()` | `GET/POST /razorpay/checkout/order` | Creates Razorpay order, returns JSON |

### `RazorpayUtils` (JavaScript)
| Method | Purpose |
|--------|---------|
| `createOrder(onSuccess, onFailure)` | AJAX call to `/razorpay/checkout/order` |
| `placeOrder(onSuccess, onUserClose, beforeStart, formId, paymentIdField)` | Opens Razorpay modal |
| `isOrderSet()` | Checks if order info is cached (prevents double API call) |
| `setKeyId(key)` | Sets API key for checkout options |
| `setMerchantName(name)` | Sets merchant display name |

---

## Common Claude Tasks

### Adding a new config field
1. Add `<fieldname>` under `<fields>` in `system.xml`
2. Add default value in `config.xml` under `<default><payment><razorpay>`
3. Access in PHP via `$paymentModel->getConfigData('fieldname')`

### Adding a new API call
1. Add URL to `$this->urls` array in `Helper/Data.php::__construct()`
2. Create method in `Helper/Data.php` following `createOrder()` / `capturePayment()` pattern
3. Use `$this->sendRequest($url, $postData)` — auth is handled automatically

### Adding support for a new checkout extension
1. Add new layout handle to `razorpay.xml` mirroring the FireCheckout block
2. Create `app/design/frontend/base/default/template/razorpay/extensions/{extension}/payments.phtml`
3. Identify the extension's form ID and place-order button selector
4. Follow the pattern: intercept button → validate → `razorpayUtils.createOrder()` → `razorpayUtils.placeOrder()`

### Debugging payment capture failures
- Check `Model/Paymentmethod::capture()` — reads `$requestFields['payment']['razorpay_payment_id']`
- Verify `Helper/Data::capturePayment()` — checks response `status === 'captured'`
- All cURL errors surface as `CURL_ERROR:` prefix exceptions
- Razorpay API errors surface as `error.code: error.description` format

---

## Testing Checklist (for Claude to verify before completing tasks)

- [ ] Native onepage checkout flow still works after changes
- [ ] FireCheckout extension still intercepts correctly
- [ ] IWD OPC extension still intercepts correctly
- [ ] AppZab OSC extension still intercepts correctly
- [ ] `isRazorpayEnabled()` guards are present on all conditional output
- [ ] No jQuery syntax in JS files (Prototype.js only)
- [ ] Config fields accessible via `getConfigData()` method
- [ ] PHP 5.x compatibility maintained (no PHP 7+ only syntax)
