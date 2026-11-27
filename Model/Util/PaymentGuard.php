<?php

namespace Razorpay\Magento\Model\Util;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * One check that a Razorpay payment belongs to the order it is about to mark
 * paid, and that the Razorpay order covers the Magento total.
 *
 * The payment amount is deliberately not compared with the order total:
 * offers, fees and partial captures legitimately change it. The Razorpay
 * order amount is the figure that must match the Magento grand total.
 */
class PaymentGuard
{
    const STATUS_AUTHORIZED = 'authorized';
    const STATUS_CAPTURED   = 'captured';

    /**
     * @param OrderInterface $order
     * @param string|null $rzpOrderId Razorpay order id from the order's link row
     * @param array|\ArrayAccess $rzpPayment Razorpay payment entity
     * @param array|\ArrayAccess $rzpOrder Razorpay order entity
     * @return void
     * @throws LocalizedException
     */
    public function assertMatches($order, $rzpOrderId, $rzpPayment, $rzpOrder): void
    {
        $rzpOrderId = (string) $rzpOrderId;

        if ($rzpOrderId === '') {
            throw new LocalizedException(__('This order has no Razorpay order linked to it.'));
        }

        $paymentOrderId = isset($rzpPayment['order_id']) ? (string) $rzpPayment['order_id'] : '';

        if ($paymentOrderId !== $rzpOrderId) {
            throw new LocalizedException(__('This payment does not belong to this order.'));
        }

        $status = isset($rzpPayment['status']) ? strtolower((string) $rzpPayment['status']) : '';

        if (in_array($status, [self::STATUS_AUTHORIZED, self::STATUS_CAPTURED], true) === false) {
            throw new LocalizedException(__('This payment is not authorized or captured.'));
        }

        $amountRefunded = isset($rzpPayment['amount_refunded']) ? (int) $rzpPayment['amount_refunded'] : 0;

        if ($amountRefunded !== 0) {
            throw new LocalizedException(__('This payment has already been refunded.'));
        }

        $orderCurrency    = strtoupper((string) $order->getOrderCurrencyCode());
        $rzpCurrency      = isset($rzpOrder['currency']) ? strtoupper((string) $rzpOrder['currency']) : '';
        $rzpOrderAmount   = isset($rzpOrder['amount']) ? (int) $rzpOrder['amount'] : 0;
        $expectedAmount   = (int) round(((float) $order->getGrandTotal()) * 100);

        if ($rzpCurrency !== '' && $rzpCurrency !== $orderCurrency) {
            throw new LocalizedException(__('The payment currency does not match the order currency.'));
        }

        if ($rzpOrderAmount !== $expectedAmount) {
            throw new LocalizedException(
                __('The Razorpay order amount does not match the order total.')
            );
        }
    }
}
