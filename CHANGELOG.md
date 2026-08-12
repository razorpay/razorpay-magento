
# Change Log


## [4.2.4] - 2026-08-12

### Fixed
- [Fixed order creation failing with "sku: the length must be no more than 128" for products with long SKUs, by truncating `sku` to 128 characters before sending to Razorpay.](https://github.com/razorpay/razorpay-magento/pull/549)

## [4.2.3] - 2026-08-06

### Added
- [Added PHP 8.5 support.](https://github.com/razorpay/razorpay-magento/pull/547)

### Changed
- Added a stale PR cleanup workflow via centralized pr-manager.

## [4.2.2] - 2026-05-28

### Added
- [Added device fingerprinting and Shield data-parameter sharing for fraud detection (Magento).](https://github.com/razorpay/razorpay-magento/pull/536)

### Fixed
- Fixed handling of `X-Forwarded-For` header to correctly take only the first (client) IP when multiple proxy IPs are present.

## [4.2.1] - 2026-01-16
- Fixed custom order status update

## [4.2.0] - 2025-12-20
- [Removed unused `genesis.yml` workflow file.](https://github.com/razorpay/razorpay-magento/pull/531)
- Enhance webhook handling by adding filter rzp_order_id

## [4.1.9] - 2025-07-16
- Added Magento plugin instrumentation.

## [4.1.8] - 2025-02-12
- [Implemented `CsrfAwareActionInterface` for the Webhook controller.](https://github.com/razorpay/razorpay-magento/pull/516)

## [4.1.7] - 2024-12-16
- [Added a setting for the age of orders to be picked up by the `CancelPendingOrder` cron.](https://github.com/razorpay/razorpay-magento/pull/507)
- [Fixed a bug in the Magento callback flow.](https://github.com/razorpay/razorpay-magento/pull/510)
- [Updated README.](https://github.com/razorpay/razorpay-magento/pull/496)

## [4.1.6] - 2024-06-06
- [Fixed a bug in the table setup notification.](https://github.com/razorpay/razorpay-magento/pull/494)
- [Fixed a bug in fetching order webhook data.](https://github.com/razorpay/razorpay-magento/pull/489)
- Removed the semgrep workflow.

## [4.1.5] - 2024-04-30
- [Fixed a bug in the cancel-pending-orders cron.](https://github.com/razorpay/razorpay-magento/pull/484)

## [4.1.4] - 2024-04-02
- Fixed, database upgrade from version 3.x.
- Fixed, FPX orders not getting updated.

## [4.1.3] - 2024-02-15
- Fixed, new Razorpay order creation bug.
- Added debug mode for additional logging.
- Added notification message for incorrect Razorpay table setup.
- Fixed, paid orders cancelled by cron bug.

## [4.1.2] - 2023-11-30
- [Added order notes.](https://github.com/razorpay/razorpay-magento/pull/465)

## [4.1.1] - 2023-11-16
- [Blocked unsupported currencies: KWD, OMR, BHD.](https://github.com/razorpay/razorpay-magento/pull/461)
- [Added an FAQ section to the README.](https://github.com/razorpay/razorpay-magento/pull/460)

## [4.1.0] - 2023-09-28
- [Reduced DB latency in order/webhook processing.](https://github.com/razorpay/razorpay-magento/pull/455)
- Added a delay before processing `order.paid` webhooks to reduce race conditions with the normal checkout flow.

## [4.0.5] - 2023-07-25
- Logger fix for refund online.
- Added referer url in hosted checkout options.
- Fixed usage of increment_id to entity_id in Reset Cart graphql.

## [4.0.4] - 2023-05-15
- Added a GraphQL mutation and resolver for Reset Cart.
- Added `referrer` URL support to both the normal and GraphQL order-creation flows.

## [4.0.3-beta] - 2023-05-09
- Issues resolved: Order email fix for Webhook Controller, Update Order Cronjob and GraphQL SetRzpPaymentDetailsForOrder.
- Cancel Reset Cart Order Cron: Orders which are reseted by closing payment popup window at checkout page have new state and canceled status, this cron will move these orders to canceled state to release inventory.

## [4.0.3] - 2023-02-27
- Order email fix for Webhook Controller, Update Order Cronjob and GraphQL SetRzpPaymentDetailsForOrder.
- Cancel Reset Cart Order Cron: Orders which are reseted by closing payment popup window at checkout page have new state and canceled status, this cron will move these orders to canceled state to release inventory.

## [4.0.2] - 2022-12-12
- Removed SecureHtmlRenderer to support Magento 2.3.x
- Fixed path for Razorpay SDK directory at class TrackPluginInstrumentation

## [4.0.1] - 2022-11-11
- [Restricted supported PHP versions to 7.* and up to 8.1.](https://github.com/razorpay/razorpay-magento/pull/387)
- Added an easy signup link to the plugin settings page.
- Added plugin usage/segmentation instrumentation (datalake events on refund, plugin upgrade, and config save), while excluding `key_id`/`key_secret` from event data.
- Updated the Lumberjack logging URL and added hostname whitelisting.

## [4.0.0] - 2022-09-19
**This module can be used to place order before payment in Magento, in new state and pending status.**

### Changed
- [Now a notification will be displayed whenever a new version update is released.](https://github.com/razorpay/razorpay-magento/pull/380)
- [Instructions have been updated to set up cron with Magento.](https://github.com/razorpay/razorpay-magento/pull/376)

### Fixed
- [Order status mismatch on Magento and Razorpay dashboard is resolved. Correct order status will reflect on both the dashboards.](https://github.com/razorpay/razorpay-magento/pull/222)
- [Webhook secret will not be removed from core config data on version upgrade. Configuration of webhook will remain same.](https://github.com/razorpay/razorpay-magento/pull/377)

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
