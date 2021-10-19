## Razorpay Payment Extension for Magento

This extension utilizes Razorpay API and provides seamless integration with Magento, allowing payments for Indian merchants via Credit Cards, Debit Cards, Net Banking, Wallets and EMI without redirecting away from the magento site.

### Installation

Install the extension through composer package manager.

```
composer require razorpay/magento
bin/magento module:enable Razorpay_Magento
```

### Install through "code.zip" file

Extract the attached code.zip from release

Go to "app" folder

Overwrite content of "code" folder with step one "code" folder (Note: if code folder not exist just place the code folder from step-1).

Run from magento root folder.

```
bin/magento module:enable Razorpay_Magento
bin/magento setup:upgrade
```

You can check if the module has been installed using `bin/magento module:status`

You should be able to see `Razorpay_Magento` in the module list


Go to `Admin -> Stores -> Configuration -> Payment Method -> Razorpay` to configure Razorpay


If you do not see Razorpay in your gateway list, please clear your Magento Cache from your admin
panel (System -> Cache Management).

### Note: Don't mix composer and zip install.

### Note: Make sure "zipcode" must be required field for billing and shipping address.**

### Setting up the cron to process missing orders

Razopray webhook cron is added under "razorpay" group, and can be run manually like below:

```
bin/magento cron:run --group="razorpay"
```
### Working with GraphQL 

Razorpay GraphQL Support added with version 3.6.0 

Order flow for placing Magento Order using Razorpay as payment method with GraphQl

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

2. Create Razorpay Order ID against the cart 
```
mutation {
  placeRazorpayOrder (
    cart_id: "{{cartid}}"
  ){
    success
    rzp_order_id
    order_quote_id
    amount
    currency
    message
  }
}
```

3. Use `rzp_order_id` and other details from step-2 and create from the Frontend/React/using razorpay's checkout.js , complete the payment and obtain razorpay_payment_id & razorpay_signature
  https://razorpay.com/docs/payment-gateway/web-integration/standard/

4. Save Razorpay Response Details against Cart after payment success with RZP paymentId , orderId and signature 
```
mutation {
  setRzpPaymentDetailsOnCart (
    input: {
      cart_id: "{{cart_ID}}"
      rzp_payment_id: "{{RAZORPAY_PAYMENT_ID}}"
      rzp_order_id: "{{RAZORPAY_ORDER_ID}}"
      rzp_signature: "{{RAZORPAY_SIGNATURE}}"
    }
  ){
  cart{
    id
  }
  }
}
```
5. Finally Place Magento Order 
```
mutation {
  placeOrder(input: {cart_id: "{{cart_ID}}"}) {
    order {
      order_number
    }
  }
}
```

### Support

Visit [https://razorpay.com](https://razorpay.com) for support requests or email contact@razorpay.com.

### DISCLAIMER

In no event shall Razorpay.com/Razorpay be liable for any claim, damages or other liability, whether in an action of contract, tort or otherwise, arising from the information or code provided or the use of the information or code provided. This disclaimer of liability refers to any technical issue or damage caused by the use or non-use of the information or code provided or by the use of incorrect or incomplete information or code provided.
