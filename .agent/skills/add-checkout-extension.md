# Skill: Add Checkout Extension Support

**Trigger phrases:** "add support for X checkout", "integrate Razorpay with X", "make Razorpay work on X checkout page"

---

## What This Does

Adds Razorpay payment modal support for a new third-party Magento 1 one-page checkout extension.
Every extension needs exactly: **1 layout handle block** + **1 new phtml template**.

---

## Pre-Flight: Gather These First

| Info Needed | How to Find It |
|-------------|----------------|
| Extension layout handle | Check extension's layout XML in `app/design/frontend/*/layout/` |
| HTML form ID | Inspect checkout page source, find `<form id="...">` wrapping payment fields |
| Place-order button selector | Inspect "Place Order" button, note its `id`, `class`, `type` |
| Final submit JS function | Read extension's JS — what does the native Place Order button call? |
| JS framework | Check if extension loads jQuery or uses Prototype.js |
| Enable config path | Does the extension have its own enabled/disabled flag in config? |

---

## Step 1 — Add Layout Block (`razorpay.xml`)

File: `app/design/frontend/base/default/layout/razorpay.xml`

Add before the closing `</layout>` tag:

```xml
<!-- Compatibility with {ExtensionName} start-->
<{layout_handle}>
    <reference name="head">
        <block type="core/text" name="razorpay.checkout">
            <action method="setText">
                <text><![CDATA[<script type="text/javascript" src="https://checkout.razorpay.com/v1/checkout.js"></script>]]></text>
            </action>
        </block>
        <action method="addJs"><file>razorpay/razorpay-utils.js</file></action>
    </reference>
    <reference name="before_body_end">
        <block type="razorpay_payments/setuputils" name="razorpay_payments_setuputils_{ext_id}" template="razorpay/setuputils.phtml" />
    </reference>
    <reference name="before_body_end">
        <block type="core/template" name="razorpay_placeorder_{ext_id}" template="razorpay/extensions/{ext_folder}/payments.phtml">
            <action method="setData"><key>form_id</key><value>{form_id}</value></action>
        </block>
    </reference>
</{layout_handle}>
<!-- Compatibility with {ExtensionName} end-->
```

Replace `{layout_handle}`, `{ext_id}`, `{ext_folder}`, `{form_id}` with real values.

---

## Step 2 — Create Template File

### Template A: Prototype.js Extension (most extensions)

Create: `app/design/frontend/base/default/template/razorpay/extensions/{ext_folder}/payments.phtml`

Model after `extensions/firecheckout/payments.phtml`:

```php
<?php $helper = Mage::helper('razorpay_payments'); ?>
<?php if ($helper->isRazorpayEnabled()) : ?>
    <?php $code = Razorpay_Payments_Model_Paymentmethod::METHOD_CODE; ?>
    <script type="text/javascript">
        var formId        = '<?php echo $this->getFormId() ?>';
        var paymentIdField = 'razorpay_payment_id';
        var nativeButton  = $$('BUTTON_SELECTOR')[0];

        nativeButton.setAttribute('onclick', 'processRazorpayOrder()');

        var processNativeExtensionOrder = function() {
            EXTENSION_SUBMIT_CALL; // e.g. checkout.save() or review.save()
        };

        var showErrorMessage = function(message) {
            alert(message);
            nativeButton.show();
        };

        var processRazorpayOrder = function() {
            if (new Validation(formId).validate()) {
                if (payment.currentMethod == '<?php echo $code ?>') {
                    razorpayUtils.isOrderSet()
                        ? placeRazorpayOrder()
                        : razorpayUtils.createOrder(placeRazorpayOrder, showErrorMessage);
                } else {
                    processNativeExtensionOrder();
                }
            }
        };

        var placeRazorpayOrder = function() {
            razorpayUtils.placeOrder(
                function(data) { $(paymentIdField).value = data.razorpay_payment_id; processNativeExtensionOrder(); },
                function()     { nativeButton.disabled = false; },
                function()     { nativeButton.disabled = true;  },
                formId,
                paymentIdField
            );
        };
    </script>
<?php endif; ?>
```

### Template B: jQuery Extension

See `extensions/iwd_opc/payments.phtml` for full pattern.
Key differences: use `$j_opc(document).on/off('click', ...)` and call `isIwdOpcExtensionEnabled()` guard.

---

## Step 3 — Add Extension Detection (if needed)

If the extension has its own enable flag, add to `Helper/Data.php`:

```php
public function is{ExtensionName}Enabled()
{
    return (Mage::getStoreConfigFlag('{extension/config/path}') and
        Mage::helper('core')->isModuleOutputEnabled('{Module_Name}'));
}
```

Then use this guard in the phtml instead of `isRazorpayEnabled()`.

---

## Step 4 — Verify

- [ ] Razorpay modal opens when Razorpay is selected and Place Order is clicked
- [ ] Modal dismiss re-enables button and stays on checkout page
- [ ] Other payment methods still submit normally (not through Razorpay flow)
- [ ] Form validation fires before modal opens
- [ ] `razorpay_payment_id` is present in POST when Magento creates the order
- [ ] Order is created with status `processing` after payment

---

## Reference

| File | Purpose |
|------|---------|
| `context/extension-flows.md` | Deep-dive on each existing extension |
| `extensions/firecheckout/payments.phtml` | Prototype.js reference |
| `extensions/iwd_opc/payments.phtml` | jQuery reference |
| `docs/LLD.md` | Sequence diagrams for all flows |
