# Razorpay Magento Extension — Gemini Context

> Optimized for Gemini Pro, Gemini Ultra, Gemini Flash, Gemini 1.0, 1.5, 2.0, and all future Gemini versions.

---

## Project Overview

This repository is a **Magento 1.x payment gateway extension** for Razorpay (India's leading payment processor). It enables merchants to accept payments via Razorpay's checkout modal directly within Magento stores.

**Foundation documents** (read these first):
- `AGENTS.md` — Universal context, repository map, all key classes and flows
- `docs/HLD.md` — High-level architecture with Mermaid diagrams
- `docs/LLD.md` — Detailed sequence diagrams
- `docs/FLOWS.md` — All checkout flows documented end-to-end

---

## Technical Stack

| Layer | Technology |
|-------|-----------|
| Backend | PHP 5.x, Magento 1.x MVC |
| Frontend | Prototype.js, Magento Layout XML |
| API | Razorpay REST API v1 (Basic Auth) |
| HTTP Client | PHP cURL |
| Module System | Magento Community Edition module format |

---

## System Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        MAGENTO STORE                            │
│                                                                 │
│  ┌─────────────┐     ┌──────────────┐     ┌─────────────────┐  │
│  │   Customer  │────►│  Checkout    │────►│  Order Created  │  │
│  │   Browser   │◄────│  Controller  │     │  (processing)   │  │
│  └──────┬──────┘     └──────┬───────┘     └─────────────────┘  │
│         │                  │                                    │
│         │ Razorpay JS      │ Helper::createOrder()              │
│         │ Modal            │                                    │
│         │            ┌─────▼──────────────────────────┐        │
│         │            │       Helper/Data.php           │        │
│         │            │   (cURL to Razorpay API v1)     │        │
│         │            └────────────────────────────────-┘        │
└─────────┼───────────────────────────────────────────────────────┘
          │
          ▼
┌─────────────────────────────────────────────────────────────────┐
│                    RAZORPAY PLATFORM                            │
│  POST /v1/orders ──► Order Created                             │
│  POST /v1/payments/:id/capture ──► Payment Captured            │
└─────────────────────────────────────────────────────────────────┘
```

---

## Module Structure (Magento 1 Conventions)

Magento 1 uses XML-declared MVC, not Composer/PSR-4. The module is registered at:
- `app/etc/modules/Razorpay_Payments.xml` — enables the module
- `app/code/community/Razorpay/Payments/etc/config.xml` — registers blocks, models, helpers, routes

**Class naming convention**: `{Namespace}_{Module}_{Layer}_{Name}`
- `Razorpay_Payments_Helper_Data` → `app/code/community/Razorpay/Payments/Helper/Data.php`
- `Razorpay_Payments_Model_Paymentmethod` → `app/code/community/Razorpay/Payments/Model/Paymentmethod.php`

---

## All Payment Flows

### Flow 1: Native Magento Onepage Checkout
```
Step 1: Customer navigates to checkout (/checkout/onepage)
Step 2: razorpay.xml loads checkout.js + razorpay-utils.js in <head>
Step 3: setuputils.phtml initializes RazorpayUtils with key_id + order URL
Step 4: order.phtml overrides Payment.save() — calls razorpayUtils.createOrder() first
Step 5: Customer clicks "Place Order" → placeorder.phtml intercepts button
Step 6: razorpayUtils.createOrder() → AJAX → /razorpay/checkout/order → Razorpay API
Step 7: Razorpay modal opens → customer completes payment
Step 8: razorpay_payment_id injected into co-payment-form → review.save()
Step 9: Magento submits order → Paymentmethod::capture() called
Step 10: Helper::capturePayment() POSTs to /v1/payments/:id/capture
Step 11: Order status set to 'processing', customer redirected to success page
```

### Flow 2: FireCheckout Extension
- Layout handle: `firecheckout_index_index`
- Form ID: `firecheckout-form`
- Button selector: `#review-buttons-container .btn-checkout`
- Key difference: `checkout.save()` used instead of `review.save()`

### Flow 3: IWD OPC Extension
- Layout handle: `opc_index_index`
- Uses jQuery (`$j_opc`) instead of Prototype.js
- Removes native OPC event listeners, adds custom Razorpay flow
- Address validation done via `VarienForm` before payment

### Flow 4: AppZab OneStepCheckout
- Layout handle: `onestepcheckout_index_index`
- Form ID: `co-form`
- Checks `razorpayUtils.isOrderSet()` to avoid double API calls

---

## Configuration Reference

Access in PHP: `Mage::getStoreConfig('payment/razorpay/{field}')`

| Field | Type | Description |
|-------|------|-------------|
| `active` | boolean | Enable payment method |
| `key_id` | string | Public API key (`rzp_live_...` for live, `rzp_test_...` for test) |
| `key_secret` | string | Secret API key |
| `title` | string | Display name at checkout |
| `merchant_name_override` | string | Name shown in Razorpay modal |
| `order_status` | select | Order status after successful payment |
| `allowspecific` | boolean | Restrict to specific countries |
| `specificcountry` | multiselect | Allowed country codes |

---

## Gemini-Specific Guidance

When analyzing or modifying this codebase:

1. **PHP compatibility**: Target PHP 5.3-5.6 syntax. No arrow functions, no typed properties, no match expressions.

2. **Magento 1 patterns**: 
   - Use `Mage::getSingleton()` for session-based singletons
   - Use `Mage::getModel()` for new model instances
   - Use `Mage::helper()` for helper instances

3. **JavaScript framework**: Prototype.js (not jQuery). Key APIs:
   - `$('element-id')` — get element by ID
   - `$$('css-selector')` — get elements by CSS selector
   - `new Ajax.Request(url, options)` — AJAX calls
   - `Class.create()` — class definition
   - `Element.observe(el, 'event', handler)` — event binding

4. **Currency handling**: Amount is always in paise (INR × 100). The extension hardcodes `INR` as currency for Razorpay orders but displays the quote currency to the customer.

5. **Extension detection**: `isIwdOpcExtensionEnabled()` uses `Mage::getStoreConfigFlag()` + `isModuleOutputEnabled()` check.
