# Context: Payment Capture and Order Processing

> For AI agents: This explains what happens on the server side after the customer pays via Razorpay modal.

---

## What Triggers Capture

After the Razorpay modal returns a `razorpay_payment_id`:
1. JS injects `payment[razorpay_payment_id]` as a hidden form field
2. JS calls `review.save()` (or extension equivalent)
3. Browser submits checkout form to Magento
4. Magento's checkout controller processes the order
5. Magento calls `$payment->capture($amount)` on the payment model
6. `Razorpay_Payments_Model_Paymentmethod::capture()` runs

---

## Capture Method Internals

```php
public function capture(Varien_Object $payment, $amount)
{
    $helper = Mage::helper('razorpay_payments');

    // Read the payment ID from the original POST request
    $requestFields = Mage::app()->getRequest()->getPost();
    $paymentId = $requestFields['payment']['razorpay_payment_id'];

    $order = $payment->getOrder();
    $orderId = $order->getIncrementId();

    // Convert to paise
    $_preparedAmount = $amount * 100;

    try {
        // POST to Razorpay API
        $result = $helper->capturePayment($paymentId, $_preparedAmount);

        $this->_debug($orderId . ' - ' . $amount);  // Logged
        $this->_debug($result);                      // Logged

        if ($result) {
            $payment
                ->setStatus(self::STATUS_APPROVED)
                ->setAmountPaid($amount)
                ->setLastTransId($paymentId)
                ->setTransactionId($paymentId)
                ->setIsTransactionClosed(true)
                ->setShouldCloseParentTransaction(true);
        } else {
            $payment->setStatus(self::STATUS_DECLINED)->setIsTransactionClosed(true);
            Mage::throwException('There was an error capturing the transaction.');
        }
    } catch (Exception $e) {
        Mage::throwException('There was an error capturing the transaction. ' . $e->getMessage());
    }

    return $this;  // Fluent interface
}
```

### Key Points

1. **POST data access**: `Mage::app()->getRequest()->getPost()` — reads the full form POST including the injected `razorpay_payment_id`.

2. **Amount double-conversion**: `$amount` arrives in Magento's currency (decimal), multiplied by 100 for Razorpay. This is correct but note that `CheckoutController` also multiplies by 100 when creating the order. The amounts must match.

3. **Debug logging**: `$this->_debug()` outputs to Magento's payment debug log if payment debugging is enabled.

4. **Transaction closed**: Both `setIsTransactionClosed(true)` and `setShouldCloseParentTransaction(true)` are set — the payment is final with no authorize/void cycle.

---

## Capture API Call

```php
// In Helper/Data.php::capturePayment()
$url = $this->getRelativeUrl('capture', array(':id' => $paymentId));
// URL = https://api.razorpay.com/v1/payments/{pay_xxx}/capture

$postData = array('amount' => $amount);  // amount in paise

$response = $this->sendRequest($url, $postData);

if ($response['status'] === 'captured') {
    return true;
}

throw new Exception('CAPTURE_ERROR: Unable to capture payment ' . $paymentId);
```

---

## Order Status After Capture

Default: `processing` (configured at `payment/razorpay/order_status`)

This is the Magento order state after the payment is captured. Merchants can change this to `complete` or other statuses in admin config.

---

## What Gets Stored in Magento

After successful capture, Magento's `sales_payment_transaction` table gets:
- `txn_id` = `razorpay_payment_id` (e.g., `pay_8o5x7pHiSHAYph`)
- `txn_type` = `capture`
- `is_closed` = `1`

The order's payment record (`sales_flat_order_payment`) gets:
- `last_trans_id` = `razorpay_payment_id`
- `amount_paid` = order amount

---

## Failure Scenarios

### Scenario 1: Invalid Payment ID
The `razorpay_payment_id` in POST doesn't exist in Razorpay.
- Razorpay API returns 400 error
- `sendRequest()` throws `BAD_REQUEST_ERROR: ...`
- `capture()` catches and rethrows via `Mage::throwException()`
- Customer sees checkout error

### Scenario 2: Amount Mismatch
The capture amount doesn't match the authorized amount.
- Razorpay returns `BAD_REQUEST_ERROR: The amount is greater than authorized`
- Same exception chain as above

### Scenario 3: Already Captured
Payment already captured (e.g., double submit).
- Razorpay returns `BAD_REQUEST_ERROR: This payment has already been captured`
- Magento order creation fails

### Scenario 4: Network Timeout
cURL timeout after 60 seconds.
- `curl_exec()` returns `false`
- `CURL_ERROR: Operation timed out` thrown
- Magento order creation fails

---

## Authorize Method (No-op)

```php
public function authorize(Varien_Object $payment, $amount)
{
    return $this;  // Does nothing
}
```

Razorpay handles authorization through its own modal flow. By the time Magento calls `authorize()`, the customer has already authorized via the Razorpay popup. The authorize step in Magento's lifecycle is bypassed.

---

## Refund Capability (Not Implemented)

```php
protected $_canRefund = false;
```

The URL `https://api.razorpay.com/v1/payments/:id/refund` is defined in the helper's URL map but no `refund()` method exists in the payment model. Refunds must be initiated manually from the Razorpay dashboard.
