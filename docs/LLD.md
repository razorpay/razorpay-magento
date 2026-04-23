# Low-Level Design (LLD) — Razorpay Magento Extension

## 1. Native Onepage Checkout — Full Sequence Diagram

```mermaid
sequenceDiagram
    participant C as Customer Browser
    participant ML as Magento Layout (razorpay.xml)
    participant SU as setuputils.phtml
    participant RU as RazorpayUtils.js
    participant OR as order.phtml (Payment.save override)
    participant PO as placeorder.phtml (Button intercept)
    participant CTRL as CheckoutController::orderAction()
    participant HELP as Helper::Data
    participant RZP as Razorpay API v1
    participant RZPJS as checkout.js (CDN)
    participant PAY as Paymentmethod::capture()

    Note over C,PAY: Page Load Phase
    C->>ML: GET /checkout/onepage
    ML-->>C: Loads checkout.js (CDN) + razorpay-utils.js
    ML-->>C: Renders setuputils.phtml in body

    Note over C,SU: Initialization Phase
    SU-->>C: new RazorpayUtils({orderUrl: '/razorpay/checkout/order'})
    SU-->>C: razorpayUtils.setKeyId('rzp_live_xxx')
    SU-->>C: razorpayUtils.setMerchantName('Magento')

    Note over C,OR: Payment Step Load (AJAX panel)
    C->>ML: POST /checkout/onepage/savepayment (selects Razorpay)
    ML-->>C: Renders order.phtml
    OR-->>C: Overrides Payment.prototype.save()

    Note over C,PO: Review Step Load (AJAX panel)
    C->>ML: POST /checkout/onepage/saveshipping
    ML-->>C: Renders placeorder.phtml
    PO-->>C: Removes btn-checkout onclick
    PO-->>C: Adds Event.observe(btn, 'click', placeOrder)

    Note over C,RZP: Place Order Click
    C->>PO: Clicks "Place Order"
    PO->>PO: Check payment.currentMethod == 'razorpay'
    PO->>PO: beforeStart() — disable button

    Note over OR,RZP: Order Creation via Payment.save override
    OR->>CTRL: AJAX POST /razorpay/checkout/order
    CTRL->>CTRL: _getQuote().getBaseGrandTotal() * 100
    CTRL->>CTRL: reserveOrderId() if not set
    CTRL->>HELP: createOrder(orderId, amount)
    HELP->>HELP: isRazorpayEnabled() check
    HELP->>RZP: POST /v1/orders {receipt, amount, currency: INR}
    RZP-->>HELP: {id: 'order_xxx', ...}
    HELP-->>CTRL: ['razorpay_order_id' => 'order_xxx']
    CTRL->>CTRL: Append customer_name, phone, email, currency
    CTRL-->>OR: JSON response {razorpay_order_id, amount, currency, ...}
    OR->>RU: orderInfo = response

    Note over C,RZPJS: Modal Launch
    PO->>RU: razorpayUtils.placeOrder(onSuccess, onUserClose, beforeStart, formId, paymentIdField)
    RU->>RU: createHiddenInput({name: "payment[razorpay_payment_id]"}, 'co-payment-form')
    RU->>RZPJS: new Razorpay({key, name, amount, currency, order_id, handler, prefill, notes})
    RZPJS-->>C: Opens payment modal

    alt Payment Success
        C->>RZPJS: Completes payment
        RZPJS-->>RU: handler(data) with data.razorpay_payment_id
        RU->>PO: onSuccess(data)
        PO->>PO: $(paymentIdField).value = data.razorpay_payment_id
        PO->>C: review.save() — submit form

        Note over C,PAY: Magento Order Creation + Capture
        C->>PAY: POST /checkout/onepage/saveOrder (form includes razorpay_payment_id)
        PAY->>PAY: getPost('payment')['razorpay_payment_id']
        PAY->>HELP: capturePayment(paymentId, amount * 100)
        HELP->>RZP: POST /v1/payments/{id}/capture {amount: paise}
        RZP-->>HELP: {status: 'captured', ...}
        HELP-->>PAY: true
        PAY->>PAY: setStatus(APPROVED), setTransactionId(paymentId)
        PAY-->>C: Order created, redirect to success

    else Payment Cancelled/Failed
        C->>RZPJS: Closes modal
        RZPJS-->>RU: modal.ondismiss()
        RU->>PO: onUserClose()
        PO->>PO: Remove hidden input field
        PO->>C: Enable place order button (defaultPlaceOrderButton.disabled = false)
    end
```

---

## 2. FireCheckout Extension — Sequence Diagram

```mermaid
sequenceDiagram
    participant C as Customer Browser
    participant FC as FireCheckout Extension JS
    participant FT as extensions/firecheckout/payments.phtml
    participant RU as RazorpayUtils.js
    participant CTRL as CheckoutController
    participant HELP as Helper::Data
    participant RZP as Razorpay API
    participant RZPJS as checkout.js

    Note over C,FT: Page Load (firecheckout_index_index handle)
    C->>FT: Page loads - phtml executes
    FT->>FT: Get nativeButton = #review-buttons-container .btn-checkout
    FT->>FC: nativeButton.setAttribute('onclick', 'processRazorpayOrder()')

    Note over C,RZP: Customer clicks Place Order
    C->>FT: Clicks place order button
    FT->>FT: processRazorpayOrder()
    FT->>FT: new Validation('firecheckout-form').validate()

    alt Form Valid + Razorpay Selected
        FT->>FT: payment.currentMethod == 'razorpay'?

        alt Order not yet created
            FT->>RU: razorpayUtils.createOrder(placeRazorpayOrder, showErrorMessage)
            RU->>CTRL: AJAX POST /razorpay/checkout/order
            CTRL->>HELP: createOrder(orderId, amount)
            HELP->>RZP: POST /v1/orders
            RZP-->>HELP: {id: 'order_xxx'}
            HELP-->>CTRL: order response
            CTRL-->>RU: JSON with orderInfo
        else Order already created (isOrderSet)
            FT->>FT: placeRazorpayOrder() directly
        end

        Note over C,RZPJS: Razorpay Modal
        FT->>RU: razorpayUtils.placeOrder(onSuccess, onUserClose, beforeStart, 'firecheckout-form', 'razorpay_payment_id')
        RU->>RZPJS: new Razorpay(options).open()
        RZPJS-->>C: Modal shown

        alt Success
            C->>RZPJS: Payment completed
            RZPJS-->>FT: onSuccess(data)
            FT->>FT: $(paymentIdField).value = data.razorpay_payment_id
            FT->>FC: processNativeExtensionOrder() → checkout.save()
            FC-->>C: Order placed
        else Dismiss
            FT->>FT: onUserClose() — re-enable button
        end

    else Different payment method
        FT->>FC: processNativeExtensionOrder() → checkout.save()
    end
```

---

## 3. IWD OPC Extension — Sequence Diagram

```mermaid
sequenceDiagram
    participant C as Customer Browser
    participant IWD as IWD.OPC Extension
    participant IT as extensions/iwd_opc/payments.phtml
    participant RU as RazorpayUtils.js
    participant CTRL as CheckoutController
    participant RZP as Razorpay API
    participant RZPJS as checkout.js

    Note over C,IT: DOM Ready
    C->>IT: Document ready
    IT->>IWD: removeSubmitOrderEvents() — removes .opc-btn-checkout click listeners
    IT->>IT: addRazorpayFlow() — $j_opc(document).on('click', '.opc-btn-checkout', processRazorpayOrder)

    Note over C,RZP: Customer clicks Place Order
    C->>IT: Clicks .opc-btn-checkout
    IT->>IT: processRazorpayOrder()
    IT->>IT: Validate opc-address-form-billing (VarienForm)

    alt Shipping not same as billing
        IT->>IT: Validate opc-address-form-shipping
    end

    alt Razorpay selected
        IT->>IWD: IWD.OPC.Checkout.showLoader()

        alt Order not set
            IT->>RU: razorpayUtils.createOrder(placeRazorpayOrder, showErrorMessage)
            RU->>CTRL: AJAX POST /razorpay/checkout/order
            CTRL->>RZP: POST /v1/orders
            RZP-->>CTRL: order created
            CTRL-->>RU: orderInfo JSON
        end

        IT->>RU: razorpayUtils.placeOrder(onSuccess, onUserClose, beforeStart, formId, paymentIdField)
        RU->>RZPJS: new Razorpay(options).open()
        RZPJS-->>C: Modal opens

        alt Payment Success
            C->>RZPJS: Completes payment
            RZPJS-->>IT: onSuccess(data)
            IT->>IT: $(paymentIdField).value = data.razorpay_payment_id
            IT->>IWD: processNativeExtensionOrder()
            IWD->>IWD: hideLoader()
            IWD->>IWD: removeSubmitOrderEvents()
            IWD->>IWD: IWD.OPC.initSaveOrder()
            IWD->>IT: click .opc-btn-checkout (no Razorpay listener now)
            IT->>IT: setTimeout → addRazorpayFlow() (restore for future)
        else Modal Dismissed
            RZPJS-->>IT: ondismiss
            IT->>IWD: hideLoader()
        end

    else Other payment
        IT->>IWD: processNativeExtensionOrder()
    end
```

---

## 4. AppZab OSC Extension — Sequence Diagram

```mermaid
sequenceDiagram
    participant C as Customer Browser
    participant AT as extensions/appzab_osc/payments.phtml
    participant RU as RazorpayUtils.js
    participant CTRL as CheckoutController
    participant RZP as Razorpay API
    participant RZPJS as checkout.js
    participant REV as review object

    Note over C,AT: Page Load (onestepcheckout_index_index)
    C->>AT: Page renders
    AT->>AT: defaultPlaceOrderButton = $$('button.btn-checkout')[0]
    AT->>AT: button.setAttribute('onclick', 'processRazorpayOrder()')

    Note over C,RZP: Customer clicks Place Order
    C->>AT: Clicks button → processRazorpayOrder()
    AT->>AT: new Validation('co-form').validate()

    alt Form valid
        AT->>AT: review.loadingbox() — show loading

        alt Razorpay selected
            alt Order already set
                AT->>AT: review.onComplete()
                AT->>AT: placeRazorpayOrder()
            else Order not set
                AT->>RU: razorpayUtils.createOrder(placeRazorpayOrder, onError)
                RU->>CTRL: AJAX POST /razorpay/checkout/order
                CTRL->>RZP: POST /v1/orders
                RZP-->>CTRL: {id: 'order_xxx', ...}
                CTRL-->>RU: JSON orderInfo
                RU->>AT: placeRazorpayOrder()
            end

            AT->>RU: razorpayUtils.placeOrder(onSuccess, onUserClose, beforeStart, 'co-form', 'razorpay_payment_id')
            RU->>RZPJS: new Razorpay(options).open()
            RZPJS-->>C: Modal opens

            alt Success
                C->>RZPJS: Pays
                RZPJS-->>AT: onSuccess(data)
                AT->>AT: $(paymentIdField).value = data.razorpay_payment_id
                AT->>REV: review.save()
                REV-->>C: Order placed
            else Cancel
                RZPJS-->>AT: ondismiss
                AT->>AT: onUserClose() — re-enable button
            end

        else Different payment
            AT->>REV: review.save()
        end
    end
```

---

## 5. Payment Capture — Detailed LLD

```mermaid
sequenceDiagram
    participant FORM as Browser Form Submit
    participant MAGE as Magento Onepage Controller
    participant PAY as Paymentmethod::capture()
    participant HELP as Helper::Data::capturePayment()
    participant CURL as cURL HTTP Client
    participant RZP as Razorpay API

    FORM->>MAGE: POST /checkout/onepage/saveOrder
    Note over FORM,MAGE: POST body includes: payment[razorpay_payment_id]=pay_xxx

    MAGE->>PAY: $payment->capture($amount)
    PAY->>PAY: getPost('payment')['razorpay_payment_id'] = 'pay_xxx'
    PAY->>PAY: $order->getIncrementId() — for logging
    PAY->>PAY: $_preparedAmount = $amount * 100

    PAY->>HELP: capturePayment('pay_xxx', preparedAmount)
    HELP->>HELP: isRazorpayEnabled() check
    HELP->>HELP: getRelativeUrl('capture', [':id' => 'pay_xxx'])
    Note over HELP: URL = https://api.razorpay.com/v1/payments/pay_xxx/capture

    HELP->>CURL: sendRequest(url, {amount: paise}, 'POST')
    CURL->>CURL: curl_setopt CURLOPT_USERPWD = key_id:key_secret
    CURL->>CURL: curl_setopt CURLOPT_POSTFIELDS = amount=10000
    CURL->>RZP: POST /v1/payments/pay_xxx/capture

    alt HTTP 200 + status: captured
        RZP-->>CURL: {status: 'captured', id: 'pay_xxx', amount: 10000}
        CURL-->>HELP: responseArray
        HELP->>HELP: response['status'] === 'captured' → return true
        HELP-->>PAY: true

        PAY->>PAY: setStatus(STATUS_APPROVED)
        PAY->>PAY: setAmountPaid(amount)
        PAY->>PAY: setLastTransId('pay_xxx')
        PAY->>PAY: setTransactionId('pay_xxx')
        PAY->>PAY: setIsTransactionClosed(true)
        PAY-->>MAGE: return $this (success)
        MAGE-->>FORM: Order created, redirect to success

    else HTTP error or status != captured
        RZP-->>CURL: {error: {code: '...', description: '...'}}
        CURL-->>HELP: throw Exception('RAZORPAY_ERROR: ...')
        HELP-->>PAY: Exception propagated
        PAY-->>MAGE: Mage::throwException('There was an error capturing...')
        MAGE-->>FORM: Checkout error shown
    end
```

---

## 6. JS RazorpayUtils Class — LLD

```mermaid
classDiagram
    class RazorpayUtils {
        -String orderUrl
        -String keyId
        -String merchantName
        -Object orderInfo
        +initialize(urls)
        +setKeyId(key)
        +setMerchantName(merchant)
        +getMerchantName() String
        +isOrderSet() Boolean
        +createHiddenInput(attributes, formId)
        +createOrder(onSuccess, onFailure)
        +placeOrder(onSuccess, onUserClose, beforeStart, formId, paymentIdField)
    }

    class RazorpayCheckout {
        <<external CDN>>
        +constructor(options)
        +open()
    }

    class MagentoAjax {
        <<Prototype.js>>
        +new Ajax.Request(url, options)
    }

    RazorpayUtils --> RazorpayCheckout : creates instance
    RazorpayUtils --> MagentoAjax : calls for order creation
```

---

## 7. createOrder() Flow — Detailed

```mermaid
flowchart TD
    A[createOrder called] --> B{typeof onSuccess == function?}
    B -->|yes| C[Store onSuccess callback]
    B -->|no| C
    C --> D[new Ajax.Request to orderUrl]
    D --> E{Response received?}
    E -->|onSuccess| F[self.orderInfo = event.responseJSON]
    F --> G[Call onSuccess callback]
    E -->|onFailure| H[Call onFailure callback]
    G --> I[Caller proceeds with placeOrder]
    H --> J[Show error or re-enable UI]
```

---

## 8. placeOrder() Flow — Detailed

```mermaid
flowchart TD
    A[placeOrder called] --> B{beforeStart defined?}
    B -->|yes| C[beforeStart - disable button]
    B -->|no| D
    C --> D[createHiddenInput in form\nname=payment.razorpay_payment_id]
    D --> E[Build Razorpay options object]
    E --> F{quote_currency != INR?}
    F -->|yes| G[Add display_amount + display_currency]
    F -->|no| H
    G --> H[new Razorpay options]
    H --> I[checkout.open - show modal]
    I --> J{Customer action?}
    J -->|Pays successfully| K[handler data called]
    J -->|Closes modal| L[modal.ondismiss called]
    K --> M[Call onSuccess with data.razorpay_payment_id]
    L --> N[Call onUserClose]
    M --> O[Caller injects payment_id + submits form]
    N --> P[Re-enable UI elements]
```

---

## 9. Helper::sendRequest() — Detailed Flow

```mermaid
flowchart TD
    A[sendRequest url content method] --> B[Get key_id + key_secret from config]
    B --> C[curl_init]
    C --> D[Set CURLOPT_URL]
    D --> E[Set CURLOPT_USERPWD = key_id:key_secret]
    E --> F[Set CURLOPT_TIMEOUT = 60s]
    F --> G[Set CURLOPT_USERAGENT = channel string]
    G --> H{method == POST?}
    H -->|yes| I[Set CURLOPT_POST + CURLOPT_POSTFIELDS]
    H -->|no| J
    I --> J[Set CURLOPT_RETURNTRANSFER = true]
    J --> K[curl_exec]
    K --> L{response == false?}
    L -->|yes| M[throw Exception CURL_ERROR + curl_error]
    L -->|no| N[Parse JSON response]
    N --> O{HTTP status in successHttpCodes AND no error key?}
    O -->|yes| P[return responseArray]
    O -->|no| Q{error.code exists?}
    Q -->|yes| R[throw error.code: error.description]
    Q -->|no| S[throw RAZORPAY_ERROR: Invalid Response]
```
