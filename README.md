## Razorpay Payment Extension for Magento

This extension utilizes Razorpay API and provides seamless integration with Magento, allowing payments for Indian merchants via Credit Cards, Debit Cards, Net Banking, Wallets and EMI without redirecting away from the magento site.

### Installation

## **Install through the "code.zip" file**
### `bin/magento` is executable command, this is to be executed from Magento installation directory.

1. Extract the attached code.zip
2. Go to the "app" folder
3. Overwrite content of the "code" folder with step one "code" folder (Note: if the code folder does not exist just place the code folder from step 1).
4. Run following command to enable Razorpay Magento module: 
```
bin/magento module:enable Razorpay_Magento
```
5. Run following command to install Magento cron jobs : 
```
bin/magento cron:install
```
6. Run `bin/magento setup:di:compile` to compile dependency code. 
7. Run `bin/magento setup:upgrade` to upgrade the Razorpay Magento module from the Magento installation folder.
8. On the Magento admin dashboard, open Razorpay payment method settings and click on the Save Config button.
**Note**: If you see this message highlighted in yellow (One or more of the Cache Types are invalidated: Page Cache. Please go to Cache Management and refresh cache types.) on top of the Admin page, please follow the steps mentioned and refresh the cache.
9. Run `bin/magento cache:flush` once again.

### **OR**

Install the extension through composer package manager.

```
composer require razorpay/magento
bin/magento module:enable Razorpay_Magento
```


You can check if the module has been installed using `bin/magento module:status`

You should be able to see `Razorpay_Magento` in the module list

#### Execute following commands from Magento installation directory:
```
bin/magento setup:di:compile
bin/magento setup:upgrade
bin/magento cache:flush
```

Go to `Admin -> Stores -> Configuration -> Payment Method -> Razorpay` to configure Razorpay


If you do not see Razorpay in your gateway list, please clear your Magento Cache from your admin
panel (System -> Cache Management).

### Setting up the cron with Magento
Setup cron with Magento to execute Razorpay cronjobs for following actions:

#### Cancel pending orders
It will cancel order created by Razorpay as per timeout saved in configuration if Cancel Pending Order is enabled.

#### Update order to processing
Accepts response from Razorpay Webhook for events `payment.authorized` and `order.paid` and updates pending order to processing.

#### Magento cron can be installed using following command:
```
bin/magento cron:install
```

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

3. Create Razorpay Order ID against the Magento Order ID and the checkout page URL as referrer.
```
mutation {
  placeRazorpayOrder (
      order_id: "{{order_ID}}"
      referrer: "{{referrer}}"
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
6.  Pass the Magento Order ID to reset the cart.
```
mutation {
  resetCart (
      order_id: "{{order_ID}}"
  ){
    success
  }
}
```
### Support

Visit [https://razorpay.com](https://razorpay.com) for support requests or email contact@razorpay.com.

## **Upgrade Razorpay plugin through composer**
If you are an existing user, you can upgrade the Magento extension using the composer. Enter the command given below:
```
composer update razorpay/magento
bin/magento setup:upgrade
```

## Using  Custom Order Status in Razorpay Magento
### Step 1: Create Custom Order Status
- On the Magento Admin Dashboard, Stores > Settings > Order Status.
- In the upper-right corner, click on Create New Status.
- Under Order Status Information section, Insert a Status Code for the internal reference. This field required to contain letters (a-z), the number (0-9) and the underscore, it is required to use letters at first character and the rest can be a combination of letters and numbers.
- Set the Status Label for Admin and storefront.
- Set the Store View specific labels for each store view on your store.
- Save status to complete.

### Step 2: Un-assign existing status
- Un-assign existing status code which is in use. 
   - If State Code and Title is `processing[Processing]`, then `processing` status is already in use for state `processing`.
   - Un-assign this status from existing state code `processing`, so that state will be available for your custom status code.

### Step 3: Assign an order status to a state
- Go to the Order Status page, click on Assign Status to State button.
- In the Assign Order Status to State section,
   - From the existing list of the order status, select the Order Status to assign.
   - Choose the Order State to include the order status you’ve just assigned. **Use Order state as `processing`**
    - Accept the order status as a default status, tick the Use Order Status as Default checkbox.
    - Enable the order status on the storefront, please tick the Visible On Storefront checkbox.
 - Click on ` Save Status Assignment` to complete.

### Step 4: Using Custom order status for Razorpay Magento
- On the Magento admin dashboard, open Razorpay payment method settings.
- At field `Custom Paid Order Status` select `Yes` to enable custom order status, select `No` to disable custom order status.
-  Insert Custom Paid Order Status value at input field provided with same value which has been used as Status Code while creating custom status.
- Save configuration & refresh the cache.

## Uninstall OR Rollback to older versions
To rollback, you will be required to uninstall existing version and install a new version again. Following are actions used for rollback & reinstall:

### Uninstall Razorpay Magento
**If composer is used for installation, use following commands from Magento installation directory to uninstall Razorpay Magento module** 
```
php bin/magento module:disable Razorpay_Magento
php bin/magento module:uninstall Razorpay_Magento
```

**If code.zip is used for installation, to uninstall following steps can be used:**
Disabled Razorpay Magento module
```
php bin/magento module:disable Razorpay_Magento
```

To remove module directory, execute following command from Magento install directory
```
rm -rf app/code/Razorapy
```

Remove module schema from MYSQL database
```
DELETE FROM `setup_module` WHERE `setup_module`.`module` = 'Razorpay_Magento';
```

### Re-Install Razorpay Magento
To install Razorpay Magento module, follow installation steps provided at this release document. Following are previously released versions [3.7.5](https://github.com/razorpay/razorpay-magento/releases/tag/3.7.5) and [4.0.4](https://github.com/razorpay/razorpay-magento/releases/tag/4.0.5).

### FAQ

Question: How to upgrade plugin using code.zip?
Answer: Install the latest code.zip and replace all the contents of the code folder with the new code.zip content. Then follow the same steps which are there for installation through code.zip.
