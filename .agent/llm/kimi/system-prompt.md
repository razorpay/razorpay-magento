# Kimi System Prompt — Razorpay Magento Extension

You are helping with the `razorpay-magento` project — a PHP extension that adds Razorpay payments to Magento stores.

## What The Project Does (Simple)

When a customer wants to pay in a Magento store:
1. They click "Place Order"
2. JavaScript creates a payment order on Razorpay's servers
3. A payment popup (modal) appears — customer enters card/UPI/bank details
4. After payment, a payment ID comes back to the browser
5. The browser sends the payment ID to Magento
6. Magento confirms the payment with Razorpay's API (server-to-server)
7. Order is created

## Key Files to Know

| File | What it does |
|------|-------------|
| `Helper/Data.php` | Makes HTTP calls to Razorpay API |
| `Model/Paymentmethod.php` | Tells Magento "I am a payment method", does capture |
| `controllers/CheckoutController.php` | Handles AJAX from browser → creates Razorpay order |
| `js/razorpay/razorpay-utils.js` | Browser JS: manages popup, form injection |
| `template/razorpay/setuputils.phtml` | Passes API key from PHP to JavaScript |
| `etc/config.xml` | Module routing and default settings |
| `etc/system.xml` | Admin settings UI |

## Always Read First

- `AGENTS.md` — full repository map
- `KIMI.md` — simplified explanations for this codebase

## Rules When Writing Code

1. **PHP**: Old style (PHP 5.3). No `namespace`, no `use`, no arrow functions (`fn =>`), no `::class`.
2. **JavaScript**: Uses Prototype.js library. NOT jQuery. Examples:
   - `$('form-id')` gets element by ID
   - `$$('.class')` gets elements by CSS selector
   - `new Ajax.Request(url, {...})` for HTTP calls
3. **No Composer**: Add new PHP classes in `config.xml`, not in `composer.json`
4. **Config reading**: `Mage::getStoreConfig('payment/razorpay/fieldname')`
5. **Amount**: Always multiply by 100 when sending to Razorpay. ₹100 = `10000`

## Skills Available

Read these files for step-by-step guides on common tasks:

| Task | Skill File |
|------|-----------|
| Add new checkout extension support | `.agent/skills/add-checkout-extension.md` |
| Call new Razorpay API | `.agent/skills/add-razorpay-api.md` |
| Add admin setting | `.agent/skills/add-admin-config.md` |
| Fix payment errors | `.agent/skills/debug-payment-failure.md` |
| Add refund support | `.agent/skills/implement-refund.md` |
| Add webhook | `.agent/skills/add-webhook-handler.md` |
| Update version | `.agent/skills/bump-version.md` |
| Test everything | `.agent/skills/test-payment-flow.md` |
