<?php

namespace Razorpay\Magento\Model\Util;

/**
 * PLUG-02: fingerprint of a Magic Checkout cart (SKU + quantity of the visible items).
 *
 * Written to the Razorpay order notes when the Razorpay order is created, and compared with the
 * cart or order that is about to be placed or marked paid. Prices are deliberately not part of it.
 * Plain PHP with no Magento dependencies, so it can be tested on its own.
 */
class CartFingerprint
{
    const NOTE_KEY = 'cart_fingerprint';

    const MATCH = 'match';
    const MISMATCH = 'mismatch';
    // Magic order without a fingerprint (created before this change): not checked.
    const UNKNOWN = 'unknown';
    // Not a Magic order (no cart_mask_id note): not checked.
    const NOT_MAGIC = 'not_magic';

    const QTY_SCALE = 10000;

    /**
     * @param iterable $items visible quote or order items (getSku(), getData($qtyField))
     * @param string $qtyField 'qty' for quote items, 'qty_ordered' for order items
     * @return string sha256 hex
     */
    public static function compute($items, $qtyField)
    {
        $totals = [];

        foreach ($items as $item) {
            $sku = (string)$item->getSku();
            $qty = $item->getData($qtyField);
            $scaled = is_numeric($qty) ? (int)round((float)$qty * self::QTY_SCALE) : 0;

            $totals[$sku] = (isset($totals[$sku]) ? $totals[$sku] : 0) + $scaled;
        }

        // Numeric SKUs become integer keys; sort and serialize them as strings.
        ksort($totals, SORT_STRING);

        $pairs = [];
        foreach ($totals as $sku => $scaled) {
            $pairs[] = [(string)$sku, $scaled];
        }

        return hash('sha256', serialize($pairs));
    }

    /**
     * Compares the fingerprint in the Razorpay ORDER notes with the given items.
     * Payment notes are never used: shoppers can set those.
     *
     * @param mixed $rzpOrderData Razorpay order entity
     * @param iterable $items
     * @param string $qtyField
     * @return string one of the verdict constants
     */
    public static function compare($rzpOrderData, $items, $qtyField)
    {
        if (self::readNote($rzpOrderData, 'cart_mask_id') === null) {
            return self::NOT_MAGIC;
        }

        $expected = self::readNote($rzpOrderData, self::NOTE_KEY);

        if ($expected === null || preg_match('/^[a-f0-9]{64}$/', $expected) !== 1) {
            return self::UNKNOWN;
        }

        return hash_equals($expected, self::compute($items, $qtyField)) ? self::MATCH : self::MISMATCH;
    }

    /**
     * Reads a note from a Razorpay entity (SDK entity, array or object). Null when absent or empty.
     */
    public static function readNote($entity, $key)
    {
        $value = self::field(self::field($entity, 'notes'), $key);

        if (is_scalar($value) === false || $value === '' || $value === false) {
            return null;
        }

        return (string)$value;
    }

    /**
     * Razorpay SDK entities implement ArrayAccess; API responses may also be arrays or plain objects.
     */
    public static function field($container, $key)
    {
        if (is_array($container) || $container instanceof \ArrayAccess) {
            return isset($container[$key]) ? $container[$key] : null;
        }

        if (is_object($container)) {
            return isset($container->$key) ? $container->$key : null;
        }

        return null;
    }
}
