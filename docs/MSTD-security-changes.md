# MSTD security fixes on `security-changes`

Scope: the Standard (non-Magic) register items that can be fixed on this branch.
Base: `security-changes` (`433232d`, parent `2e628b8` = 4.2.5).

`security-changes` has no `Controller/OneClick/*` and no `Model/CartConverter.php`, so
MAGM-R08, MAGM-B14, MAGM-B15 and the OneClick halves of the Magic register cannot land here.

## What changed, per register item

### MSTD-B03 — callback failure branch took the order from the request
`Controller/Payment/Callback.php`

The no-payment-id branch now loads `checkoutSession->getLastRealOrder()`, refuses the request
unless its `order_id` equals the session order's increment id, and only then reactivates that
order's quote. The request id is never used to reach a quote.

### MSTD-B01 — `placeRazorpayOrder` had no ownership check
`Model/Resolver/PlaceRazorpayOrder.php`, `etc/schema.graphqls`, `Model/Util/CallerIdentity.php`

- the caller is resolved through `CallerIdentity`: a customer token only when
  `getUserType() === USER_TYPE_CUSTOMER`, or `masked_cart_id` for a guest;
- `assertOwnerForGraphQl()` runs before any Razorpay call;
- `assertStore()` refuses an order from another store;
- `assertPayable()` refuses any state but `new`, a non-Razorpay payment, or an order that already
  has an invoice or a paid total;
- the Razorpay order already linked to the Magento order is reused instead of replaced;
- `callback_token` is returned and exposed on the `RazorpayOrder` type.

### MSTD-B02 — `resetCart` had no ownership check and no state check
`Model/Resolver/ResetCart.php`, `README.md`

- same caller resolution, store check and `assertPayable()` before the status change;
- README step 6 now states that reset is only for a failed payment, and shows the required
  `masked_cart_id` / customer token.

### MSTD-R02 / MSTD-B27 — payment proof was not bound to the order
`Controller/Payment/Validate.php`, `Model/Util/PaymentGuard.php`

- `Validate` loads the order's link row and verifies the signature against **that** `rzp_order_id`,
  not `catalogSession->getRazorpayOrderID()`;
- requires state `new`, method `razorpay`, and clears the session Razorpay order id after use;
- `PaymentGuard::assertMatches()` then checks the payment belongs to the linked Razorpay order, is
  `authorized` or `captured`, is not refunded, and that the Razorpay **order** amount equals the
  Magento grand total. The payment amount is deliberately not compared, because offers and fees
  legitimately change it.

The same guard is now used by `Callback`, `SetRzpPaymentDetailsForOrder` and
`Cron/UpdateOrdersToProcessingV2`.

### MSTD-R10 — an order could be paid through another store
`Model/Resolver/PlaceRazorpayOrder.php`, `Model/Resolver/SetRzpPaymentDetailsForOrder.php`

Both resolvers refuse an order whose `store_id` differs from the current request's store.

## Supporting fixes

| File | Change |
| --- | --- |
| `Model/Util/OrderAccessGuard.php` | callback token bound to entity id + increment id; `assertStore()` added; `assertPayable()` also rejects invoiced and paid orders |
| `Model/Util/CallerIdentity.php` | new: customer id from the GraphQL context, only for a customer token |
| `Model/Util/PaymentGuard.php` | new: payment-to-order and amount check used by every confirmation path |
| `Controller/Payment/Callback.php` | token and masked cart id redacted from logs; ownership moved to the payment path; repeated callback stays a success; state and payment-method check before mutation |
| `Controller/Payment/Validate.php` | same re-entry behaviour; refuses with 409 instead of an unhandled exception |
| `Cron/CancelPendingOrders.php` | never cancels an order that has an invoice, a paid total or a processing/complete/closed state, even without a webhook timestamp |
| `Cron/UpdateOrdersToProcessingV2.php` | holds an order in `payment_review` when the payment cannot be verified |
| `Controller/Payment/Order.php` | `is_hosted` default restored to `false` (it had been forced to `true`, disabling the embedded checkout) |
| `composer.json`, `CHANGELOG.md` | version 4.3.0, breaking-change note for guest GraphQL callers |

## Not in this patch

- Persisting `key_id` and mode on the link row and building the Razorpay client from the order's
  store in refunds and crons. The cross-store exploit is closed by the store check, but a key
  rotation mid-flight is still unprotected. Needs a schema upgrade.
- Per-handler denial tests. The register requires one test per handler asserting denial with no
  state change; the repo has no test harness.
- The Magic items (R08, B14, B15, OneClick halves of R02/R01) belong on `magic_integration_latest`.

## Verification

- `git apply --check` the patch on `security-changes`.
- `bin/magento setup:di:compile` (new constructor arguments are auto-wired).
- Manual: foreign order id on `/razorpay/payment/callback` without a payment id → redirect, no
  quote change; `placeRazorpayOrder` with a foreign order → 403; `resetCart` on a paid order → 4xx
  and status unchanged; `Validate` with a payment from another order → 409, order still `new`.
