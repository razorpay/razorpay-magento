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
    // PLUG-02: cart items changed after the Razorpay order was created. Terminal; never retried by crons.
    const ORDER_ITEM_MISMATCH_REJECTED = 7;
    // Cron could not recover this row (bad/missing ids, payment failed or refunded, already placed). Terminal.
    const CRON_UNRECOVERABLE_SKIPPED = 8;
    // PLUG-02: item mismatch in capture mode but the payment is not captured yet. Blocks completion like 7;
    // the refund is retried (order.paid webhook, cron) once the payment is captured. Not selected by the order crons (>= 3).
    const ORDER_ITEM_MISMATCH_REFUND_PENDING = 9;
}
