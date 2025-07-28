<?php

namespace Razorpay\Magento\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteIdMaskFactory;

class Data extends AbstractHelper
{
    protected $quoteFactory;
    protected $quoteIdMaskFactory;

    public function __construct(
        Context $context,
        QuoteFactory $quoteFactory,
        QuoteIdMaskFactory $quoteIdMaskFactory
    ) {
        parent::__construct($context);
        $this->quoteFactory = $quoteFactory;
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
    }

    /**
     * Get cart items and totals using masked cart ID (guest cart)
     *
     * @param string $maskedCartId
     * @return array
     */
    public function getCartDetailsByMaskedId($maskedCartId)
    {
        try {
            // Convert masked ID to actual quote ID
            $quoteIdMask = $this->quoteIdMaskFactory->create()->load($maskedCartId, 'masked_id');
            $quoteId = $quoteIdMask->getQuoteId();

            if (!$quoteId) {
                return [];
            }

            // Load quote
            $quote = $this->quoteFactory->create()->load($quoteId);
            if (!$quote->getId()) {
                return [];
            }

            // Get visible items
            $items = $quote->getAllVisibleItems();
            $itemData = [];

            foreach ($items as $item) {
                $itemData[] = [
                    'product_id' => $item->getProductId(),
                    'sku'        => $item->getSku(),
                    'name'       => $item->getName(),
                    'price'      => $item->getPrice(),
                    'qty'        => $item->getQty(),
                    'row_total'  => $item->getRowTotal(),
                ];
            }

            // Quote-level totals
            $totals = [
                'subtotal'       => $quote->getSubtotal(),
                'subtotal_with_discount' => $quote->getSubtotalWithDiscount(),
                'discount_amount' => $quote->getDiscountAmount(),
                'tax_amount'     => $quote->getTaxAmount(),
                'shipping_amount' => $quote->getShippingAddress()->getShippingAmount(),
                'grand_total'    => $quote->getGrandTotal(),
                'currency_code'  => $quote->getQuoteCurrencyCode(),
            ];

            return [
                'items' => $itemData,
                'totals' => $totals
            ];

        } catch (\Exception $e) {
            $this->_logger->error('Cart Helper Error: ' . $e->getMessage());
            return [];
        }
    }
}
