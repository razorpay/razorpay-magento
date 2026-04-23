# Code Review Prompt — Razorpay Magento Extension

Use this prompt when reviewing PRs or code changes to this repository.

---

## Review Checklist

When reviewing any code change, verify:

### Security
- [ ] `key_secret` is not present in any `.phtml`, `.js`, or template file
- [ ] No sensitive data logged via `Mage::log()` (e.g., full API responses with credentials)
- [ ] Amount calculations use integer paise, not float INR (prevents rounding attacks)
- [ ] Webhook signature verified via `hash_hmac('sha256', ...)` if webhook controller added
- [ ] New controller actions don't expose order data without auth check

### Correctness
- [ ] Amount multiplied by 100 exactly once in the relevant flow (not double-converted)
- [ ] `isRazorpayEnabled()` guard present in all conditional template output
- [ ] `razorpay_payment_id` from POST validated before passing to `capturePayment()`
- [ ] All 4 checkout flows work if `razorpay-utils.js` was modified
- [ ] `createOrder()` returns `['razorpay_order_id' => $response['id']]` format unchanged

### PHP Compatibility
- [ ] No PHP 7+ syntax: no `??` null coalescing in PHP 5.3, no typed params, no `match`
- [ ] No namespaces (`namespace Foo\Bar;`)
- [ ] No `use` import statements
- [ ] No `::class` constant
- [ ] No arrow functions `fn($x) => $x`

### JavaScript Compatibility
- [ ] No jQuery (`$()` is Prototype's, not jQuery — but `$.ajax()` is jQuery-only)
- [ ] No `fetch()`, `async/await`, `const`, `let`, arrow functions in Prototype.js files
- [ ] IWD OPC template can use `$j_opc` (scoped jQuery) — this is OK
- [ ] `Event.observe()` used for event binding, not `addEventListener()`

### Magento 1 Conventions
- [ ] New classes registered in `config.xml` under correct `<blocks>/<models>/<helpers>`
- [ ] New admin config fields in both `system.xml` (UI) AND `config.xml` (defaults)
- [ ] New routes registered under `<frontend><routers>` in `config.xml`
- [ ] Layout blocks added to `razorpay.xml` for all relevant handles
- [ ] New templates in `app/design/frontend/base/default/template/razorpay/`

### Razorpay API
- [ ] New API URLs registered in `Helper/Data::__construct()` `$this->urls` array
- [ ] `sendRequest()` reused for all Razorpay HTTP calls (handles auth + error handling)
- [ ] Response validation checks `status === 'captured'` (or appropriate field) before returning true
- [ ] Errors thrown as `Exception` in helpers, `Mage::throwException()` in payment models

---

## Common Issues to Flag

| Issue | What to Look For | Fix |
|-------|-----------------|-----|
| Double amount conversion | `$amount * 100 * 100` | Remove one conversion |
| Key secret in template | `$this->getConfigData('key_secret')` in phtml | Remove — never expose secret |
| jQuery in non-IWD template | `jQuery(...)` or `$(...)` (jQuery style) in firecheckout/appzab templates | Replace with Prototype.js |
| Missing enabled check | Template output without `isRazorpayEnabled()` | Add the guard |
| Hardcoded amounts | `amount = 100` instead of computed from quote | Use `getBaseGrandTotal() * 100` |
| Missing URL registration | `sendRequest($url)` with hardcoded URL | Add to `$this->urls` and use `getRelativeUrl()` |
