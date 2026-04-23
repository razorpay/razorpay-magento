# Skill: Add Admin Configuration Field

**Trigger phrases:** "add config for X", "make X configurable", "add admin setting for X", "add toggle for X"

---

## What This Does

Adds a new field to the Razorpay payment method admin configuration panel at:
`System > Configuration > Sales > Payment Methods > Razorpay`

Requires changes in exactly **two files**: `system.xml` (UI) and `config.xml` (default value).

---

## Step 1 — Define the Field in `system.xml`

File: `app/code/community/Razorpay/Payments/etc/system.xml`

Add inside the `<fields>` block, under the last existing `<field>` block:

### Text Input Field
```xml
<your_field_name translate="label">
    <label>Your Field Label</label>
    <frontend_type>text</frontend_type>
    <sort_order>200</sort_order>
    <show_in_default>1</show_in_default>
    <show_in_website>1</show_in_website>
    <show_in_store>0</show_in_store>
</your_field_name>
```

### Yes/No Toggle (Select)
```xml
<your_flag_name translate="label">
    <label>Enable Feature X</label>
    <frontend_type>select</frontend_type>
    <source_model>adminhtml/system_config_source_yesno</source_model>
    <sort_order>200</sort_order>
    <show_in_default>1</show_in_default>
    <show_in_website>1</show_in_website>
    <show_in_store>0</show_in_store>
</your_flag_name>
```

### Dropdown (Custom Options)
```xml
<your_select_name translate="label">
    <label>Payment Action</label>
    <frontend_type>select</frontend_type>
    <source_model>razorpay_payments/source_yoursource</source_model>
    <sort_order>200</sort_order>
    <show_in_default>1</show_in_default>
    <show_in_website>1</show_in_website>
    <show_in_store>0</show_in_store>
</your_select_name>
```

### Multiline Textarea
```xml
<your_textarea translate="label">
    <label>Description</label>
    <frontend_type>textarea</frontend_type>
    <sort_order>200</sort_order>
    <show_in_default>1</show_in_default>
    <show_in_website>1</show_in_website>
    <show_in_store>1</show_in_store>
</your_textarea>
```

### Password (Hidden input)
```xml
<your_secret translate="label">
    <label>API Secret</label>
    <frontend_type>obscure</frontend_type>
    <backend_model>adminhtml/system_config_backend_encrypted</backend_model>
    <sort_order>200</sort_order>
    <show_in_default>1</show_in_default>
    <show_in_website>1</show_in_website>
    <show_in_store>0</show_in_store>
</your_secret>
```

**Scope guidance:**

| `show_in_store` | Use when |
|----------------|----------|
| `1` | Field can vary per store (e.g. display labels, sort order) |
| `0` | Field is global/website-level (e.g. API keys, toggles) |

---

## Step 2 — Set Default Value in `config.xml`

File: `app/code/community/Razorpay/Payments/etc/config.xml`

Add inside `<default><payment><razorpay>`:

```xml
<default>
    <payment>
        <razorpay>
            <!-- existing fields ... -->
            <your_field_name>your_default_value</your_field_name>
        </razorpay>
    </payment>
</default>
```

Examples:
```xml
<enable_xyz>0</enable_xyz>          <!-- default: disabled -->
<timeout_seconds>60</timeout_seconds>
<display_label>Pay with Razorpay</display_label>
```

---

## Step 3 — Read the Value in PHP

### Simple string/number value
```php
$value = Mage::getModel('razorpay_payments/paymentmethod')->getConfigData('your_field_name');
// or
$value = Mage::getStoreConfig('payment/razorpay/your_field_name');
```

### Boolean flag
```php
$isEnabled = Mage::getStoreConfigFlag('payment/razorpay/your_flag_name');
// Returns true/false
```

### In Helper
```php
public function isFeatureXEnabled()
{
    return Mage::getStoreConfigFlag('payment/razorpay/your_flag_name');
}
```

---

## Step 4 — Pass to JavaScript (if needed)

If the config value needs to be available in frontend JS, add it in `Block/Setuputils.php` and output it in `setuputils.phtml`.

**Block (`Setuputils.php`):**
```php
public function getFeatureXEnabled()
{
    return Mage::getStoreConfigFlag('payment/razorpay/your_flag_name');
}
```

**Template (`setuputils.phtml`):**
```php
<?php if (Mage::helper('razorpay_payments')->isRazorpayEnabled()) : ?>
<script type="text/javascript">
    // existing init...
    razorpayUtils.setFeatureX(<?php echo $this->getFeatureXEnabled() ? 'true' : 'false' ?>);
</script>
<?php endif; ?>
```

**JS class (`razorpay-utils.js`):**
```javascript
setFeatureX: function(enabled) {
    this.featureXEnabled = enabled;
},
```

---

## Checklist

- [ ] Field added to `system.xml` inside `<fields>` block
- [ ] Default value set in `config.xml` inside `<default><payment><razorpay>`
- [ ] Config path is `payment/razorpay/{your_field_name}` (consistent)
- [ ] `show_in_store` matches whether the value needs per-store override
- [ ] `sort_order` is higher than existing fields to appear at the bottom
- [ ] Clear Magento config cache after changes: `var/cache/` delete or admin cache flush
