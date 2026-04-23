# Skill: Add Razorpay Webhook Handler

**Trigger phrases:** "add webhook", "handle Razorpay events", "payment.captured webhook", "async payment notification", "webhook endpoint"

---

## What This Does

Adds a Razorpay webhook endpoint to the extension that receives async payment events from Razorpay (e.g., `payment.captured`, `order.paid`, `payment.failed`, `refund.created`). This is needed for reliable payment confirmation independent of browser-side callbacks.

---

## Why Webhooks Matter

The current extension relies entirely on the browser JS callback (`handler: onSuccess`). If the customer closes the browser after payment but before the form submits, the order is never created in Magento. Webhooks solve this.

---

## Step 1 — Register the Webhook Route + Disable CSRF Correctly

File: `app/code/community/Razorpay/Payments/etc/config.xml`

The `razorpay` router is already present. Add the `nodispatch` flag to exclude the webhook
action from Magento's CSRF form_key check — this is the correct Magento 1 pattern:

```xml
<frontend>
    <routers>
        <razorpay>
            <use>standard</use>
            <args>
                <module>Razorpay_Payments</module>
                <frontName>razorpay</frontName>
            </args>
        </razorpay>
    </routers>
    <secure_url>
        <razorpay_order>/razorpay/order</razorpay_order>
        <razorpay_webhook>/razorpay/webhook</razorpay_webhook>
    </secure_url>
</frontend>
```

**IMPORTANT — do NOT use this CSRF bypass inside the controller:**
```php
// WRONG — do not do this:
$this->getRequest()->setPost('form_key', Mage::getSingleton('core/session')->getFormKey());
```
That approach initialises the user session unnecessarily, creates session overhead on every webhook
call, and is semantically incorrect. Instead, simply extend `Mage_Core_Controller_Front_Action`
and rely on Razorpay's HMAC signature for all authentication. The Magento CSRF form_key is
a browser-session protection mechanism — it does not apply to server-to-server webhook calls.

The webhook URL will be: `https://yourstore.com/razorpay/webhook/index`

---

## Step 2 — Create the Webhook Controller

Create file: `app/code/community/Razorpay/Payments/controllers/WebhookController.php`

```php
<?php

class Razorpay_Payments_WebhookController extends Mage_Core_Controller_Front_Action
{
    /**
     * Razorpay webhook endpoint
     * URL: /razorpay/webhook/index
     * Configure this URL in Razorpay Dashboard > Settings > Webhooks
     */
    public function indexAction()
    {
        // Read raw POST body FIRST — before any session/output operations
        // (webhooks send JSON, not form-encoded)
        $rawBody = file_get_contents('php://input');

        if (empty($rawBody))
        {
            $this->getResponse()->setHttpResponseCode(400);
            return;
        }

        // MANDATORY signature verification — reject ALL requests without a valid signature.
        // Never make this optional: an unverified webhook endpoint lets any attacker
        // cancel orders (payment.failed) or mark unpaid orders as paid (payment.captured).
        $webhookSecret = Mage::getStoreConfig('payment/razorpay/webhook_secret');
        $signature     = $this->getRequest()->getHeader('X-Razorpay-Signature');

        if (empty($webhookSecret))
        {
            // Webhook secret not configured — refuse all webhook calls until it is set.
            Mage::log('Razorpay webhook: webhook_secret not configured — request rejected', null, 'razorpay_webhook.log');
            $this->getResponse()->setHttpResponseCode(400);
            return;
        }

        if (empty($signature) || !$this->verifyWebhookSignature($rawBody, $signature, $webhookSecret))
        {
            Mage::log('Razorpay webhook: invalid or missing signature', null, 'razorpay_webhook.log');
            $this->getResponse()->setHttpResponseCode(400);
            return;
        }

        $payload = json_decode($rawBody, true);
        $event   = isset($payload['event']) ? $payload['event'] : '';

        Mage::log('Razorpay webhook received: ' . $event, null, 'razorpay_webhook.log');

        switch ($event)
        {
            case 'payment.captured':
                $this->handlePaymentCaptured($payload);
                break;

            case 'payment.failed':
                $this->handlePaymentFailed($payload);
                break;

            case 'order.paid':
                $this->handleOrderPaid($payload);
                break;

            case 'refund.created':
                $this->handleRefundCreated($payload);
                break;

            default:
                Mage::log('Razorpay webhook: unhandled event ' . $event, null, 'razorpay_webhook.log');
        }

        $this->getResponse()->setHttpResponseCode(200);
        $this->getResponse()->setBody(json_encode(array('status' => 'ok')));
    }

    /**
     * Verify Razorpay webhook signature using HMAC-SHA256.
     *
     * IMPORTANT: Uses a constant-time string comparison to prevent timing attacks.
     * hash_equals() is only available in PHP >= 5.6. For PHP 5.3–5.5 compatibility
     * we implement a constant-time comparison manually.
     *
     * @param string $body      Raw request body
     * @param string $signature X-Razorpay-Signature header value
     * @param string $secret    Webhook secret from admin config
     * @return bool
     */
    protected function verifyWebhookSignature($body, $signature, $secret)
    {
        $expectedSignature = hash_hmac('sha256', $body, $secret);

        // Constant-time comparison — prevents timing oracle attacks.
        // hash_equals() is PHP 5.6+; this fallback covers PHP 5.3–5.5.
        if (function_exists('hash_equals'))
        {
            return hash_equals($expectedSignature, $signature);
        }

        // Manual constant-time comparison for PHP < 5.6
        if (strlen($expectedSignature) !== strlen($signature))
        {
            return false;
        }

        $result = 0;
        for ($i = 0, $len = strlen($expectedSignature); $i < $len; $i++)
        {
            $result |= ord($expectedSignature[$i]) ^ ord($signature[$i]);
        }

        return $result === 0;
    }

    /**
     * Handle payment.captured event
     * Useful when payment is captured outside Magento (e.g. via dashboard)
     */
    protected function handlePaymentCaptured($payload)
    {
        $payment   = $payload['payload']['payment']['entity'];
        $paymentId = $payment['id'];
        $orderId   = isset($payment['notes']['merchant_order_id']) ? $payment['notes']['merchant_order_id'] : null;

        Mage::log('payment.captured: ' . $paymentId . ' for order: ' . $orderId, null, 'razorpay_webhook.log');

        // Optional: update Magento order status if needed
        if ($orderId)
        {
            $order = Mage::getModel('sales/order')->loadByIncrementId($orderId);
            if ($order->getId() && $order->getState() === Mage_Sales_Model_Order::STATE_PENDING_PAYMENT)
            {
                $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, 'processing', 'Payment confirmed via webhook: ' . $paymentId);
                $order->save();
            }
        }
    }

    /**
     * Handle payment.failed event.
     *
     * SECURITY: Only cancel orders that are in STATE_PENDING_PAYMENT.
     * Never cancel orders that are already processing/complete — this prevents
     * a scenario where a duplicate/late webhook cancels an already-confirmed order.
     * The orderId comes from Razorpay's payload (which has been signature-verified),
     * but we still restrict what states we'll act on.
     */
    protected function handlePaymentFailed($payload)
    {
        $payment   = $payload['payload']['payment']['entity'];
        $paymentId = $payment['id'];
        $orderId   = isset($payment['notes']['merchant_order_id']) ? $payment['notes']['merchant_order_id'] : null;

        Mage::log('payment.failed for order: ' . $orderId, null, 'razorpay_webhook.log');

        if ($orderId)
        {
            $order = Mage::getModel('sales/order')->loadByIncrementId($orderId);

            // Only cancel orders that are strictly pending payment.
            // Do NOT cancel processing/complete orders — a failed webhook must
            // never override a successful capture that already happened.
            if ($order->getId() && $order->getState() === Mage_Sales_Model_Order::STATE_PENDING_PAYMENT)
            {
                $order->cancel()->save();
            }
        }
    }

    /**
     * Handle order.paid event — fired when full order amount is paid
     */
    protected function handleOrderPaid($payload)
    {
        $razorpayOrder = $payload['payload']['order']['entity'];
        $receipt       = $razorpayOrder['receipt']; // Magento order increment ID

        Mage::log('order.paid: receipt=' . $receipt, null, 'razorpay_webhook.log');
    }

    /**
     * Handle refund.created event
     */
    protected function handleRefundCreated($payload)
    {
        $refund    = $payload['payload']['refund']['entity'];
        $refundId  = $refund['id'];
        $paymentId = $refund['payment_id'];

        Mage::log('refund.created: ' . $refundId . ' for payment: ' . $paymentId, null, 'razorpay_webhook.log');
    }
}
```

---

## Step 3 — Add Webhook Secret Config Field

Use the `add-admin-config` skill to add a `webhook_secret` field:

**`system.xml`** — add inside `<fields>`:
```xml
<webhook_secret translate="label">
    <label>Webhook Secret</label>
    <frontend_type>obscure</frontend_type>
    <backend_model>adminhtml/system_config_backend_encrypted</backend_model>
    <comment>Set this to the secret configured in Razorpay Dashboard > Webhooks</comment>
    <sort_order>90</sort_order>
    <show_in_default>1</show_in_default>
    <show_in_website>1</show_in_website>
    <show_in_store>0</show_in_store>
</webhook_secret>
```

**`config.xml`** — add default:
```xml
<webhook_secret></webhook_secret>
```

---

## Step 4 — Configure in Razorpay Dashboard

1. Go to Razorpay Dashboard → Settings → Webhooks
2. Add webhook URL: `https://yourstore.com/razorpay/webhook/index`
3. Set the same secret you configured in Magento admin
4. Select events: `payment.captured`, `payment.failed`, `order.paid`, `refund.created`
5. Save

---

## Step 5 — Secure the Endpoint

Add to `config.xml` under `<frontend>`:
```xml
<secure_url>
    <razorpay_order>/razorpay/order</razorpay_order>
    <razorpay_webhook>/razorpay/webhook</razorpay_webhook>
</secure_url>
```

---

## Security Checklist

- [ ] `webhook_secret` is configured in Magento admin **before** enabling the endpoint — requests are rejected when secret is empty
- [ ] Signature verification is **mandatory** — no code path processes the payload without a valid HMAC signature
- [ ] Constant-time comparison used (`hash_equals` with PHP 5.3 fallback) — no timing oracle
- [ ] `payment.failed` only cancels orders in `STATE_PENDING_PAYMENT` — never overrides already-processing orders
- [ ] No session initialisation in the webhook controller (no `setPost('form_key', ...)`)
- [ ] `webhook_secret` stored with `<backend_model>adminhtml/system_config_backend_encrypted</backend_model>`
- [ ] Webhook URL uses HTTPS (Razorpay rejects HTTP)
- [ ] Webhook URL registered in Razorpay Dashboard with matching secret
- [ ] Log entries do NOT contain `key_secret`, raw API responses, or card/account details
