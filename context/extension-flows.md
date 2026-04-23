# Context: Third-Party Extension Flows

> For AI agents: This file explains how Razorpay integrates with non-native Magento checkout extensions.
> Cross-reference with `docs/LLD.md` for sequence diagrams.

---

## Why Extension-Specific Integration is Needed

Standard Magento checkout extensions override the native checkout flow in different ways:
- Different form IDs
- Different button selectors and event mechanisms
- Different JS frameworks (IWD OPC uses jQuery)
- Different loading state APIs
- Different final submission methods

Each integration file (`extensions/{ext}/payments.phtml`) handles these differences while using the same `RazorpayUtils` core library.

---

## Common Pattern Across All Extensions

All extension integrations share:
1. **Detection**: Check `payment.currentMethod == 'razorpay'`
2. **Order creation**: `razorpayUtils.createOrder(placeRazorpayOrder, onError)`
3. **Modal opening**: `razorpayUtils.placeOrder(onSuccess, onUserClose, beforeStart, formId, paymentIdField)`
4. **Payment ID injection**: `$(paymentIdField).value = data.razorpay_payment_id`
5. **Native submit**: Call the extension's own order submission function

What varies: button interception, form ID, loading states, submit function.

---

## FireCheckout Integration Deep Dive

**File:** `extensions/firecheckout/payments.phtml`

### The Interception Strategy
FireCheckout has a single page with a `#review-buttons-container .btn-checkout` button. The template replaces the button's `onclick` attribute with `processRazorpayOrder()`.

```javascript
var nativeButton = $$('#review-buttons-container .btn-checkout').first();
nativeButton.setAttribute('onclick', 'processRazorpayOrder()');
```

### Order Caching (`isOrderSet()`)
FireCheckout's single-page nature means the payment order might already be created if the customer has been on the page a while. `isOrderSet()` prevents a double API call:

```javascript
if (razorpayUtils.isOrderSet()) {
    placeRazorpayOrder();  // Skip createOrder, use cached orderInfo
} else {
    razorpayUtils.createOrder(placeRazorpayOrder, showErrorMessage);
}
```

### Native Submit
FireCheckout uses `checkout.save()` (its own global), not Magento's native `review.save()`:
```javascript
var processNativeExtensionOrder = function() {
    nativeButton.show();
    checkout.save();  // FireCheckout's submit
};
```

### Error Display
Simpler than IWD — uses standard `alert()` and re-shows the button:
```javascript
var showErrorMessage = function(message) {
    alert(message);
    nativeButton.show();
};
```

---

## IWD OPC Integration Deep Dive

**File:** `extensions/iwd_opc/payments.phtml`
**Enable check:** `Helper::isIwdOpcExtensionEnabled()` (checks IWD config + module enabled)

### The Event Listener Management Challenge
IWD OPC registers its own click listener on `.opc-btn-checkout`. We can't just override `onclick` because IWD uses jQuery's event system. The solution:

```javascript
// Remove IWD's listener
var removeSubmitOrderEvents = function() {
    $j_opc(document).off('click', '.opc-btn-checkout');
};

// Add our listener
var addRazorpayFlow = function() {
    $j_opc(document).on('click', '.opc-btn-checkout', processRazorpayOrder);
};

// On DOM ready: disable IWD's native listener, add ours
$j_opc(document).ready(function() {
    removeSubmitOrderEvents();
    addRazorpayFlow();
});
```

### Address Validation
IWD OPC requires explicit address validation before payment:
```javascript
var addressForm = new VarienForm('opc-address-form-billing');
if (!addressForm.validator.validate()) { return; }

// Also validate shipping if separate
if (!$j_opc('input[name="billing[use_for_shipping]"]').prop('checked')) {
    var shippingForm = new VarienForm('opc-address-form-shipping');
    if (!shippingForm.validator.validate()) { return; }
}
```

### The "Temporary Unlisten" Pattern for Native Submit
After successful payment, we need IWD to process the order natively, but without our Razorpay listener interfering:

```javascript
var processNativeExtensionOrder = function() {
    IWD.OPC.Checkout.hideLoader();
    removeSubmitOrderEvents();     // 1. Remove Razorpay listener
    
    IWD.OPC.initSaveOrder();       // 2. Re-init IWD's save process
    
    $j_opc('.opc-btn-checkout').disabled = 'false';
    $j_opc('.opc-btn-checkout').click();  // 3. Click button (IWD listener now active)
    
    removeSubmitOrderEvents();     // 4. Remove again (IWD's listener re-added by initSaveOrder)
    setTimeout(addRazorpayFlow(), 500);   // 5. Re-add Razorpay listener after 500ms
};
```

**Note:** The `setTimeout(addRazorpayFlow(), 500)` has a bug — it calls `addRazorpayFlow()` immediately (returns undefined) then schedules `undefined`. It should be `setTimeout(addRazorpayFlow, 500)`. This means Razorpay's listener is re-added immediately, not after 500ms.

### jQuery Namespace
IWD OPC ships with a namespaced jQuery to avoid conflicts:
```javascript
$j_opc  // IWD's jQuery
```
This is why the integration can't use Prototype.js for IWD-specific operations.

---

## AppZab OneStepCheckout Integration Deep Dive

**File:** `extensions/appzab_osc/payments.phtml`

### Button Selector Difference
AppZab uses `type=button` (not `type=submit`) for its checkout button:
```javascript
var defaultPlaceOrderButton = $$('button[type=button][class~="btn-checkout"]')[0];
```
Compare with native checkout: `button[type=submit][class~="btn-checkout"]`

### Early Order Completion Signal
Unique to AppZab: if the order is already set, AppZab requires an explicit "complete" signal before opening the modal:
```javascript
if (razorpayUtils.isOrderSet()) {
    review.onComplete();  // Tell AppZab the review is complete
    placeRazorpayOrder();
} else {
    razorpayUtils.createOrder(placeRazorpayOrder, onError);
}
```

### Error Handling via `checkout.ajaxFailure`
AppZab uses a more integrated error display:
```javascript
var onError = function(message) {
    $("review-please").update('');
    $('review-btn').disabled = '';
    checkout.ajaxFailure(message);  // Uses AppZab/Magento's error display
};
```

---

## Adding Support for a New Extension

To add support for a new third-party checkout extension:

### 1. Identify the Extension's Properties
- Layout handle (e.g., `myextension_index_index`)
- Form ID containing payment fields
- Place order button CSS selector
- JS framework (Prototype? jQuery?)
- Submit function name
- Loading state API

### 2. Add Layout XML Block (`razorpay.xml`)
```xml
<myextension_index_index>
    <reference name="head">
        <block type="core/text" name="razorpay.checkout">
            <action method="setText">
                <text><![CDATA[<script src="https://checkout.razorpay.com/v1/checkout.js"></script>]]></text>
            </action>
        </block>
        <action method="addJs"><file>razorpay/razorpay-utils.js</file></action>
    </reference>
    <reference name="before_body_end">
        <block type="razorpay_payments/setuputils" name="razorpay_payments_setuputils_myext" template="razorpay/setuputils.phtml" />
    </reference>
    <reference name="before_body_end">
        <block type="core/template" name="razorpay_placeorder_myextension" template="razorpay/extensions/myextension/payments.phtml">
            <action method="setData"><key>form_id</key><value>my-checkout-form</value></action>
        </block>
    </reference>
</myextension_index_index>
```

### 3. Create Template File
Follow the pattern in `extensions/firecheckout/payments.phtml`:
```php
<?php if (Mage::helper('razorpay_payments')->isRazorpayEnabled()) : ?>
<script type="text/javascript">
    var formId = '<?php echo $this->getFormId() ?>';
    var paymentIdField = 'razorpay_payment_id';
    var nativeButton = /* find the button */;
    
    nativeButton.setAttribute('onclick', 'processRazorpayOrder()');
    
    var processRazorpayOrder = function() {
        // validate form
        if (payment.currentMethod == '<?php echo Razorpay_Payments_Model_Paymentmethod::METHOD_CODE ?>') {
            if (razorpayUtils.isOrderSet()) {
                placeRazorpayOrder();
            } else {
                razorpayUtils.createOrder(placeRazorpayOrder, showErrorMessage);
            }
        } else {
            processNativeExtensionOrder();
        }
    };
    
    var placeRazorpayOrder = function() {
        razorpayUtils.placeOrder(onSuccess, onUserClose, beforeStart, formId, paymentIdField);
    };
    
    var onSuccess = function(data) {
        $(paymentIdField).value = data.razorpay_payment_id;
        processNativeExtensionOrder();
    };
    
    var processNativeExtensionOrder = function() {
        /* extension's submit function */
    };
</script>
<?php endif; ?>
```
