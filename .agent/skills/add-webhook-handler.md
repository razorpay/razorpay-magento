# Skill: Add Razorpay Webhook Handler

**Trigger phrases:** "add webhook", "handle Razorpay events", "payment.captured webhook", "async payment notification", "webhook endpoint"

---

## What This Does

Adds a Razorpay webhook endpoint to the extension that receives async payment events from Razorpay (e.g., `payment.captured`, `order.paid`, `payment.failed`, `refund.created`). This is needed for reliable payment confirmation independent of browser-side callbacks.

---

## Why Webhooks Matter

The current extension relies entirely on the browser JS callback (`handler: onSuccess`). If the customer closes the browser after payment but before the form submits, the order is never created in Magento. Webhooks solve this.

---

## Step 1 — Register the Webhook Route

File: `app/code/community/Razorpay/Payments/etc/config.xml`

Add under `<frontend><routers>`:
```xml
<routers>
    <razorpay>
        <use>standard</use>
        <args>
            <module>Razorpay_Payments</module>
            <frontName>razorpay</frontName>
        </args>
    </razorpay>
</routers>
```
(Already present — the `razorpay` router covers all controllers.)

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
        $helper = Mage::helper('razorpay_payments');

        // Disable Magento's CSRF protection for this endpoint
        $this->getRequest()->setPost('form_key', Mage::getSingleton('core/session')->getFormKey());

        // Read raw POST body (webhooks send JSON, not form-encoded)
        $rawBody = file_get_contents('php://input');

        if (empty($rawBody))
        {
            $this->getResponse()->setHttpResponseCode(400);
            return;
        }

        // Verify webhook signature
        $webhookSecret = Mage::getStoreConfig('payment/razorpay/webhook_secret');
        $signature      = $this->getRequest()->getHeader('X-Razorpay-Signature');

        if (!empty($webhookSecret) && !$this->verifyWebhookSignature($rawBody, $signature, $webhookSecret))
        {
            Mage::log('Razorpay webhook: invalid signature', null, 'razorpay_webhook.log');
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
     * Verify Razorpay webhook signature using HMAC-SHA256
     */
    protected function verifyWebhookSignature($body, $signature, $secret)
    {
        $expectedSignature = hash_hmac('sha256', $body, $secret);
        return hash_equals($expectedSignature, $signature);
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
     * Handle payment.failed event
     */
    protected function handlePaymentFailed($payload)
    {
        $payment   = $payload['payload']['payment']['entity'];
        $paymentId = $payment['id'];
        $orderId   = isset($payment['notes']['merchant_order_id']) ? $payment['notes']['merchant_order_id'] : null;

        Mage::log('payment.failed: ' . $paymentId . ' for order: ' . $orderId, null, 'razorpay_webhook.log');

        // Optional: cancel the pending Magento order
        if ($orderId)
        {
            $order = Mage::getModel('sales/order')->loadByIncrementId($orderId);
            if ($order->getId() && $order->canCancel())
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

## Checklist

- [ ] `WebhookController.php` created with `indexAction()`
- [ ] Signature verification implemented with `hash_hmac('sha256', ...)`
- [ ] `webhook_secret` config field added (encrypted storage)
- [ ] Webhook URL registered in Razorpay Dashboard
- [ ] Events selected in Razorpay Dashboard match switch cases in controller
- [ ] Log file `var/log/razorpay_webhook.log` created and writable
- [ ] Tested with Razorpay's webhook test tool in Dashboard
- [ ] HTTPS required on production (Razorpay rejects HTTP webhook URLs)
