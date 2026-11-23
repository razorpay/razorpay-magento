<?php

namespace Razorpay\Magento\Constants;

class OrderCronStatus
{
    const NEW_ORDER = 0;
    const PAYMENT_AUTHORIZED_COMPLETED = 1;
    const ORDER_PAID_AFTER_MANUAL_CAPTURE = 2;
    const INVOICE_GENERATED = 3;
    const INVOICE_GENERATION_NOT_POSSIBLE = 4;
    const PAYMENT_AUTHORIZED_CRON_REPEAT = 5;
    const ORDER_NOT_PLACED_DUE_TO_STOCK_UNAVAILABILITY = 6;
    // PLUG-02: cart items changed after payment started. Never completed later; the V2 cron skips it (>= 3).
    const ORDER_ITEM_MISMATCH_REJECTED = 7;
}
