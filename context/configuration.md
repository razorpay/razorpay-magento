# Context: Admin Configuration

> For AI agents: Complete reference for all Magento admin configuration options and how they're used in code.

---

## Admin UI Location

`System > Configuration > Sales > Payment Methods > Razorpay`

---

## All Configuration Fields

### `active` (Enable/Disable)
- **Type**: Select (Yes/No)
- **Config path**: `payment/razorpay/active`
- **Default**: `0` (No/Disabled)
- **Scope**: Default + Website (not Store)
- **Used by**:
  - `Helper::isRazorpayEnabled()` → `Mage::getStoreConfigFlag('payment/razorpay/active')`
  - All phtml templates: `if (Mage::helper('razorpay_payments')->isRazorpayEnabled())`
  - IWD OPC: uses `isIwdOpcExtensionEnabled()` instead (which also checks IWD module)

### `key_id` (API Key)
- **Type**: Text input
- **Config path**: `payment/razorpay/key_id`
- **Default**: `'Key ID'` (placeholder)
- **Scope**: Default + Website (not Store — same key for all stores in website)
- **Used by**:
  - `Paymentmethod::getConfigData('key_id')` → used in `Helper::sendRequest()` for Basic Auth
  - `Block::Setuputils::getKeyId()` → passed to `RazorpayUtils.setKeyId()` in JS
  - Razorpay checkout modal as the `key` option
- **Format**: `rzp_live_xxx` (live) or `rzp_test_xxx` (test)

### `key_secret` (API Key Secret)
- **Type**: Text input (not password type — security concern)
- **Config path**: `payment/razorpay/key_secret`
- **Default**: `'Key Secret'` (placeholder)
- **Scope**: Default + Website
- **Used by**:
  - `Paymentmethod::getConfigData('key_secret')` → used in `Helper::sendRequest()` for Basic Auth
  - NEVER exposed to browser/JS
- **Security note**: Stored plaintext in `core_config_data`. Production Magento instances should use encrypted config values.

### `title` (Payment Method Title)
- **Type**: Text input
- **Config path**: `payment/razorpay/title`
- **Default**: `'Razorpay'`
- **Scope**: Default + Website + Store (translatable per store)
- **Used by**: Magento's payment method selection UI (shown to customer as payment option name)

### `merchant_name_override` (Override Merchant Name)
- **Type**: Text input
- **Config path**: `payment/razorpay/merchant_name_override`
- **Default**: `'Magento'`
- **Scope**: Default + Website (not Store)
- **Used by**:
  - `Block::Setuputils::getMerchantName()` → `razorpayUtils.setMerchantName()`
  - Razorpay checkout modal as the `name` option (merchant display name in popup)

### `payment_action` (Payment Action)
- **Type**: Not in system.xml (hidden/default only)
- **Config path**: `payment/razorpay/payment_action`
- **Default**: `'authorize_capture'`
- **Effect**: Magento calls both `authorize()` then `capture()`. Since `authorize()` is a no-op, effectively only `capture()` runs.

### `order_status` (New Order Status)
- **Type**: Select
- **Config path**: `payment/razorpay/order_status`
- **Default**: `'processing'`
- **Source**: `adminhtml/system_config_source_order_status_processing`
- **Scope**: Default + Website (not Store)
- **Used by**: Magento automatically sets order status to this value after successful payment

### `allowspecific` + `specificcountry` (Country Restrictions)
- **Type**: allowspecific + multiselect
- **Config paths**: `payment/razorpay/allowspecific`, `payment/razorpay/specificcountry`
- **Default**: All countries allowed
- **Scope**: Default + Website (not Store)
- **Used by**: Magento's native payment method availability check

### `sort_order`
- **Type**: Text input
- **Config path**: `payment/razorpay/sort_order`
- **Scope**: Default + Website + Store
- **Used by**: Order of payment methods in checkout UI

---

## Reading Config in Code

### PHP — Via Payment Model (recommended)
```php
$paymentModel = Mage::getModel('razorpay_payments/paymentmethod');
$keyId = $paymentModel->getConfigData('key_id');
$keySecret = $paymentModel->getConfigData('key_secret');
```

`getConfigData()` is store-aware — in admin, uses admin store; on frontend, uses current store.

### PHP — Via Mage::getStoreConfig
```php
$value = Mage::getStoreConfig('payment/razorpay/key_id');
$isEnabled = Mage::getStoreConfigFlag('payment/razorpay/active');
```

### PHP — Via Helper
```php
$helper = Mage::helper('razorpay_payments');
$isEnabled = $helper->isRazorpayEnabled();
```

### JavaScript — Via Block Output
Config is passed to JS only through PHP rendering in `setuputils.phtml`:
```php
// Block/Setuputils.php provides:
$this->getKeyId()        // payment/razorpay/key_id
$this->getMerchantName() // payment/razorpay/merchant_name_override
```

---

## Block/Setuputils.php

The `Setuputils` block is what bridges PHP config to JavaScript. It must implement `getKeyId()` and `getMerchantName()`. Currently:

```php
class Razorpay_Payments_Block_Setuputils extends Mage_Core_Block_Template
{
    // Currently empty — methods are likely inherited or defined via getData()
}
```

The block gets its data via layout XML `<action method="setData">` calls or template direct calls to `$this->getUrl()` etc. The template `setuputils.phtml` calls `$this->getKeyId()` and `$this->getMerchantName()` which use Magento's magic getter pattern (`getData('key_id')`).

**Note for AI agents**: `getKeyId()` and `getMerchantName()` are not explicitly defined in `Setuputils.php`. They rely on Magento's `Varien_Object::__call()` magic which maps `getXxx()` to `getData('xxx')`. The data must be set somehow — likely the block reads config directly. This may be a gap in the implementation.

---

## Admin Config Scope Notes

| Field | Default | Website | Store |
|-------|---------|---------|-------|
| active | ✓ | ✓ | ✗ |
| key_id | ✓ | ✓ | ✗ |
| key_secret | ✓ | ✓ | ✗ |
| title | ✓ | ✓ | ✓ |
| merchant_name_override | ✓ | ✓ | ✗ |
| order_status | ✓ | ✓ | ✗ |
| allowspecific | ✓ | ✓ | ✗ |
| specificcountry | ✓ | ✓ | ✗ |
| sort_order | ✓ | ✓ | ✓ |

Fields not available at Store scope cannot be overridden per store — they're shared across the website.

---

## Default Values (config.xml)

```xml
<default>
    <payment>
        <razorpay>
            <model>razorpay_payments/paymentmethod</model>
            <title>Razorpay</title>
            <merchant_name_override>Magento</merchant_name_override>
            <payment_action>authorize_capture</payment_action>
            <active>0</active>
            <order_status>processing</order_status>
            <key_id>Key ID</key_id>
            <key_secret>Key Secret</key_secret>
        </razorpay>
    </payment>
</default>
```

The `key_id` and `key_secret` defaults (`'Key ID'`, `'Key Secret'`) are placeholder strings — they'll fail API calls. Merchants must configure real keys before enabling.
