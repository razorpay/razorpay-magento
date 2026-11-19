<?php

namespace Razorpay\Magento\Model;

use Magento\Sales\Model\OrderFactory;
use Razorpay\Magento\Constants\OrderCronStatus;
use Razorpay\Magento\Model\Util\CartFingerprint;

/**
 * PLUG-02: blocks Magic Checkout orders whose cart changed after the Razorpay order was created.
 *
 * Block order matters: the OrderLink is marked terminal FIRST, so a crash or failed refund can never
 * leave a row that a cron or callback would later complete as a normal order.
 */
class CartGuard
{
    const OUTCOME_REFUNDED = 'refunded';
    const OUTCOME_ALREADY_REFUNDED = 'already_refunded';
    // Authorize mode: nothing to refund, the uncaptured authorization lapses on its own.
    const OUTCOME_AUTH_LAPSE = 'authorization_will_lapse';
    // Capture mode but the payment is not captured yet: refund is retried once it is.
    const OUTCOME_REFUND_PENDING = 'refund_pending';
    const OUTCOME_NOT_REQUIRED = 'not_required';
    const OUTCOME_REFUND_FAILED = 'refund_failed';
    // Payment state could not be determined: nothing was changed, the row stays retryable.
    const OUTCOME_RETRY_LATER = 'retry_later';

    const EVENT_NAME = 'Order Item Mismatch Rejected';
    const EVENT_CRON_TERMINAL = 'Cron Row Terminal';

    const MISMATCH_MESSAGE = 'We could not complete your order because the items in your cart changed after payment was initiated. Any amount paid will be refunded automatically.';
    const GENERIC_REJECTION_MESSAGE = 'Payment could not be confirmed.';

    const MAX_CRON_ATTEMPTS = 5;
    const ATTEMPTS_KEY = '_plug02_cron_attempts';
    const REFUND_ATTEMPTS_KEY = '_plug02_refund_attempts';
    const MAX_REFUND_ATTEMPTS = 5;

    protected $rzp;
    protected $config;
    protected $orderFactory;
    protected $orderLinkFactory;
    protected $trackPluginInstrumentation;
    protected $logger;

    public function __construct(
        PaymentMethod $paymentMethod,
        Config $config,
        OrderFactory $orderFactory,
        OrderLinkFactory $orderLinkFactory,
        TrackPluginInstrumentation $trackPluginInstrumentation,
        \Psr\Log\LoggerInterface $logger
    ) {
        $this->rzp = $paymentMethod->setAndGetRzpApiInstance();
        $this->config = $config;
        $this->orderFactory = $orderFactory;
        $this->orderLinkFactory = $orderLinkFactory;
        $this->trackPluginInstrumentation = $trackPluginInstrumentation;
        $this->logger = $logger;
    }

    /**
     * Kill switch (payment/razorpay/magic_cart_guard). Off = every guard call below is a no-op.
     */
    public function isEnabled()
    {
        return $this->config->isMagicCartGuardEnabled();
    }

    /**
     * Identity / payment-status checks, skipped (null = pass) when the kill switch is off.
     */
    public function identityRejection($requestOrderId, $sessionOrderId, $paymentOrderId, $linkRzpOrderId = null)
    {
        return $this->isEnabled()
            ? CartFingerprint::identityRejection($requestOrderId, $sessionOrderId, $paymentOrderId, $linkRzpOrderId)
            : null;
    }

    public function paymentStatusRejection($paymentStatus)
    {
        return $this->isEnabled() ? CartFingerprint::paymentStatusRejection($paymentStatus) : null;
    }

    /**
     * match | mismatch | unknown | not_applicable
     */
    public function check($rzpOrderData, $items)
    {
        if (!$this->isEnabled()) {
            return CartFingerprint::VERDICT_NOT_APPLICABLE;
        }

        return CartFingerprint::compare($rzpOrderData, $items, $this->logger);
    }

    /**
     * Existing order (by merchant_order_id) wins over the quote. A FRESH order model is created per call:
     * a shared model keeps its cached items collection across loads and would compare the wrong items.
     *
     * @return array [verdict, existingOrder|null]
     */
    public function compareForRow($rzpOrderData, $quote, $merchantOrderId)
    {
        $existing = null;

        if (!$this->isEnabled()) {
            return [CartFingerprint::VERDICT_NOT_APPLICABLE, null];
        }

        if (empty($merchantOrderId) === false) {
            $candidate = $this->loadFreshByIncrementId($merchantOrderId);
            $existing = $candidate->getId() ? $candidate : null;
        }

        return [$this->check($rzpOrderData, CartFingerprint::pickComparableItems($existing, $quote)), $existing];
    }

    public function orderExists($incrementId)
    {
        return $this->loadFreshByIncrementId($incrementId)->getId() ? true : false;
    }

    /**
     * Always a new model (never the shared one injected into callers): cached item collections of a
     * previously loaded order must not leak into this row.
     */
    public function loadFreshByIncrementId($incrementId)
    {
        return $this->orderFactory->create()->loadByIncrementId($incrementId);
    }

    /**
     * Only a clear "not found" is permanent. Razorpay BadRequestError is chosen by error code and also covers
     * 401/429 and other transient cases, so the HTTP status decides.
     */
    public static function isPermanentNotFound($e)
    {
        return $e instanceof \Razorpay\Api\Errors\Error && (int)$e->getHttpStatusCode() === 404;
    }

    /**
     * Fresh model, so cached item collections of a previously loaded order cannot leak in.
     */
    public function loadFreshOrder($entityId)
    {
        return $this->orderFactory->create()->load($entityId);
    }

    public function findOrderLink($rzpOrderId)
    {
        if (empty($rzpOrderId)) {
            return null;
        }

        $link = $this->orderLinkFactory->create()
            ->getCollection()
            ->addFilter('rzp_order_id', $rzpOrderId)
            ->getFirstItem();

        return $link->getId() ? $link : null;
    }

    /**
     * True for the mismatch statuses (rejected, or rejected with a refund still pending).
     */
    public function isRejected($orderLink)
    {
        // Deliberately NOT gated by the kill switch: a payment that was already rejected (and refunded or
        // queued for refund) must never be completed later, whatever the switch says now.
        return $orderLink !== null && self::isRejectedStatus($orderLink->getRzpUpdateOrderCronStatus());
    }

    public static function isRejectedStatus($status)
    {
        $status = (int)$status;

        return $status === OrderCronStatus::ORDER_ITEM_MISMATCH_REJECTED
            || $status === OrderCronStatus::ORDER_ITEM_MISMATCH_REFUND_PENDING;
    }

    /**
     * @param mixed $rzpOrderData Razorpay order entity
     * @param mixed $rzpPaymentData Razorpay payment entity
     * @param string $source call site, for logs/events
     * @param \Magento\Sales\Model\Order|null $order existing Magento order, if any
     * @return string one of the OUTCOME_* constants
     */
    public function reject($rzpOrderData, $rzpPaymentData, $source, $order = null)
    {
        $rzpOrderId = (string)($rzpOrderData['id'] ?? '');
        $paymentId = (string)($rzpPaymentData['id'] ?? '');

        $this->logger->critical('PLUG-02: cart items changed after payment was initiated. source=' . $source
            . ' rzp_order_id=' . $rzpOrderId . ' rzp_payment_id=' . $paymentId);

        // 1. Terminal status first.
        $link = null;
        try {
            $link = $this->findOrderLink($rzpOrderId);
            if ($link !== null) {
                $link->setRzpUpdateOrderCronStatus(OrderCronStatus::ORDER_ITEM_MISMATCH_REJECTED);
                if ($paymentId !== '') {
                    $link->setRzpPaymentId($paymentId);
                }
                $link->save();
            }
        } catch (\Exception $e) {
            $this->logger->critical('PLUG-02: could not persist mismatch status: ' . $e->getMessage());
        }

        // 2. Money.
        $outcome = $this->settlePayment($rzpOrderData, $rzpPaymentData);

        // 3. Refund still owed (payment not captured yet, or the refund call failed): keep it retryable.
        if (($outcome === self::OUTCOME_REFUND_PENDING || $outcome === self::OUTCOME_REFUND_FAILED) && $link !== null) {
            try {
                $link->setRzpUpdateOrderCronStatus(OrderCronStatus::ORDER_ITEM_MISMATCH_REFUND_PENDING);
                $link->save();
            } catch (\Exception $e) {
                $this->logger->critical('PLUG-02: could not persist refund-pending status: ' . $e->getMessage());
            }
        }

        // 4. Existing Magento order: cancel (releases stock) + admin comment.
        $orderCancelled = false;
        if ($order !== null && $order->getId()) {
            $orderCancelled = $this->cancelOrder($order, $outcome, $rzpOrderId, $paymentId);
        }

        // 5. Durable alert/event (refund_failed and authorization_will_lapse need a human).
        $this->emit(self::EVENT_NAME, [
            'source' => $source,
            'rzp_order_id' => $rzpOrderId,
            'rzp_payment_id' => $paymentId,
            'outcome' => $outcome,
            'order_cancelled' => $orderCancelled,
        ]);

        return $outcome;
    }

    /**
     * Retry a refund left pending by reject(). Called from the order.paid webhook path and the cron.
     */
    public function retryPendingRefund($rzpOrderId)
    {
        // Not gated by the kill switch either: refunds already owed are always completed.
        $link = $this->findOrderLink($rzpOrderId);

        if ($link === null
            || (int)$link->getRzpUpdateOrderCronStatus() !== OrderCronStatus::ORDER_ITEM_MISMATCH_REFUND_PENDING
            || empty($link->getRzpPaymentId())) {
            return null;
        }

        $payment = $this->rzp->payment->fetch($link->getRzpPaymentId());
        $rzpOrderData = $this->rzp->order->fetch($rzpOrderId);

        $outcome = $this->settlePayment($rzpOrderData, $payment);

        if ($outcome === self::OUTCOME_REFUND_PENDING) {
            return $outcome;
        }

        if ($outcome === self::OUTCOME_REFUND_FAILED) {
            $attempts = $this->incrementCounter($link, self::REFUND_ATTEMPTS_KEY);

            if ($attempts < self::MAX_REFUND_ATTEMPTS) {
                return $outcome;
            }

            // Cap reached: stop retrying, leave a durable alert for a human.
            $this->logger->critical('PLUG-02: ALERT refund still failing after ' . $attempts . ' attempts for payment ' . ($payment['id'] ?? '') . '. Manual refund required.');
            $link->setRzpUpdateOrderCronStatus(OrderCronStatus::ORDER_ITEM_MISMATCH_REJECTED);
            $link->save();
            $this->emit(self::EVENT_NAME, [
                'source' => 'refund_retry_cap',
                'rzp_order_id' => (string)$rzpOrderId,
                'rzp_payment_id' => (string)($payment['id'] ?? ''),
                'outcome' => 'refund_failed_final',
            ]);

            return $outcome;
        }

        // Resolved (refunded / nothing owed): final.
        $link->setRzpUpdateOrderCronStatus(OrderCronStatus::ORDER_ITEM_MISMATCH_REJECTED);
        $link->save();

        $this->emit(self::EVENT_NAME, [
            'source' => 'refund_retry',
            'rzp_order_id' => (string)$rzpOrderId,
            'rzp_payment_id' => (string)($payment['id'] ?? ''),
            'outcome' => $outcome,
        ]);

        return $outcome;
    }

    /**
     * Cron rows pending a refund (bounded batch).
     */
    public function retryAllPendingRefunds($limit = 5)
    {
        $rows = $this->orderLinkFactory->create()
            ->getCollection()
            ->addFieldToFilter('rzp_update_order_cron_status', OrderCronStatus::ORDER_ITEM_MISMATCH_REFUND_PENDING)
            ->setOrder('entity_id')
            ->setPageSize($limit)
            ->getData();

        foreach ($rows as $row) {
            try {
                $this->retryPendingRefund($row['rzp_order_id']);
            } catch (\Exception $e) {
                $this->logger->critical('PLUG-02: refund retry failed for ' . ($row['rzp_order_id'] ?? '?') . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Cron attempt counter kept inside the existing rzp_webhook_data blob (no schema change).
     * Returns the new attempt count for the OrderLink row.
     */
    public function recordCronAttempt($entityId)
    {
        if (!$this->isEnabled()) {
            return 0;
        }

        $link = $this->orderLinkFactory->create()->load($entityId);

        if (!$link->getId()) {
            return self::MAX_CRON_ATTEMPTS;
        }

        return $this->incrementCounter($link, self::ATTEMPTS_KEY);
    }

    protected function incrementCounter($link, $key)
    {
        $data = [];
        $raw = $link->getRzpWebhookData();
        if (empty($raw) === false) {
            $decoded = unserialize($raw, ['allowed_classes' => false]); // nosemgrep
            $data = is_array($decoded) ? $decoded : [];
        }

        $data[$key] = (int)($data[$key] ?? 0) + 1;

        $link->setRzpWebhookData(serialize($data));
        $link->save();

        return $data[$key];
    }

    /**
     * Terminal outcome for a cron row. Never silent: a captured payment with no Magento order is refunded,
     * and a durable event is always emitted. Does not overwrite the mismatch statuses.
     *
     * @param array $orderData OrderLink row
     * @param mixed $rzpOrderData Razorpay order entity or null
     * @param mixed $rzpPaymentData Razorpay payment entity or null; pass null when the payment is not ours to refund
     */
    public function abandonRow($orderData, $reason, $rzpOrderData = null, $rzpPaymentData = null, $paymentIsNotOurs = false)
    {
        // Kill switch off: pre-PLUG-02 cron behaviour, no terminal statuses, no refunds.
        if (!$this->isEnabled()) {
            return self::OUTCOME_RETRY_LATER;
        }

        $outcome = self::OUTCOME_NOT_REQUIRED;

        // Never decide about money without knowing the payment state.
        if ($paymentIsNotOurs === false && $rzpPaymentData === null && empty($orderData['rzp_payment_id']) === false) {
            try {
                $rzpPaymentData = $this->rzp->payment->fetch($orderData['rzp_payment_id']);
            } catch (\Exception $e) {
                if (self::isPermanentNotFound($e) === false) {
                    $this->logger->critical('PLUG-02: cron row ' . ($orderData['entity_id'] ?? '?')
                        . ' left retryable, payment state unknown: ' . $e->getMessage());

                    return self::OUTCOME_RETRY_LATER;
                }
                // payment really does not exist: nothing to refund
            }
        }

        $merchantOrderId = $rzpOrderData !== null ? CartFingerprint::readNote($rzpOrderData, 'merchant_order_id') : null;

        if ($paymentIsNotOurs === false && $rzpPaymentData !== null && $rzpOrderData !== null && $this->orderExists($merchantOrderId) === false) {
            // Money taken, no order will ever be placed for it: refund.
            $outcome = $this->settlePayment($rzpOrderData, $rzpPaymentData);
        }

        $link = $this->orderLinkFactory->create()->load($orderData['entity_id']);
        if ($link->getId() && self::isRejectedStatus($link->getRzpUpdateOrderCronStatus()) === false) {
            $refundOwed = $outcome === self::OUTCOME_REFUND_PENDING || $outcome === self::OUTCOME_REFUND_FAILED;

            $link->setRzpUpdateOrderCronStatus(
                $refundOwed ? OrderCronStatus::ORDER_ITEM_MISMATCH_REFUND_PENDING : OrderCronStatus::CRON_UNRECOVERABLE_SKIPPED
            );
            if ($refundOwed && empty($link->getRzpPaymentId()) && empty($orderData['rzp_payment_id']) === false) {
                $link->setRzpPaymentId($orderData['rzp_payment_id']);
            }
            $link->save();
        }

        $this->logger->critical('PLUG-02: cron row ' . ($orderData['entity_id'] ?? '?') . ' abandoned: ' . $reason . ' outcome=' . $outcome);

        $this->emit(self::EVENT_CRON_TERMINAL, [
            'reason' => $reason,
            'rzp_order_id' => (string)($orderData['rzp_order_id'] ?? ''),
            'rzp_payment_id' => (string)($orderData['rzp_payment_id'] ?? ''),
            'outcome' => $outcome,
        ]);

        return $outcome;
    }

    public function isAuthorizeMode()
    {
        return $this->config->getPaymentAction() === 'authorize';
    }

    /**
     * Decides what happens to the money. Branches on capture MODE for uncaptured payments:
     * - captured (any mode): refund the remaining amount
     * - authorize mode, uncaptured: nothing refundable, authorization lapses
     * - capture mode, uncaptured (authorized/created): refund is owed once it captures -> pending
     */
    public function settlePayment($rzpOrderData, $rzpPaymentData)
    {
        $status = $rzpPaymentData['status'] ?? '';
        $method = $rzpPaymentData['method'] ?? '';

        if ($status === 'refunded') {
            return self::OUTCOME_ALREADY_REFUNDED;
        }

        if ($status === 'failed' || $method === 'cod') {
            return self::OUTCOME_NOT_REQUIRED;
        }

        if ($status !== 'captured') {
            return $this->isAuthorizeMode() ? self::OUTCOME_AUTH_LAPSE : self::OUTCOME_REFUND_PENDING;
        }

        $paymentId = $rzpPaymentData['id'];

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                // Re-read on every attempt: a partial/concurrent refund changes what is left.
                $fresh = $this->rzp->payment->fetch($paymentId);
                $remaining = (int)$fresh['amount'] - (int)($fresh['amount_refunded'] ?? 0);

                if (($fresh['status'] ?? '') === 'refunded' || $remaining <= 0) {
                    return self::OUTCOME_ALREADY_REFUNDED;
                }

                $fresh->refund([
                    'amount' => $remaining,
                    'receipt' => $rzpOrderData['receipt'] ?? '',
                    'notes' => [
                        'reason' => 'Cart items changed after payment was initiated',
                        'order_id' => $rzpOrderData['receipt'] ?? '',
                        'refund_from_magic' => true,
                        'source' => 'Magento',
                    ],
                ]);

                return self::OUTCOME_REFUNDED;
            } catch (\Exception $e) {
                $this->logger->critical('PLUG-02: refund attempt ' . $attempt . ' failed for ' . $paymentId . ': ' . $e->getMessage());
            }
        }

        $this->logger->critical('PLUG-02: ALERT refund FAILED for payment ' . $paymentId . '. Manual refund required.');

        return self::OUTCOME_REFUND_FAILED;
    }

    protected function emit($event, array $properties)
    {
        try {
            $this->trackPluginInstrumentation->rzpTrackDataLake($event, $properties);
        } catch (\Exception $e) {
            $this->logger->critical('PLUG-02: could not emit event ' . $event . ': ' . $e->getMessage());
        }
    }

    protected function cancelOrder($order, $outcome, $rzpOrderId, $paymentId)
    {
        $cancelled = false;

        try {
            $alreadyCancelled = $order->isCanceled();

            if (!$alreadyCancelled && $order->canCancel()) {
                $order->cancel();
                $cancelled = true;
            }

            $comment = 'PLUG-02: cart items changed after payment was initiated (Razorpay order ' . $rzpOrderId
                . ', payment ' . $paymentId . '). ';

            if ($cancelled) {
                $comment .= 'Order cancelled and stock released. ';
            } elseif ($alreadyCancelled) {
                $comment .= 'Order was already cancelled. ';
            } else {
                $comment .= 'Order could not be cancelled automatically, please review manually. ';
            }

            switch ($outcome) {
                case self::OUTCOME_REFUNDED:
                case self::OUTCOME_ALREADY_REFUNDED:
                    $comment .= 'Payment refunded.';
                    break;
                case self::OUTCOME_REFUND_PENDING:
                    $comment .= 'Payment is not captured yet; it will be refunded automatically once captured.';
                    break;
                case self::OUTCOME_AUTH_LAPSE:
                    $comment .= 'ALERT: payment is only authorized (not captured) so it cannot be refunded; the authorization will lapse automatically. Do not capture it.';
                    break;
                case self::OUTCOME_REFUND_FAILED:
                    $comment .= 'ALERT: automatic refund FAILED. Refund this payment manually from the Razorpay dashboard.';
                    break;
                default:
                    $comment .= 'No payment refund was required.';
            }

            $order->addStatusHistoryComment($comment);
            $order->save();
        } catch (\Exception $e) {
            $this->logger->critical('PLUG-02: could not cancel order ' . $order->getIncrementId() . ': ' . $e->getMessage());
        }

        return $cancelled;
    }
}
