<?php

namespace Razorpay\Magento\Model\Util;

/**
 * Pure helpers to fingerprint the items of a cart/order as a sorted list of "sku:qty".
 * Prices, tax, shipping and fees are deliberately excluded so honest recalculations never trip a check.
 */
class CartFingerprint
{
    const NOTE_KEY = 'cart_fingerprint';

    /**
     * @param iterable $items quote or order items (visible items only); each exposes getSku() and getQty()/getQtyOrdered()
     * @return array sorted list of "sku:qty" strings
     */
    public static function canonicalLines($items): array
    {
        $totals = [];
        foreach ($items as $item) {
            $qty = method_exists($item, 'getQtyOrdered') ? $item->getQtyOrdered() : $item->getQty();
            $sku = trim((string)$item->getSku());
            if ($sku === '' || (float)$qty <= 0) {
                continue;
            }
            $totals[$sku] = ($totals[$sku] ?? 0) + (float)$qty;
        }

        $lines = [];
        foreach ($totals as $sku => $qty) {
            $lines[] = $sku . ':' . rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.');
        }
        sort($lines, SORT_STRING);

        return $lines;
    }

    public static function hash(array $lines): string
    {
        return hash('sha256', implode("\n", $lines));
    }

    public static function fromItems($items): string
    {
        return self::hash(self::canonicalLines($items));
    }

    public static function matches(string $expectedHash, $items): bool
    {
        return hash_equals($expectedHash, self::fromItems($items));
    }
}
