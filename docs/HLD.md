# High-Level Design (HLD) — Razorpay Magento Extension

## 1. System Overview

The Razorpay Magento extension is a **payment gateway integration** that bridges Magento 1.x's checkout system with Razorpay's payment processing platform. It operates as a classic Magento Community module following the `Razorpay_Payments` namespace.

---

## 2. HLD Architecture Diagram

```mermaid
graph TB
    subgraph Customer["Customer Browser"]
        C[Customer] --> MUI[Magento Checkout UI]
        MUI --> JS[RazorpayUtils.js]
        JS --> RZPJS[Razorpay checkout.js CDN]
    end

    subgraph Magento["Magento 1.x Application Server"]
        direction TB
        CTRL[CheckoutController\n/razorpay/checkout/order]
        HELPER[Helper::Data\nAPI Gateway Layer]
        MODEL[Paymentmethod Model\nCapture Logic]
        CONFIG[Magento Config\npayment/razorpay/*]
        BLOCK[Block::Setuputils\nPHP→JS Bridge]

        CTRL --> HELPER
        MODEL --> HELPER
        BLOCK --> CONFIG
        MODEL --> CONFIG
        HELPER --> CONFIG
    end

    subgraph Razorpay["Razorpay Platform (External)"]
        RZPAPI[Razorpay REST API v1]
        RZPAPI --> ORDER_EP[POST /v1/orders]
        RZPAPI --> CAPTURE_EP[POST /v1/payments/:id/capture]
    end

    MUI -- AJAX POST --> CTRL
    CTRL -- returns JSON --> JS
    JS -- opens modal --> RZPJS
    RZPJS -- payment_id callback --> JS
    JS -- form submit --> MODEL
    MODEL -- HTTP Basic Auth --> RZPAPI

    style Customer fill:#e8f5e9
    style Magento fill:#e3f2fd
    style Razorpay fill:#fce4ec
```

---

## 3. Component Interaction Overview

```mermaid
graph LR
    subgraph Frontend["Frontend Layer"]
        LAY[razorpay.xml\nLayout XML]
        SETUP[setuputils.phtml\nJS Init]
        PO[placeorder.phtml\nButton Intercept]
        OR[order.phtml\nPayment.save Override]
        EXT[Extension Templates\nFireCheckout/IWD/AppZab]
        RU[razorpay-utils.js\nRazorpayUtils Class]
    end

    subgraph Backend["Backend Layer"]
        CTRL[CheckoutController\nAJAX Handler]
        HELP[Helper/Data\nAPI Client]
        PAY[Paymentmethod\nMagento Model]
        CONF[config.xml + system.xml\nConfiguration]
    end

    subgraph External["External Services"]
        RZP_CDN[checkout.razorpay.com\nCheckout.js CDN]
        RZP_API[api.razorpay.com\nRazorpay REST API]
    end

    LAY --> SETUP
    LAY --> PO
    LAY --> OR
    LAY --> EXT
    SETUP --> RU
    PO --> RU
    OR --> RU
    EXT --> RU
    RU --> CTRL
    RU --> RZP_CDN
    CTRL --> HELP
    HELP --> RZP_API
    PAY --> HELP
    PAY --> CONF
    HELP --> CONF
```

---

## 4. Deployment Architecture

```mermaid
graph TB
    subgraph MagentoStore["Magento Store (Web Server)"]
        direction LR
        PHP[PHP Application\nMagento 1.x]
        STATIC[Static Assets\n/js/razorpay/]
        DB[(MySQL\nMagento DB)]
        PHP --> DB
    end

    subgraph CDN["Razorpay CDN"]
        CHECKOUT_JS[checkout.js\nPayment Modal]
    end

    subgraph RazorpayAPI["Razorpay API Cluster"]
        API[REST API v1\nBasic Auth]
        ORDERS[Orders Service]
        PAYMENTS[Payments Service]
        API --> ORDERS
        API --> PAYMENTS
    end

    Browser -->|HTTPS| MagentoStore
    Browser -->|HTTPS| CDN
    PHP -->|HTTPS + Basic Auth| RazorpayAPI
    PHP -->|serve| STATIC
```

---

## 5. Configuration Flow

```mermaid
flowchart LR
    Admin[Magento Admin\nSystem > Config > Payment > Razorpay]
    DB[(Magento\ncore_config_data table)]
    PHP[PHP Code\nMage::getStoreConfig]
    JS[JavaScript\nRazorpayUtils init]

    Admin -->|Save| DB
    DB -->|Read| PHP
    PHP -->|Render in template| JS
    JS -->|Pass to Razorpay modal| Modal[Razorpay\nCheckout Modal]
```

---

## 6. Checkout Extension Compatibility Architecture

```mermaid
graph TB
    LAYOUT[razorpay.xml\nLayout System]

    subgraph Native["Native Checkout"]
        NL[checkout_onepage_*\nLayout Handles]
        NT1[order.phtml\nPayment.save Override]
        NT2[placeorder.phtml\nButton Intercept]
    end

    subgraph FireCheckout["FireCheckout Extension"]
        FL[firecheckout_index_index\nLayout Handle]
        FT[extensions/firecheckout/\npayments.phtml]
    end

    subgraph IWDOPC["IWD OPC Extension"]
        IL[opc_index_index\nLayout Handle]
        IT[extensions/iwd_opc/\npayments.phtml]
    end

    subgraph AppZab["AppZab OSC Extension"]
        AL[onestepcheckout_index_index\nLayout Handle]
        AT[extensions/appzab_osc/\npayments.phtml]
    end

    subgraph Common["Shared Infrastructure"]
        SU[setuputils.phtml\nRazorpayUtils init]
        RU[razorpay-utils.js\nCore JS Library]
    end

    LAYOUT --> Native
    LAYOUT --> FireCheckout
    LAYOUT --> IWDOPC
    LAYOUT --> AppZab
    LAYOUT --> Common

    Native --> RU
    FireCheckout --> RU
    IWDOPC --> RU
    AppZab --> RU
```

---

## 7. Security Architecture

```mermaid
graph TB
    subgraph ClientSide["Client Side (Browser)"]
        KEY_ID[key_id\nPublic Key Only]
        PAYMENT_MODAL[Razorpay Modal\nHosted by Razorpay]
    end

    subgraph ServerSide["Server Side (Magento)"]
        KEY_SECRET[key_secret\nNEVER sent to browser]
        BASIC_AUTH[HTTP Basic Auth\nkey_id:key_secret]
        CAPTURE[Payment Capture\nServer-to-Server]
    end

    subgraph RazorpayPlatform["Razorpay Platform"]
        API_ENDPOINT[API Endpoints\nTLS 1.2+]
    end

    KEY_ID --> PAYMENT_MODAL
    KEY_SECRET --> BASIC_AUTH
    BASIC_AUTH --> API_ENDPOINT
    CAPTURE --> API_ENDPOINT

    style KEY_SECRET fill:#ffcccc
    style BASIC_AUTH fill:#ffcccc
```

**Security invariants:**
- `key_secret` is only in Magento's `core_config_data` table (encrypted at rest in production)
- Only `key_id` is exposed to browser JavaScript
- Payment capture is always server-side (PHP → Razorpay API)
- The `razorpay_payment_id` from browser is validated by Razorpay during server-side capture

---

## 8. Data Flow — Amount Handling

```mermaid
flowchart LR
    QUOTE[Magento Quote\ngetBaseGrandTotal] -->|× 100| PAISE[Amount in Paise\nINR cents]
    PAISE --> CREATE_ORDER[POST /v1/orders\namount: paise]
    PAISE --> CAPTURE[POST /payments/:id/capture\namount: paise]

    QUOTE2[getGrandTotal\nQuote Currency] -->|display only| MODAL[Razorpay Modal\ndisplay_amount]

    note1[Example: ₹100.00 → 10000 paise]
```

---

## 9. Module Registration Flow

```mermaid
sequenceDiagram
    participant Magento
    participant ModulesXML as app/etc/modules/Razorpay_Payments.xml
    participant ConfigXML as etc/config.xml
    participant SystemXML as etc/system.xml

    Magento->>ModulesXML: Load module declarations
    ModulesXML-->>Magento: Module enabled, codePool=community
    Magento->>ConfigXML: Load module config
    ConfigXML-->>Magento: Register blocks, models, helpers, routes, layouts
    Magento->>SystemXML: Load admin config schema
    SystemXML-->>Magento: Register payment/razorpay/* config fields
```
