# Razorpay Magento Extension — Agent Context (Universal)

> This file is the **primary entry point** for all LLM-based agentic development tools.
> Compatible with: Claude (all versions), Gemini (all versions), Kimi (all versions), GPT-4+, Copilot, Cursor, Cody, and any OpenAI-compatible agent.

---

## Project Identity

| Field | Value |
|-------|-------|
| **Name** | Razorpay Payment Extension for Magento 1.x |
| **Language** | PHP (Magento 1 Community/Enterprise Edition) |
| **Version** | 1.1.7 |
| **Namespace** | `Razorpay_Payments` |
| **Module path** | `app/code/community/Razorpay/Payments/` |
| **Frontend assets** | `js/razorpay/`, `app/design/frontend/base/default/` |

---

## Repository Map

```
razorpay-magento/
├── AGENTS.md                        ← You are here (universal LLM context)
├── CLAUDE.md                        ← Claude-specific context
├── GEMINI.md                        ← Gemini-specific context
├── docs/
│   ├── HLD.md                       ← High-Level Design + Mermaid diagrams
│   ├── LLD.md                       ← Low-Level Design + Mermaid diagrams
│   ├── FLOWS.md                     ← All payment flows documented
│   └── API.md                       ← Razorpay API reference
├── context/
│   ├── checkout-flow.md             ← Standard checkout flow context
│   ├── extension-flows.md           ← Third-party extension flows
│   ├── payment-capture.md           ← Capture + order processing
│   └── configuration.md             ← Admin config reference
├── app/code/community/Razorpay/Payments/
│   ├── Block/
│   │   ├── Placeorder.php           ← Block: place order (empty, template-driven)
│   │   └── Setuputils.php           ← Block: injects key_id + merchant name to JS
│   ├── Helper/
│   │   └── Data.php                 ← Core helper: API calls, order creation, capture
│   ├── Model/
│   │   └── Paymentmethod.php        ← Payment model: authorize/capture, config access
│   ├── controllers/
│   │   └── CheckoutController.php   ← Frontend controller: /razorpay/checkout/order
│   └── etc/
│       ├── config.xml               ← Module registration, routes, defaults
│       └── system.xml               ← Admin UI config fields
├── app/design/frontend/base/default/
│   ├── layout/
│   │   └── razorpay.xml             ← Layout handles for all checkout types
│   └── template/razorpay/
│       ├── setuputils.phtml         ← Initializes RazorpayUtils JS object
│       ├── placeorder.phtml         ← Native checkout: intercepts place-order button
│       ├── order.phtml              ← Native checkout: overrides Payment.save()
│       └── extensions/
│           ├── firecheckout/payments.phtml   ← FireCheckout integration
│           ├── iwd_opc/payments.phtml        ← IWD OPC integration
│           └── appzab_osc/payments.phtml     ← AppZab OSC integration
├── js/razorpay/
│   └── razorpay-utils.js            ← Core JS: RazorpayUtils class (Prototype.js)
├── app/etc/modules/
│   └── Razorpay_Payments.xml        ← Module enable/disable declaration
├── modman                           ← Modman symlink map
└── package.xml                      ← Magento Connect package descriptor
```

---

## Core Concepts

### Payment Flow Summary
1. Customer selects Razorpay at checkout
2. JS intercepts "Place Order" button click
3. AJAX call to `/razorpay/checkout/order` creates a Razorpay Order via API
4. Razorpay Checkout JS modal opens for the customer to pay
5. On payment success, `razorpay_payment_id` is injected into form
6. Form submitted → Magento captures payment via `Paymentmethod::capture()`
7. Magento creates order with status `processing`

### Key Classes

| Class | Role |
|-------|------|
| `Razorpay_Payments_Helper_Data` | All Razorpay API communication (cURL), URL building |
| `Razorpay_Payments_Model_Paymentmethod` | Magento payment model, config reader |
| `Razorpay_Payments_CheckoutController` | REST-like endpoint for order creation |
| `Razorpay_Payments_Block_Setuputils` | PHP→JS bridge for key_id + merchant name |
| `RazorpayUtils` (JS) | Client-side Razorpay checkout orchestration |

### API Endpoints Used

| Operation | Method | URL |
|-----------|--------|-----|
| Create Order | POST | `https://api.razorpay.com/v1/orders` |
| Capture Payment | POST | `https://api.razorpay.com/v1/payments/:id/capture` |

### Authentication
HTTP Basic Auth: `key_id:key_secret` (configured in Magento admin)

---

## Checkout Extension Compatibility

| Extension | Layout Handle | Template | Form ID |
|-----------|--------------|----------|---------|
| Native Onepage | `checkout_onepage_*` | `placeorder.phtml`, `order.phtml` | `co-payment-form` |
| FireCheckout | `firecheckout_index_index` | `extensions/firecheckout/payments.phtml` | `firecheckout-form` |
| IWD OPC | `opc_index_index` | `extensions/iwd_opc/payments.phtml` | `co-payment-form` |
| AppZab OSC | `onestepcheckout_index_index` | `extensions/appzab_osc/payments.phtml` | `co-form` |

---

## Configuration Paths (Magento Core Config)

| Path | Default | Description |
|------|---------|-------------|
| `payment/razorpay/active` | `0` | Enable/disable |
| `payment/razorpay/key_id` | `Key ID` | Public API key |
| `payment/razorpay/key_secret` | `Key Secret` | Secret API key |
| `payment/razorpay/title` | `Razorpay` | Payment method label |
| `payment/razorpay/merchant_name_override` | `Magento` | Name shown in checkout modal |
| `payment/razorpay/order_status` | `processing` | Post-payment order status |

---

## Development Guidelines for AI Agents

### Do NOT
- Modify `config.xml` routing without updating `CheckoutController.php`
- Change the `CURRENCY` constant (hardcoded `INR`) without updating order creation logic
- Add Composer dependencies — this is a legacy Magento 1 extension, no Composer
- Use PSR-4 autoloading — use Magento 1's XML-declared class map

### Do
- Keep JavaScript in Prototype.js style (Magento 1 uses Prototype, not jQuery)
- Use `Mage::helper('razorpay_payments')` for all API calls
- Use `Mage::getModel('razorpay_payments/paymentmethod')->getConfigData($field)` to read config
- Test all 4 checkout extension flows when modifying JS

### File Modification Impact Map
| File Changed | Affects |
|---|---|
| `Helper/Data.php` | All API calls (order create, capture, refund) |
| `Model/Paymentmethod.php` | Payment capabilities, capture flow, config access |
| `controllers/CheckoutController.php` | `/razorpay/checkout/order` endpoint |
| `js/razorpay/razorpay-utils.js` | All JS checkout flows |
| `Block/Setuputils.php` | JS initialization (key_id, merchant name injection) |
| `etc/config.xml` | Module routing, defaults, layout registration |
| `layout/razorpay.xml` | Which blocks load on which checkout pages |

---

## See Also

- `docs/HLD.md` — Architecture diagrams (Mermaid)
- `docs/LLD.md` — Sequence diagrams for every flow (Mermaid)
- `docs/FLOWS.md` — Detailed flow walkthroughs
- `docs/API.md` — Razorpay API reference
- `context/checkout-flow.md` — Standard checkout deep-dive
- `context/extension-flows.md` — Extension integration details
