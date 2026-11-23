<?php

namespace Razorpay\Magento\Model;

use Razorpay\Magento\Constants\OrderCronStatus;
use Razorpay\Magento\Model\Util\CartFingerprint;

/**
 * PLUG-02: stops a Magic Checkout order from being placed or marked paid when its items differ from the
 * cart the Razorpay order was created for.
 */
class CartGuard
{
    const MISMATCH_MESSAGE = 'We could not complete your order because the items in your cart changed after payment was started. Any amount paid will be returned.';

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    public function __construct(
        Config $config,
        \Psr\Log\LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->logger = $logger;
    }

    public function isEnabled()
    {
        return $this->config->isMagicCartGuardEnabled();
    }

    /**
     * True when the cart about to be placed differs from the cart the Razorpay order was created for.
     */
    public function quoteMismatch($rzpOrderData, $quote)
    {
        return $this->appliesTo($rzpOrderData)
            && $this->isMismatch($rzpOrderData, $quote->getAllVisibleItems(), 'qty');
    }

    /**
     * True when the order about to be marked paid differs from the cart the Razorpay order was created for.
     */
    public function orderMismatch($rzpOrderData, $order)
    {
        return $this->appliesTo($rzpOrderData)
            && $this->isMismatch($rzpOrderData, $order->getAllVisibleItems(), 'qty_ordered');
    }

    /**
     * A rejected payment is never completed later. Not gated by the flag: this status only exists if the
     * guard was on when the payment was rejected (and possibly refunded).
     */
    public function isRejected($orderLink)
    {
        return $orderLink !== null
            && $orderLink->getId()
            && (int)$orderLink->getRzpUpdateOrderCronStatus() === OrderCronStatus::ORDER_ITEM_MISMATCH_REJECTED;
    }

    /**
     * Rejects the payment: rejected status first, then one refund attempt, then cancels the existing
     * Magento order if there is one. Never throws.
     *
     * @param \Razorpay\Api\Api $rzp
     * @param \Razorpay\Magento\Model\OrderLink|null $orderLink
     * @param mixed $rzpOrderData Razorpay order entity
     * @param mixed $rzpPaymentData Razorpay payment entity or array
     * @param \Magento\Sales\Model\Order|null $order existing Magento order, if any
     * @param string $source call site, for the log
     */
    public function reject($rzp, $orderLink, $rzpOrderData, $rzpPaymentData, $order, $source)
    {
        $rzpOrderId = (string)CartFingerprint::field($rzpOrderData, 'id');
        $paymentId = (string)CartFingerprint::field($rzpPaymentData, 'id');

        $this->logger->critical('PLUG-02: cart items changed after payment started. source=' . $source
            . ' rzp_order_id=' . $rzpOrderId . ' rzp_payment_id=' . $paymentId);

        // 1. Rejected status before any money moves, so no path completes this payment later.
        $rejectionSaved = false;
        try {
            if ($orderLink !== null && $orderLink->getId()) {
                $orderLink->setRzpUpdateOrderCronStatus(OrderCronStatus::ORDER_ITEM_MISMATCH_REJECTED);
                if ($paymentId !== '') {
                    $orderLink->setRzpPaymentId($paymentId);
                }
                $orderLink->save();
                $rejectionSaved = true;
            }
        } catch (\Exception $e) {
            $this->logger->critical('PLUG-02: could not save the rejected status: ' . $e->getMessage());
        }

        // 2. Refund only once the rejection is saved; otherwise a refunded payment could still be completed.
        if ($rejectionSaved) {
            $paymentNote = $this->refundOnce($rzp, $rzpOrderData, $rzpPaymentData, $paymentId);
        } else {
            $paymentNote = 'Payment was not refunded automatically. Check it in the Razorpay Dashboard.';
            $this->logger->critical('PLUG-02: payment ' . $paymentId . ' not refunded because the rejection was not saved.');
        }

        // 3. An existing order (pre-placed by AbandonedQuote) is cancelled, never marked paid.
        if ($order !== null && $order->getId()) {
            $this->cancelOrder($order, $rzpOrderId, $paymentId, $paymentNote);
        }
    }

    protected function appliesTo($rzpOrderData)
    {
        return $this->isEnabled() && CartFingerprint::readNote($rzpOrderData, 'cart_mask_id') !== null;
    }

    protected function isMismatch($rzpOrderData, $items, $qtyField)
    {
        $verdict = CartFingerprint::compare($rzpOrderData, $items, $qtyField);

        if ($verdict === CartFingerprint::UNKNOWN) {
            $this->logger->info('PLUG-02: Razorpay order ' . CartFingerprint::field($rzpOrderData, 'id')
                . ' has no cart fingerprint, items not checked');
        }

        return $verdict === CartFingerprint::MISMATCH;
    }

    /**
     * Same shape as the out-of-stock refund in CompleteOrder: one attempt, failures are logged.
     *
     * @return string note for the admin comment
     */
    protected function refundOnce($rzp, $rzpOrderData, $rzpPaymentData, $paymentId)
    {
        $method = (string)CartFingerprint::field($rzpPaymentData, 'method');
        $status = (string)CartFingerprint::field($rzpPaymentData, 'status');

        if ($method === 'cod') {
            return 'Cash on delivery, nothing to refund.';
        }

        if ($status === 'refunded') {
            return 'Payment was already refunded.';
        }

        if ($status === 'failed') {
            return 'Payment failed, nothing to refund.';
        }

        if ($status !== 'captured') {
            $this->logger->critical('PLUG-02: payment ' . $paymentId . ' is ' . $status . ', not refunded.');

            return 'Payment status is "' . $status . '", so it was not refunded. Do not capture it; if it gets captured, refund it from the Razorpay Dashboard.';
        }

        try {
            $payment = $rzp->payment->fetch($paymentId);
            $remaining = (int)$payment['amount'] - (int)($payment['amount_refunded'] ?? 0);

            if ($remaining <= 0) {
                return 'Payment was already refunded.';
            }

            $receipt = (string)CartFingerprint::field($rzpOrderData, 'receipt');

            $payment->refund([
                'amount' => $remaining,
                'receipt' => $receipt,
                'notes' => [
                    'reason' => 'Cart items changed after payment was started',
                    'order_id' => $receipt,
                    'refund_from_magic' => true,
                    'source' => 'Magento',
                ],
            ]);

            return 'Payment refunded.';
        } catch (\Exception $e) {
            $this->logger->critical('PLUG-02: refund failed for payment ' . $paymentId . ': ' . $e->getMessage());

            return 'Automatic refund failed. Refund it from the Razorpay Dashboard.';
        }
    }

    protected function cancelOrder($order, $rzpOrderId, $paymentId, $paymentNote)
    {
        try {
            $cancelled = false;
            if ($order->canCancel()) {
                $order->cancel();
                $cancelled = true;
            }

            $order->addStatusHistoryComment(
                'Cart items changed after payment was started (Razorpay order ' . $rzpOrderId
                . ', payment ' . $paymentId . '). '
                . ($cancelled ? 'Order cancelled. ' : 'Order could not be cancelled automatically, please review it. ')
                . $paymentNote
            );
            $order->save();
        } catch (\Exception $e) {
            $this->logger->critical('PLUG-02: could not cancel order ' . $order->getIncrementId() . ': ' . $e->getMessage());
        }
    }
}
