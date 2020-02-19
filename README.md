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

### Support

Visit [https://razorpay.com](https://razorpay.com) for support requests or email contact@razorpay.com.
