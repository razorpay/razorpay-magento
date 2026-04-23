# Onboarding Prompt — New Developer/Agent on razorpay-magento

Use this when starting fresh on this repository.

---

## Read in This Order

1. `AGENTS.md` — Repository map, all classes, all flows, constraints (5 min read)
2. `docs/HLD.md` — Architecture diagrams (visual overview)
3. `docs/LLD.md` — Sequence diagrams for each checkout flow
4. `docs/FLOWS.md` — Detailed step-by-step flow descriptions

Then for your specific task:
- Working on checkout JS? → `context/checkout-flow.md`
- Adding extension? → `context/extension-flows.md`
- Working on capture/refund? → `context/payment-capture.md`
- Changing admin config? → `context/configuration.md`
- Need a skill guide? → `.agent/skills/`

---

## Mental Model

Think of this extension as a **three-layer intercept**:

```
Layer 1 (PHP, Server)  — Creates Razorpay orders, captures payments
Layer 2 (PHP, Template) — Bridges PHP config to JavaScript
Layer 3 (JS, Browser)  — Intercepts checkout button, opens modal
```

Each checkout extension (FireCheckout, IWD, AppZab) replaces Layer 3 with its own template while reusing Layers 1 and 2 unchanged.

---

## The One Thing That Must Not Break

The `razorpay_payment_id` handoff:
```
Razorpay modal → JS callback → hidden input in form → POST body → PHP capture
```
If any link in this chain breaks, payments fail silently or orders are created without capture.

---

## Quick Orientation Commands

```bash
# Find all Razorpay PHP files
find app/code/community/Razorpay -name "*.php" | sort

# Find where config is read
grep -r "getConfigData\|getStoreConfig" app/code/community/Razorpay/

# Find all checkout templates
find app/design -name "*.phtml" | grep razorpay

# Check current version
grep "VERSION" app/code/community/Razorpay/Payments/Model/Paymentmethod.php
```
