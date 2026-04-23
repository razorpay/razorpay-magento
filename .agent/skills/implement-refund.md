# Skill: Implement Refund Capability

**Trigger phrases:** "implement refund", "add refund support", "enable refunds", "Razorpay refund"

---

## Current State

The extension has refund **stubbed but not implemented**:
- `$_canRefund = false` in `Paymentmethod.php` — Magento won't show refund UI
- `refund` URL is defined in `Helper/Data.php` but no method uses it
- No `refund()` method exists in `Paymentmethod.php`

This skill implements full refund support.

---

## Step 1 — Enable Refund Capability in Payment Model

File: `app/code/community/Razorpay/Payments/Model/Paymentmethod.php`

Change:
```php
protected $_canRefund               = false;
protected $_canRefundInvoicePartial = false;
```
To:
```php
protected $_canRefund               = true;
protected $_canRefundInvoicePartial = true;
```

---

## Step 2 — Add `refund()` Method to Payment Model

Add after `capture()` method in `Paymentmethod.php`:

```php
/**
 * Refund specified amount for payment
 *
 * @param Varien_Object $payment
 * @param float $amount
 * @return Razorpay_Payments_Model_Paymentmethod
 */
public function refund(Varien_Object $payment, $amount)
{
    $helper = Mage::helper('razorpay_payments');

    $paymentId = $payment->getLastTransId();

    if (empty($paymentId))
    {
        Mage::throwException('Razorpay payment ID not found. Cannot process refund.');
    }

    // Convert to paise. Use round() before casting to int — floating point
    // multiplication is imprecise: (int)(10.10 * 100) can yield 1009 not 1010.
    $refundAmount = (int) round($amount * 100);

    try
    {
        $result = $helper->refundPayment($paymentId, $refundAmount);

        $this->_debug('Refund initiated for ' . $paymentId . ' amount: ' . $amount);
        $this->_debug($result);

        $payment->setTransactionId($result['id'])
                ->setIsTransactionClosed(true)
                ->setShouldCloseParentTransaction(!$payment->getCreditmemo()->getInvoice()->canRefund());
    }
    catch (Exception $e)
    {
        Mage::throwException('Razorpay refund failed: ' . $e->getMessage());
    }

    return $this;
}
```

---

## Step 3 — Add `refundPayment()` to Helper

File: `app/code/community/Razorpay/Payments/Helper/Data.php`

The `refund` URL is already defined in `__construct()`. Add the method:

```php
/**
 * Refund a captured Razorpay payment
 *
 * @param string $paymentId  Razorpay payment ID (pay_xxx)
 * @param int    $amount     Amount in paise to refund
 * @return array             Razorpay refund object
 * @throws Exception
 */
public function refundPayment($paymentId, $amount)
{
    if ($this->isRazorpayEnabled())
    {
        $url = $this->getRelativeUrl('refund', array(':id' => $paymentId));

        $postData = array('amount' => $amount);

        $response = $this->sendRequest($url, $postData);

        // Razorpay returns refund object: {id, entity, amount, payment_id, ...}
        if (!empty($response['id']))
        {
            return $response;
        }

        throw new Exception('REFUND_ERROR: Unexpected response for payment ' . $paymentId);
    }

    throw new Exception('MAGENTO_ERROR: Payment Method not available');
}
```

---

## Step 4 — Test Refund Flow

In Magento admin:
1. Open a completed order (status: `processing` with a captured Razorpay payment)
2. Click "Invoice" → create invoice if not already invoiced
3. Click "Credit Memo"
4. Enter refund amount (partial or full)
5. Click "Refund"

Expected:
- Magento calls `Paymentmethod::refund()` 
- `refundPayment()` POSTs to `https://api.razorpay.com/v1/payments/{id}/refund`
- Razorpay processes refund
- Credit memo created in Magento
- Order status updated

### Test with Razorpay Test Mode
```bash
curl -u rzp_test_KEY:SECRET \
  -X POST https://api.razorpay.com/v1/payments/pay_xxx/refund \
  -d "amount=10000"
```
Expected response:
```json
{"id": "rfnd_xxx", "entity": "refund", "amount": 10000, "payment_id": "pay_xxx", ...}
```

---

## Partial Refund Considerations

With `$_canRefundInvoicePartial = true`, Magento allows partial refunds.
The Razorpay API supports partial refunds — just pass the partial amount in paise.

If you want to restrict to **full refunds only**:
```php
protected $_canRefundInvoicePartial = false;
```
And in `refund()`:
```php
$invoiceTotal = (int) ($payment->getOrder()->getGrandTotal() * 100);
if ($refundAmount !== $invoiceTotal) {
    Mage::throwException('Razorpay only supports full refunds via this extension.');
}
```

---

## Checklist

- [ ] `$_canRefund = true` set in `Paymentmethod.php`
- [ ] `refund()` method added to `Paymentmethod.php`
- [ ] `refundPayment()` method added to `Helper/Data.php`
- [ ] `refund` URL already exists in `Helper/Data::__construct()` — no change needed
- [ ] Tested with test mode keys via Magento admin credit memo flow
- [ ] Verified refund appears in Razorpay dashboard
- [ ] Partial refund behavior decided and implemented
