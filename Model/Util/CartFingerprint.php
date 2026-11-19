<?php

namespace Razorpay\Magento\Model\Util;

/**
 * PLUG-02: cart fingerprint for Magic Checkout orders.
 *
 * Pure PHP (no Magento dependencies) so it can be unit tested in isolation.
 *
 * The fingerprint is a sha256 over the visible items of a quote/order (sum of qty per SKU,
 * canonical qty string, JSON-encoded pairs sorted by SKU). It is written server-side to the
 * Razorpay order notes (key `cart_fingerprint`) when the Razorpay order is created and compared
 * again before money-moving state changes. Only SKU + qty are compared; prices are NOT.
 */
class CartFingerprint
{
    const NOTE_KEY = 'cart_fingerprint';

    const VERDICT_MATCH = 'match';
    const VERDICT_MISMATCH = 'mismatch';
    // Fingerprint missing (order created before upgrade) or malformed: callers pass the order through.
    const VERDICT_UNKNOWN = 'unknown';
    // Not a Magic order (no cart_mask_id note): guard does not apply.
    const VERDICT_NOT_APPLICABLE = 'not_applicable';

    const QTY_SCALE = 10000;

    /**
     * @param iterable $items quote items (getQty) or order items (getQtyOrdered); visible items only
     */
    public static function compute($items)
    {
        $totals = [];

        foreach ($items as $item) {
            $sku = (string)$item->getSku();
            $qty = method_exists($item, 'getQtyOrdered') ? $item->getQtyOrdered() : $item->getQty();

            $scaled = is_numeric($qty) ? (int)round(((float)$qty) * self::QTY_SCALE) : 0;

            $totals[$sku] = ($totals[$sku] ?? 0) + $scaled;
        }

        // PHP turns numeric-string array keys into ints; cast back before sorting/encoding.
        $skus = array_map('strval', array_keys($totals));
        usort($skus, 'strcmp');

        $pairs = [];
        foreach ($skus as $sku) {
            $pairs[] = [$sku, self::canonicalQty($totals[$sku])];
        }

        return hash('sha256', json_encode($pairs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /**
     * 25000 => "2.5", 20000 => "2", 1 => "0.0001"
     */
    public static function canonicalQty($scaledQty)
    {
        $sign = $scaledQty < 0 ? '-' : '';
        $abs = abs((int)$scaledQty);

        $whole = intdiv($abs, self::QTY_SCALE);
        $fraction = rtrim(str_pad((string)($abs % self::QTY_SCALE), 4, '0', STR_PAD_LEFT), '0');

        return $sign . $whole . ($fraction === '' ? '' : '.' . $fraction);
    }

    /**
     * Compare the fingerprint stored in the Razorpay ORDER notes against the given items.
     * Reads order notes only, never payment notes.
     *
     * @param mixed $rzpOrderData Razorpay order entity (object/array)
     * @param iterable $items
     * @param \Psr\Log\LoggerInterface|null $logger
     */
    public static function compare($rzpOrderData, $items, $logger = null)
    {
        if (self::readNote($rzpOrderData, 'cart_mask_id') === null) {
            return self::VERDICT_NOT_APPLICABLE;
        }

        $expected = self::readNote($rzpOrderData, self::NOTE_KEY);

        if ($expected === null || preg_match('/^[a-f0-9]{64}$/', $expected) !== 1) {
            if ($logger !== null) {
                $logger->info('PLUG-02: cart_fingerprint '
                    . ($expected === null ? 'missing' : 'malformed')
                    . ' on Razorpay order ' . self::readOrderId($rzpOrderData)
                    . ', skipping item check (order created before upgrade)');
            }

            return self::VERDICT_UNKNOWN;
        }

        return hash_equals($expected, self::compute($items)) ? self::VERDICT_MATCH : self::VERDICT_MISMATCH;
    }

    /**
     * An already placed Magento order (e.g. pre-placed by AbandonedQuote) wins over the quote.
     *
     * @param object|null $existingOrder
     * @param object $quote
     */
    public static function pickComparableItems($existingOrder, $quote)
    {
        if ($existingOrder !== null && $existingOrder->getId()) {
            return $existingOrder->getAllVisibleItems();
        }

        return $quote->getAllVisibleItems();
    }

    /**
     * Identity checks for the payment being completed. Returns a reason string, or null when fine.
     */
    public static function identityRejection($requestOrderId, $sessionOrderId, $paymentOrderId, $linkRzpOrderId = null)
    {
        if (empty($requestOrderId) || empty($sessionOrderId) || (string)$requestOrderId !== (string)$sessionOrderId) {
            return 'request razorpay_order_id does not match the order id stored in session';
        }

        if (empty($paymentOrderId) || (string)$paymentOrderId !== (string)$sessionOrderId) {
            return 'payment does not belong to the session razorpay order';
        }

        if (empty($linkRzpOrderId) === false && (string)$linkRzpOrderId !== (string)$sessionOrderId) {
            return 'payment order id does not match the order link';
        }

        return null;
    }

    public static function paymentStatusRejection($paymentStatus)
    {
        if (in_array($paymentStatus, ['failed', 'refunded'], true)) {
            return 'payment status is ' . $paymentStatus;
        }

        return null;
    }

    public static function readNote($rzpOrderData, $key)
    {
        $value = self::field(self::field($rzpOrderData, 'notes'), $key);

        if (is_scalar($value) === false || $value === '' || $value === false) {
            return null;
        }

        return (string)$value;
    }

    /**
     * Razorpay SDK entities are ArrayObjects; test doubles may be plain objects or arrays.
     */
    private static function field($container, $key)
    {
        if (is_array($container) || $container instanceof \ArrayAccess) {
            return isset($container[$key]) ? $container[$key] : null;
        }

        if (is_object($container)) {
            return isset($container->$key) ? $container->$key : null;
        }

        return null;
    }

    private static function readOrderId($rzpOrderData)
    {
        $id = self::field($rzpOrderData, 'id');

        return is_scalar($id) ? (string)$id : '';
    }
}
