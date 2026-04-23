# Skill: Test Payment Flows

**Trigger phrases:** "test checkout", "verify payment flow", "QA checklist", "test Razorpay integration"

---

## Setup: Test Mode Keys

Before testing, configure test keys in Magento admin:
- `System > Config > Payment Methods > Razorpay`
- API Key: `rzp_test_XXXXXXXX`
- API Key Secret: `your_test_secret`

Razorpay test card: `4111 1111 1111 1111` | Expiry: any future | CVV: any 3 digits

---

## Flow 1: Native Onepage Checkout

**URL:** `/checkout/onepage`

### Test Cases

**TC-1.1 — Happy path (Razorpay selected)**
1. Add item to cart → proceed to checkout
2. Fill in address details
3. On Payment step → select "Razorpay"
4. Click "Continue"
5. ✅ Expected: No modal yet — just moves to Review step
6. On Review step — click "Place Order"
7. ✅ Expected: Razorpay modal opens with correct amount and merchant name
8. Pay using test card
9. ✅ Expected: Order created, redirected to `/checkout/onepage/success`
10. ✅ Expected: Order status = `processing` in admin
11. ✅ Expected: `razorpay_payment_id` saved as transaction ID in order

**TC-1.2 — Modal dismiss (user cancels payment)**
1. Reach Review step with Razorpay selected
2. Click "Place Order" → modal opens
3. Close the modal (X button or press Escape)
4. ✅ Expected: Stays on Review page
5. ✅ Expected: "Place Order" button is re-enabled
6. ✅ Expected: Can click again and re-open modal

**TC-1.3 — Different payment method**
1. Select a non-Razorpay payment method (e.g., Check/Money Order)
2. Click "Place Order"
3. ✅ Expected: No Razorpay modal — native Magento submit flow

**TC-1.4 — Razorpay disabled**
1. Disable Razorpay in admin config
2. Go to checkout
3. ✅ Expected: Razorpay does NOT appear in payment method list

---

## Flow 2: FireCheckout Extension

**URL:** `/firecheckout` (or extension's configured URL)

**Pre-condition:** FireCheckout extension installed and enabled.

**TC-2.1 — Happy path**
1. Fill all checkout fields on single page
2. Select Razorpay as payment
3. Click "Place Order"
4. ✅ Expected: Form validation runs first
5. ✅ Expected: Razorpay modal opens
6. Pay → ✅ Expected: Order created

**TC-2.2 — Form validation before modal**
1. Leave required field empty (e.g., phone)
2. Click "Place Order"
3. ✅ Expected: Validation error shown, modal does NOT open

**TC-2.3 — Order caching (`isOrderSet`)**
1. Click "Place Order" → modal opens → cancel
2. Click "Place Order" again
3. ✅ Expected: Modal opens immediately (no second createOrder AJAX call)
4. Check Network tab — should NOT see second request to `/razorpay/checkout/order`

---

## Flow 3: IWD OPC Extension

**URL:** `/opc` (or extension's configured URL)

**Pre-condition:** IWD OPC extension installed, `opc/global/status` = 1.

**TC-3.1 — Happy path**
1. Fill billing + shipping on single page
2. Select Razorpay
3. Click "Place Order"
4. ✅ Expected: IWD loader shows
5. ✅ Expected: Razorpay modal opens
6. Pay → ✅ Expected: Order created

**TC-3.2 — Address validation**
1. Leave billing address fields empty
2. Click "Place Order"
3. ✅ Expected: Billing address validation error, NO modal

**TC-3.3 — Separate shipping address**
1. Uncheck "same as billing"
2. Leave shipping fields empty
3. Click "Place Order"
4. ✅ Expected: Shipping validation error, NO modal

**TC-3.4 — Event listener management**
1. Open browser console
2. Verify `$j_opc(document)._data('events')` shows Razorpay's listener on `.opc-btn-checkout`
3. After payment success — verify IWD's native listener temporarily re-added then removed

---

## Flow 4: AppZab OneStepCheckout

**URL:** `/onestepcheckout` (or extension's configured URL)

**TC-4.1 — Happy path**
1. Fill all fields
2. Select Razorpay
3. Click "Place Order"
4. ✅ Expected: Razorpay modal opens
5. Pay → ✅ Expected: Order created

**TC-4.2 — Order already set**
1. Click Place Order → cancel modal
2. Click Place Order again
3. ✅ Expected: `review.onComplete()` called, then modal opens immediately

---

## Cross-Flow Verification

Run these checks for ALL flows:

| Check | Expected |
|-------|----------|
| Network: `/razorpay/checkout/order` response | `200 OK` with `razorpay_order_id` |
| Network: final form POST | Includes `payment[razorpay_payment_id]` |
| Razorpay Dashboard | Payment shows as `captured` |
| Magento Admin > Order | Status = `processing`, Transaction ID = `pay_xxx` |
| Razorpay Dashboard > Orders | Receipt = Magento increment ID (e.g. `100000001`) |

---

## Amount Verification

| Scenario | Expected Behavior |
|----------|-----------------|
| Order total ₹100 | Razorpay modal shows `₹100.00` |
| Store with USD display currency, ₹100 base | Modal shows `₹100` (INR charged), USD display amount shown |
| Minimum order (< ₹1) | Razorpay API rejects — `BAD_REQUEST_ERROR: amount less than minimum` |

---

## Regression After Code Changes

After any change to these files, re-run the full TC suite:

| File Changed | Must Re-Test |
|---|---|
| `razorpay-utils.js` | All 4 flows |
| `setuputils.phtml` | All 4 flows (JS init) |
| `placeorder.phtml` | Flow 1 only |
| `order.phtml` | Flow 1 only |
| `extensions/firecheckout/payments.phtml` | Flow 2 only |
| `extensions/iwd_opc/payments.phtml` | Flow 3 only |
| `extensions/appzab_osc/payments.phtml` | Flow 4 only |
| `Helper/Data.php` | All flows (API calls) |
| `Model/Paymentmethod.php` | All flows (capture) |
| `layout/razorpay.xml` | All 4 flows |
