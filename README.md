## Razorpay Payment Extension for Magento

This extension utilizes Razorpay API and provides seamless integration with Magento, allowing payments for Indian merchants via Credit Cards, Debit Cards, Net Banking, Wallets and EMI without redirecting away from the magento site.

### Installation

Install the extension through composer package manager.

```
composer require razorpay/magento
bin/magento module:enable Razorpay_Magento
```


You can check if the module has been installed using `bin/magento module:status`

You should be able to see `Razorpay_Magento` in the module list


Go to `Admin -> Stores -> Configuration -> Payment Method -> Razorpay` to configure Razorpay


If you do not see Razorpay in your gateway list, please clear your Magento Cache from your admin
panel (System -> Cache Management).

### Working with GraphQL

Razorpay GraphQL Support added with Magento ver. 2.3.6

Order flow for placing Magento Order using Razorpay as payment method with GraphQL

1. set Payment Method on Cart
```
mutation {
  setPaymentMethodOnCart(input: {
      cart_id: "{{cart_ID}}"
      payment_method: {
          code: "razorpay"
      }
  }) {
    cart {
      selected_payment_method {
        code
      }
    }
  }
}
```
2. Place Magento Order
```
mutation {
  placeOrder(input: {cart_id: "{{cart_ID}}"}) {
    order {
      order_number
    }
  }
}
```

3. Create Razorpay Order ID against the Magento Order ID
```
mutation {
  placeRazorpayOrder (
      order_id: "{{order_ID}}"
  ){
    success
    rzp_order_id
    order_id
    amount
    currency
    message
  }
}
```

4. Use Razorpay Order ID `rzp_order_id` and other details from step-3 and create frontend form using razorpay's checkout.js , complete the payment and obtain razorpay_payment_id & razorpay_signature
  https://razorpay.com/docs/payment-gateway/web-integration/standard/

5. Save Razorpay Response Details against Cart after payment success with Magento orderID, RZP paymentId , orderId and signature
```
mutation {
  setRzpPaymentDetailsForOrder (
    input: {
      order_id: "{{order_ID}}"
      rzp_payment_id: "{{RAZORPAY_PAYMENT_ID}}"
      rzp_signature: "{{RAZORPAY_SIGNATURE}}"
    }
  ){
  order{
    order_id
  }
  }
}
```

### Support

Visit [https://razorpay.com](https://razorpay.com) for support requests or email contact@razorpay.com.
