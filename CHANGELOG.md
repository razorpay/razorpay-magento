
# Change Log


## [3.7.3] - 2021-01-07

### Changed
- [Added, support for subscription webhook events](https://github.com/razorpay/razorpay-magento/pull/300).
### Fixed
- [Fixed issue, Webhook gets disabled in case of mutistore site](https://github.com/razorpay/razorpay-magento/pull/301).
- [Fixed issue, Verify the active quote, before placing order in callback](https://github.com/razorpay/razorpay-magento/pull/299).

## [3.7.2] - 2021-12-06

### Changed
### Fixed
- [Fixed issue, related with new RZP order ID generation for same quote and cart amount](https://github.com/razorpay/razorpay-magento/pull/292).
- [Added, Change order status to `Payment Review` in case of amount mismatch (By using setting configs)](https://github.com/razorpay/razorpay-magento/pull/296).

## [3.7.1] - 2021-10-20

### Changed
### Fixed
- [Fixed issue, related with quote ID missing in the callback url](https://github.com/razorpay/razorpay-magento/pull/288).
- [Added webhook order cron under `razorpay` group](https://github.com/razorpay/razorpay-magento/pull/289).

## [3.7.0] - 2021-10-07

### Changed
### Fixed
- [Added Cron, to create missing webhook orders](https://github.com/razorpay/razorpay-magento/pull/284).
- [Added `payment.authorize` webhook event](https://github.com/razorpay/razorpay-magento/pull/284).

## [3.6.4] - 2021-09-20

### Changed
### Fixed
- [Fixed to validate order amount in webhook)](https://github.com/razorpay/razorpay-magento/pull/275).
- [Fixed paymentId validation](https://github.com/razorpay/razorpay-magento/pull/276).
- [Added refund through invoice credit memo](https://github.com/razorpay/razorpay-magento/pull/272).

## [3.6.3] - 2021-07-12

### Changed
### Fixed
- [Fixed to avoid api calls in observer (In case order gets updated again by other modules)](https://github.com/razorpay/razorpay-magento/pull/269).
- [Added latest release upgrade notification](https://github.com/razorpay/razorpay-magento/pull/264).
- [Additional Config support for magento GraphQL](https://github.com/razorpay/razorpay-magento/pull/268).

## [3.6.2] - 2021-06-23

### Changed
### Fixed
- [Fixed webhook localhost url validation](https://github.com/razorpay/razorpay-magento/pull/257).

 Fixed webhook localhost url validation.

## [3.6.1] - 2021-06-17
 
### Changed
### Fixed
- [Signature issue, billing address validation](https://github.com/razorpay/razorpay-magento/pull/254).

 Fixed webhook signature mismatch issue and added validation for shipping/billing address and shipping method for quote, before creating RZP order.

## [3.6.0] - 2021-06-11
  
### Added

- [GraphQL Support](https://github.com/razorpay/razorpay-magento/pull/240).

 Razorpay GraphQL Support added in this release. Please follow the readme file for instructions of uses. 
 
### Changed   
### Fixed

## [3.5.3] - 2021-06-03 

### Added

- [Actual amount paid in order comments section ( In case of Offer/Fee applied on RZP dashboard.)](https://github.com/razorpay/razorpay-magento/pull/249).
 
### Changed   

Added label for front-end to fix radio button selection issue.

### Fixed

- [Webhook signature mismatch](https://github.com/razorpay/razorpay-magento/pull/251).