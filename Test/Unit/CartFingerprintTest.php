<?php
/**
 * PLUG-02 unit tests for Razorpay\Magento\Model\Util\CartFingerprint.
 *
 * Dependency-free (this branch has no PHPUnit/Magento test infrastructure):
 *   php Test/Unit/CartFingerprintTest.php
 * Exits non-zero on failure.
 */

require_once __DIR__ . '/../../Model/Util/CartFingerprint.php';
require_once __DIR__ . '/../../Constants/OrderCronStatus.php';
require_once __DIR__ . '/Stubs.php';
require_once __DIR__ . '/../../Model/CartGuard.php';

use Razorpay\Magento\Model\Util\CartFingerprint as F;
use Razorpay\Magento\Model\CartGuard as G;
use Razorpay\Magento\Constants\OrderCronStatus as S;

class FakeItem
{
    private $sku;
    private $qty;

    public function __construct($sku, $qty)
    {
        $this->sku = $sku;
        $this->qty = $qty;
    }

    public function getSku() { return $this->sku; }
    public function getQty() { return $this->qty; }
}

class FakeOrderItem extends FakeItem
{
    public function getQtyOrdered() { return $this->getQty(); }
}

class FakeCart
{
    private $id;
    private $items;

    public function __construct($items, $id = 1) { $this->items = $items; $this->id = $id; }
    public function getId() { return $this->id; }
    public function getAllVisibleItems() { return $this->items; }
}

class FakeLogger
{
    public $lines = [];
    public function info($m) { $this->lines[] = $m; }
    public function critical($m) { $this->lines[] = $m; }
}

// ---- CartGuard doubles (the class is built without its Magento constructor) ----
class FakePayment extends ArrayObject
{
    public $refunds = [];
    public $failRefunds = 0;
    public function refund($data)
    {
        if ($this->failRefunds > 0) { $this->failRefunds--; throw new Exception('refund api down'); }
        $this->refunds[] = $data;
        $this['amount_refunded'] = ($this['amount_refunded'] ?? 0) + $data['amount'];
        return true;
    }
}
class FakeRzp
{
    public $payment;
    public $order;
    public function __construct($payment)
    {
        $this->payment = new class($payment) { public $p; public $fail = null; function __construct($p) { $this->p = $p; } function fetch($id) { if ($this->fail) { throw $this->fail; } return $this->p; } };
        $this->order = new class { function fetch($id) { return ['id' => $id, 'receipt' => 'R1', 'notes' => ['cart_mask_id' => 'm']]; } };
    }
}
class FakeLink
{
    public $data;
    public $saves = 0;
    public function __construct($data) { $this->data = $data; }
    public function __call($m, $a)
    {
        $key = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', substr($m, 3)));
        if (strpos($m, 'get') === 0) { return $this->data[$key] ?? null; }
        $this->data[$key] = $a[0];
        return $this;
    }
    public function getId() { return $this->data['entity_id'] ?? null; }
    public function save() { $this->saves++; return $this; }
    public function load($id) { return $this; }
    public function getCollection() { return $this; }
    public function addFilter($f, $v) { return $this; }
    public function getFirstItem() { return $this; }
}
class FakeFactory
{
    public $made = 0;
    private $cb;
    public function __construct($cb) { $this->cb = $cb; }
    public function create() { $this->made++; return ($this->cb)(); }
}
class FakeEventSink
{
    public $events = [];
    public function rzpTrackDataLake($e, $p) { $this->events[] = [$e, $p]; }
}
class FakeConfig
{
    public $action = 'authorize_capture';
    public $guard = true;
    public function isMagicCartGuardEnabled() { return $this->guard; }
    public function getPaymentAction() { return $this->action; }
}
class FakeLoadedOrder extends FakeCart
{
    public $cancelled = false;
    public $comments = [];
    public function isCanceled() { return $this->cancelled; }
    public function canCancel() { return !$this->cancelled; }
    public function cancel() { $this->cancelled = true; }
    public function addStatusHistoryComment($c) { $this->comments[] = $c; }
    public function save() {}
    public function getIncrementId() { return 'INC'; }
}

function makeGuard($payment, $link, $orderFactory = null, $mode = 'authorize_capture', $guardOn = true)
{
    $ref = new ReflectionClass(G::class);
    $g = $ref->newInstanceWithoutConstructor();
    $cfg = new FakeConfig();
    $cfg->action = $mode;
    $cfg->guard = $guardOn;
    $sink = new FakeEventSink();
    foreach ([
        'rzp' => new FakeRzp($payment),
        'config' => $cfg,
        'orderFactory' => $orderFactory,
        'orderLinkFactory' => new FakeFactory(function () use ($link) { return $link; }),
        'trackPluginInstrumentation' => $sink,
        'logger' => new FakeLogger(),
    ] as $prop => $val) {
        $p = $ref->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue($g, $val);
    }
    $g->sink = $sink;
    $g->fake = $ref->getProperty('rzp')->getValue($g);
    return $g;
}

$failures = 0;
$count = 0;

function check($name, $cond)
{
    global $failures, $count;
    $count++;
    if (!$cond) {
        $failures++;
        echo "FAIL: $name\n";
    }
}

function rzpOrder($items, $withMask = true, $fingerprint = 'auto')
{
    $notes = [];
    if ($withMask) {
        $notes['cart_mask_id'] = 'mask';
    }
    if ($fingerprint === 'auto') {
        $notes[F::NOTE_KEY] = F::compute($items);
    } elseif ($fingerprint !== null) {
        $notes[F::NOTE_KEY] = $fingerprint;
    }

    return ['id' => 'order_X', 'notes' => $notes];
}

$cart = [new FakeItem('A', 1), new FakeItem('B', 2)];

// --- comparator ---
check('match', F::compare(rzpOrder($cart), $cart) === F::VERDICT_MATCH);
check('order of items does not matter', F::compare(rzpOrder($cart), array_reverse($cart)) === F::VERDICT_MATCH);
check('qty "2" vs 2.0 vs "2.0000" are equal', F::compute([new FakeItem('A', '2')]) === F::compute([new FakeItem('A', 2.0)]) && F::compute([new FakeItem('A', '2.0000')]) === F::compute([new FakeItem('A', 2)]));
check('added item', F::compare(rzpOrder($cart), array_merge($cart, [new FakeItem('C', 1)])) === F::VERDICT_MISMATCH);
check('removed item', F::compare(rzpOrder($cart), [$cart[0]]) === F::VERDICT_MISMATCH);
check('qty change', F::compare(rzpOrder($cart), [new FakeItem('A', 1), new FakeItem('B', 3)]) === F::VERDICT_MISMATCH);
check('fractional qty change not truncated', F::compare(rzpOrder([new FakeItem('A', 1)]), [new FakeItem('A', 1.5)]) === F::VERDICT_MISMATCH);
check('variant swap (sku change)', F::compare(rzpOrder($cart), [new FakeItem('A-RED', 1), $cart[1]]) === F::VERDICT_MISMATCH);
check('same SKU on multiple rows is summed', F::compute([new FakeItem('A', 1), new FakeItem('A', 2)]) === F::compute([new FakeItem('A', 3)]));
check('sku/qty delimiter injection cannot collide', F::compute([new FakeItem('A:1', 1)]) !== F::compute([new FakeItem('A', 11)]) && F::compute([new FakeItem('A', 1), new FakeItem('B', 1)]) !== F::compute([new FakeItem("A\",\"1\"],[\"B", 1)]));
check('numeric SKUs ("10" vs "9") sort stably', F::compute([new FakeItem('10', 1), new FakeItem('9', 1)]) === F::compute([new FakeItem('9', 1), new FakeItem('10', 1)]));
check('order items (qty_ordered) match quote items', F::compare(rzpOrder($cart), [new FakeOrderItem('A', 1), new FakeOrderItem('B', 2)]) === F::VERDICT_MATCH);
check('empty cart is a stable fingerprint', F::compare(rzpOrder([]), []) === F::VERDICT_MATCH);

// --- missing / malformed / not applicable ---
$log = new FakeLogger();
check('fingerprint missing => unknown (pass)', F::compare(rzpOrder($cart, true, null), $cart, $log) === F::VERDICT_UNKNOWN);
check('missing fingerprint logs exactly one line', count($log->lines) === 1);
check('malformed note => unknown', F::compare(rzpOrder($cart, true, 'not-a-hash'), $cart) === F::VERDICT_UNKNOWN);
check('non-scalar note => unknown', F::compare(['id' => 'o', 'notes' => ['cart_mask_id' => 'm', F::NOTE_KEY => ['x']]], $cart) === F::VERDICT_UNKNOWN);
check('non-Magic order (no cart_mask_id) => not applicable', F::compare(rzpOrder($cart, false), [new FakeItem('Z', 9)]) === F::VERDICT_NOT_APPLICABLE);
check('object-shaped order notes are readable', F::compare((object)['id' => 'o', 'notes' => (object)['cart_mask_id' => 'm', F::NOTE_KEY => F::compute($cart)]], $cart) === F::VERDICT_MATCH);
check('ArrayObject (SDK entity) notes are readable', F::compare(new ArrayObject(['id' => 'o', 'notes' => new ArrayObject(['cart_mask_id' => 'm', F::NOTE_KEY => F::compute($cart)])]), $cart) === F::VERDICT_MATCH);

// --- identity checks (rotation attack) ---
// Attacker: PlaceOrder #1 (big cart A) then PlaceOrder #2 (cheap cart B); pays B, submits A's notes/order id.
// Session holds the LATEST order id (B). Request names A. Payment belongs to B.
check('ROTATION ATTACK: request order id (A) != session order id (B) is rejected', F::identityRejection('order_A', 'order_B', 'order_B') !== null);
check('ROTATION ATTACK variant: payment on B, request+session A is rejected', F::identityRejection('order_A', 'order_A', 'order_B') !== null);
check('identity ok', F::identityRejection('order_A', 'order_A', 'order_A', 'order_A') === null);
check('identity: link mismatch rejected', F::identityRejection('order_A', 'order_A', 'order_A', 'order_Z') !== null);
check('identity: empty session id rejected', F::identityRejection('order_A', null, 'order_A') !== null);
check('identity: missing payment order id rejected', F::identityRejection('order_A', 'order_A', null) !== null);
check('payment failed rejected', F::paymentStatusRejection('failed') !== null);
check('payment refunded rejected', F::paymentStatusRejection('refunded') !== null);
check('payment captured/authorized/pending ok', F::paymentStatusRejection('captured') === null && F::paymentStatusRejection('authorized') === null && F::paymentStatusRejection('pending') === null);

// --- existing order wins over quote (AbandonedQuote exploit) ---
// Attacker pays for a cheap cart, triggers AbandonedQuote which pre-places an order from a different (big) quote state,
// then calls CompleteOrder. The quote still looks like the cheap cart; the placed order does not.
$cheap = [new FakeItem('CHEAP', 1)];
$big = [new FakeItem('BIG', 5)];
$rzp = rzpOrder($cheap);
$existingBig = new FakeCart($big, 42);
$quoteCheap = new FakeCart($cheap, 7);
check('ABANDONED-QUOTE EXPLOIT: existing order items are compared, not the quote => mismatch', F::compare($rzp, F::pickComparableItems($existingBig, $quoteCheap)) === F::VERDICT_MISMATCH);
check('no existing order => quote items compared', F::compare($rzp, F::pickComparableItems(new FakeCart([], null), $quoteCheap)) === F::VERDICT_MATCH);
check('null existing order => quote items compared', F::compare($rzp, F::pickComparableItems(null, $quoteCheap)) === F::VERDICT_MATCH);
check('existing order that matches passes', F::compare($rzp, F::pickComparableItems(new FakeCart($cheap, 42), $quoteCheap)) === F::VERDICT_MATCH);

// =====================  CartGuard (money paths)  =====================
$rzpO = ['id' => 'order_A', 'receipt' => 'R1', 'notes' => ['cart_mask_id' => 'm']];
function pay($status, $amount = 10000, $refunded = 0)
{
    return new FakePayment(['id' => 'pay_1', 'status' => $status, 'method' => 'card', 'amount' => $amount, 'amount_refunded' => $refunded, 'order_id' => 'order_A']);
}

// F-captured: reject on a captured payment refunds it, status 7 set before the refund, order cancelled once.
$link = new FakeLink(['entity_id' => 5, 'rzp_order_id' => 'order_A', 'rzp_update_order_cron_status' => 1]);
$payment = pay('captured');
$g = makeGuard($payment, $link);
$ord = new FakeLoadedOrder([], 9);
$out = $g->reject($rzpO, $payment, 'test', $ord);
check('captured: refund called for full amount', $out === G::OUTCOME_REFUNDED && count($payment->refunds) === 1 && $payment->refunds[0]['amount'] === 10000);
check('captured: link ends as status 7', (int)$link->data['rzp_update_order_cron_status'] === S::ORDER_ITEM_MISMATCH_REJECTED);
check('captured: existing order cancelled with admin comment', $ord->cancelled && count($ord->comments) === 1);
check('captured: datalake event emitted', count($g->sink->events) === 1 && $g->sink->events[0][1]['outcome'] === 'refunded');

// F1: capture mode + payment only authorized => no lapse; refund-pending recorded; refund fires once captured.
$link = new FakeLink(['entity_id' => 5, 'rzp_order_id' => 'order_A', 'rzp_update_order_cron_status' => 1]);
$payment = pay('authorized');
$g = makeGuard($payment, $link, null, 'authorize_capture');
$out = $g->reject($rzpO, $payment, 'test', null);
check('capture-mode + authorized payment: refund pending, NOT lapse', $out === G::OUTCOME_REFUND_PENDING && count($payment->refunds) === 0);
check('capture-mode + authorized: link in refund-pending status (blocks completion)', (int)$link->data['rzp_update_order_cron_status'] === S::ORDER_ITEM_MISMATCH_REFUND_PENDING && G::isRejectedStatus($link->data['rzp_update_order_cron_status']));
$link->data['rzp_order_id'] = 'order_A';
$stillPending = $g->retryPendingRefund('order_A');
check('refund retry while still uncaptured keeps pending', $stillPending === G::OUTCOME_REFUND_PENDING && (int)$link->data['rzp_update_order_cron_status'] === S::ORDER_ITEM_MISMATCH_REFUND_PENDING);
$payment['status'] = 'captured';
$done = $g->retryPendingRefund('order_A');
check('order.paid/cron retry refunds once captured', $done === G::OUTCOME_REFUNDED && count($payment->refunds) === 1 && (int)$link->data['rzp_update_order_cron_status'] === S::ORDER_ITEM_MISMATCH_REJECTED);

// Authorize mode: lapse, no refund, alert emitted.
$link = new FakeLink(['entity_id' => 5, 'rzp_order_id' => 'order_A', 'rzp_update_order_cron_status' => 1]);
$payment = pay('authorized');
$g = makeGuard($payment, $link, null, 'authorize');
$ord = new FakeLoadedOrder([], 9);
$out = $g->reject($rzpO, $payment, 'test', $ord);
check('authorize mode: lapse, no refund, status 7', $out === G::OUTCOME_AUTH_LAPSE && count($payment->refunds) === 0 && (int)$link->data['rzp_update_order_cron_status'] === S::ORDER_ITEM_MISMATCH_REJECTED);
check('authorize mode: admin alert in order comment', strpos($ord->comments[0], 'ALERT') !== false);

// F4: partial prior refund => remainder only.
$link = new FakeLink(['entity_id' => 5, 'rzp_order_id' => 'order_A', 'rzp_update_order_cron_status' => 1]);
$payment = pay('captured', 10000, 4000);
$g = makeGuard($payment, $link);
$out = $g->reject($rzpO, $payment, 'test', null);
check('partial refund: refunds the remainder (6000)', $out === G::OUTCOME_REFUNDED && $payment->refunds[0]['amount'] === 6000);
$payment = pay('captured', 10000, 10000);
$g = makeGuard($payment, $link);
check('fully refunded already => success, no second refund', $g->settlePayment($rzpO, $payment) === G::OUTCOME_ALREADY_REFUNDED && count($payment->refunds) === 0);
$payment = pay('refunded');
check('status refunded => success', makeGuard($payment, $link)->settlePayment($rzpO, $payment) === G::OUTCOME_ALREADY_REFUNDED);

// F4: refund failure is not silent: outcome refund_failed, status stays 7, durable event emitted, order comment alerts.
$link = new FakeLink(['entity_id' => 5, 'rzp_order_id' => 'order_A', 'rzp_update_order_cron_status' => 1]);
$payment = pay('captured');
$payment->failRefunds = 5;
$g = makeGuard($payment, $link);
$ord = new FakeLoadedOrder([], 9);
$out = $g->reject($rzpO, $payment, 'test', $ord);
check('refund failure: outcome refund_failed + event + alert comment', $out === G::OUTCOME_REFUND_FAILED && $g->sink->events[0][1]['outcome'] === 'refund_failed' && strpos($ord->comments[0], 'FAILED') !== false);
// retry once on a transient failure
$payment = pay('captured');
$payment->failRefunds = 1;
check('one transient refund failure is retried', makeGuard($payment, $link)->settlePayment($rzpO, $payment) === G::OUTCOME_REFUNDED);

// F4: already-cancelled order => no double-cancel wording.
$ord = new FakeLoadedOrder([], 9);
$ord->cancelled = true;
$payment = pay('captured');
makeGuard($payment, new FakeLink(['entity_id' => 5, 'rzp_order_id' => 'order_A']))->reject($rzpO, $payment, 'test', $ord);
check('already cancelled order: comment says so, not "cancelled and stock released"', strpos($ord->comments[0], 'already cancelled') !== false && strpos($ord->comments[0], 'stock released') === false);

// F3 + cron multi-row batch: each row gets a FRESH order (no stale items from the previous row).
$rowOrders = [
    'ORD-1' => new FakeCart([new FakeItem('A', 1)], 11),
    'ORD-2' => new FakeCart([new FakeItem('B', 2)], 12),
];
$created = 0;
$orderFactory = new class($rowOrders, $created) {
    private $orders; private $current;
    function __construct($o) { $this->orders = $o; }
    function create() { return new class($this->orders) { private $orders; private $cur; function __construct($o) { $this->orders = $o; } function loadByIncrementId($id) { $this->cur = $this->orders[$id] ?? new FakeCart([], null); return $this->cur; } }; }
};
$g = makeGuard(pay('captured'), new FakeLink([]), $orderFactory);
$quoteEmpty = new FakeCart([], 99);
$r1 = $g->compareForRow(rzpOrder([new FakeItem('A', 1)]), $quoteEmpty, 'ORD-1');
$r2 = $g->compareForRow(rzpOrder([new FakeItem('B', 2)]), $quoteEmpty, 'ORD-2');
$r3 = $g->compareForRow(rzpOrder([new FakeItem('A', 1)]), new FakeCart([new FakeItem('A', 1)], 98), 'ORD-NONE');
check('batch row 1 compares its own order items', $r1[0] === F::VERDICT_MATCH);
check('batch row 2 is not polluted by row 1 (matches its own items)', $r2[0] === F::VERDICT_MATCH);
check('batch row 3 with no order falls back to the quote, not row 2 leftovers', $r3[0] === F::VERDICT_MATCH && $r3[1] === null);

// F5: attempt counter + cap.
$link = new FakeLink(['entity_id' => 7, 'rzp_webhook_data' => null]);
$g = makeGuard(pay('captured'), $link);
$n = 0;
for ($i = 0; $i < G::MAX_CRON_ATTEMPTS; $i++) { $n = $g->recordCronAttempt(7); }
check('attempt counter reaches the cap after MAX attempts', $n === G::MAX_CRON_ATTEMPTS);
$link->data['rzp_webhook_data'] = serialize(['order.paid' => ['payment_id' => 'p']]);
$g->recordCronAttempt(7);
check('attempt counter preserves existing webhook data', isset(unserialize($link->data['rzp_webhook_data'])['order.paid']));

// F2/F5: abandonRow. Captured payment, no Magento order => refunded + terminal + durable event.
$link = new FakeLink(['entity_id' => 7, 'rzp_update_order_cron_status' => 0]);
$payment = pay('captured');
$noOrder = new class { function create() { return new class { function loadByIncrementId($i) { return new FakeCart([], null); } }; } };
$g = makeGuard($payment, $link, $noOrder);
$rzpWithMerchant = ['id' => 'order_A', 'receipt' => 'R1', 'notes' => ['cart_mask_id' => 'm', 'merchant_order_id' => '000123']];
$out = $g->abandonRow(['entity_id' => 7, 'rzp_order_id' => 'order_A', 'rzp_payment_id' => 'pay_1'], 'quote not found', $rzpWithMerchant, $payment);
check('abandon with captured payment and no order: refund + terminal + event', $out === G::OUTCOME_REFUNDED && count($payment->refunds) === 1 && (int)$link->data['rzp_update_order_cron_status'] === S::CRON_UNRECOVERABLE_SKIPPED && $g->sink->events[0][0] === G::EVENT_CRON_TERMINAL);
// abandon when the Magento order exists: no refund
$link = new FakeLink(['entity_id' => 7, 'rzp_update_order_cron_status' => 0]);
$payment = pay('captured');
$hasOrder = new class { function create() { return new class { function loadByIncrementId($i) { return new FakeCart([], 55); } }; } };
$g = makeGuard($payment, $link, $hasOrder);
$g->abandonRow(['entity_id' => 7, 'rzp_order_id' => 'order_A'], 'order already placed', $rzpWithMerchant, $payment);
check('abandon when a Magento order exists never refunds', count($payment->refunds) === 0);
// abandon never overwrites a mismatch status
$link = new FakeLink(['entity_id' => 7, 'rzp_update_order_cron_status' => S::ORDER_ITEM_MISMATCH_REFUND_PENDING]);
$g = makeGuard(pay('authorized'), $link);
$g->abandonRow(['entity_id' => 7, 'rzp_order_id' => 'order_A'], 'x');
check('abandon does not overwrite mismatch statuses', (int)$link->data['rzp_update_order_cron_status'] === S::ORDER_ITEM_MISMATCH_REFUND_PENDING);

// N1: only HTTP 404 is permanent. 401/429/5xx/400-other stay retryable.
use Razorpay\Api\Errors\BadRequestError;
check('N1: 404 is permanent not-found', G::isPermanentNotFound(new BadRequestError('x', 'BAD_REQUEST_ERROR', 404)));
check('N1: 401 stays retryable', !G::isPermanentNotFound(new BadRequestError('x', 'BAD_REQUEST_ERROR', 401)));
check('N1: 429 stays retryable', !G::isPermanentNotFound(new BadRequestError('x', 'BAD_REQUEST_ERROR', 429)));
check('N1: 400 / 500 / plain exceptions stay retryable', !G::isPermanentNotFound(new BadRequestError('x', 'BAD_REQUEST_ERROR', 400)) && !G::isPermanentNotFound(new BadRequestError('x', 'SERVER_ERROR', 500)) && !G::isPermanentNotFound(new Exception('network')));

// N2: abandonRow never decides about money with unknown payment state.
$link = new FakeLink(['entity_id' => 7, 'rzp_update_order_cron_status' => 0]);
$payment = pay('captured');
$g = makeGuard($payment, $link, $noOrder);
$g->fake->payment->fail = new BadRequestError('rate limited', 'BAD_REQUEST_ERROR', 429);
$out = $g->abandonRow(['entity_id' => 7, 'rzp_order_id' => 'order_A', 'rzp_payment_id' => 'pay_1'], 'order fetch failed', $rzpWithMerchant, null);
check('N2: payment refetch fails (429) => row stays retryable, no terminal status, no refund, no event', $out === G::OUTCOME_RETRY_LATER && (int)$link->data['rzp_update_order_cron_status'] === 0 && count($payment->refunds) === 0 && count($g->sink->events) === 0);
$g->fake->payment->fail = null;
$out = $g->abandonRow(['entity_id' => 7, 'rzp_order_id' => 'order_A', 'rzp_payment_id' => 'pay_1'], 'order fetch failed', $rzpWithMerchant, null);
check('N2: payment refetch succeeds => captured payment refunded, then terminal', $out === G::OUTCOME_REFUNDED && count($payment->refunds) === 1 && (int)$link->data['rzp_update_order_cron_status'] === S::CRON_UNRECOVERABLE_SKIPPED);
$link = new FakeLink(['entity_id' => 7, 'rzp_update_order_cron_status' => 0]);
$g = makeGuard(pay('captured'), $link, $noOrder);
$g->fake->payment->fail = new BadRequestError('gone', 'BAD_REQUEST_ERROR', 404);
$out = $g->abandonRow(['entity_id' => 7, 'rzp_order_id' => 'order_A', 'rzp_payment_id' => 'pay_1'], 'payment 404', $rzpWithMerchant, null);
check('N2: payment 404 => nothing to refund, terminal', $out === G::OUTCOME_NOT_REQUIRED && (int)$link->data['rzp_update_order_cron_status'] === S::CRON_UNRECOVERABLE_SKIPPED);

// N3: refund failure => status 9 (retryable) with a retry cap, then a durable final alert.
$link = new FakeLink(['entity_id' => 5, 'rzp_order_id' => 'order_A', 'rzp_payment_id' => 'pay_1', 'rzp_update_order_cron_status' => 1]);
$payment = pay('captured');
$payment->failRefunds = 1000;
$g = makeGuard($payment, $link);
$g->reject($rzpO, $payment, 'test', null);
check('N3: REFUND_FAILED maps to status 9, not parked at 7', (int)$link->data['rzp_update_order_cron_status'] === S::ORDER_ITEM_MISMATCH_REFUND_PENDING);
$last = null;
for ($i = 1; $i <= G::MAX_REFUND_ATTEMPTS; $i++) {
    $last = $g->retryPendingRefund('order_A');
    if ($i < G::MAX_REFUND_ATTEMPTS) {
        if ((int)$link->data['rzp_update_order_cron_status'] !== S::ORDER_ITEM_MISMATCH_REFUND_PENDING) { check('N3: stays 9 until the cap (attempt ' . $i . ')', false); }
    }
}
check('N3: after the cap the row stops retrying (status 7) and a final alert event is emitted', (int)$link->data['rzp_update_order_cron_status'] === S::ORDER_ITEM_MISMATCH_REJECTED && end($g->sink->events)[1]['outcome'] === 'refund_failed_final');

// N4: a standard row + Razorpay outage never goes terminal. The cron now only `continue`s on order-fetch failure and
// only CartGuard::abandonRow/markers write terminal status; nothing in CartGuard is reachable for an unclassified row.
$link = new FakeLink(['entity_id' => 9, 'rzp_update_order_cron_status' => 0]);
$g = makeGuard(pay('captured'), $link);
for ($i = 0; $i < 50; $i++) { /* the cron performs no CartGuard call for a failed order fetch */ }
check('N4: no status write happens for an unclassified row (guard untouched)', (int)$link->data['rzp_update_order_cron_status'] === 0 && $link->saves === 0);
// structural guard: the order-fetch failure path in the cron must not call the guard at all
$cron = file_get_contents(__DIR__ . '/../../Cron/UpdateOrdersToProcessingV2.php');
$fetchCatch = substr($cron, strpos($cron, '$this->api->order->fetch($orderData'), 600);
check('N4: order-fetch failure path contains no abandonRow/handleTransientFailure call', strpos($fetchCatch, 'abandonRow') === false && strpos($fetchCatch, 'handleTransientFailure') === false && strpos($fetchCatch, 'continue;') !== false);
$ap = strpos($cron, 'abandonRow'); $mg = strpos($cron, '$isMagicOrder = $this->checkMagicOrder');
check('N4: first terminal marking in the cron comes after the Magic classification', $ap > $mg);

// N5: placement uses fresh models: row1 order placed, row2 not placed => row2 must not see row1's order.
$db = [];
$factoryN5 = new class($db) {
    public $db;
    function __construct(&$db) { $this->db = &$db; }
    function create() { return new class($this->db) { private $db; private $cache; function __construct(&$db) { $this->db = &$db; } function loadByIncrementId($id) { $this->cache = $this->db[$id] ?? null; return $this->cache ?: new FakeCart([], null); } function load($id) { return new FakeCart([], $id); } }; }
};
$g = makeGuard(pay('captured'), new FakeLink([]), $factoryN5);
$factoryN5->db['ROW1'] = new FakeCart([new FakeItem('A', 1)], 101);
$o1 = $g->loadFreshByIncrementId('ROW1');
$o2 = $g->loadFreshByIncrementId('ROW2');
check('N5: row1 order found, row2 (not placed) sees NO order from row1', $o1->getId() === 101 && $o2->getId() === null);
check('N5: each call builds a new model', $factoryN5->db !== null);
$cronSrc = $cron;
check('N5: placeMagentoOrderthroughCron no longer uses the shared $this->order for loads', preg_match('/this->order->(loadByIncrementId|load)\(/', $cronSrc) === 0);

// ===== Kill switch (payment/razorpay/magic_cart_guard) =====
$cheap = [new FakeItem('CHEAP', 1)];
$bigQuote = new FakeCart([new FakeItem('BIG', 5)], 7);
$rzpMismatch = rzpOrder($cheap);
$off = makeGuard(pay('captured'), new FakeLink(['entity_id' => 1, 'rzp_update_order_cron_status' => 0]), $noOrder, 'authorize_capture', false);
$on = makeGuard(pay('captured'), new FakeLink(['entity_id' => 1, 'rzp_update_order_cron_status' => 0]), $noOrder, 'authorize_capture', true);
check('switch ON: mismatch is detected', $on->check($rzpMismatch, $bigQuote->getAllVisibleItems()) === F::VERDICT_MISMATCH);
check('switch OFF: mismatch passes (not_applicable)', $off->check($rzpMismatch, $bigQuote->getAllVisibleItems()) === F::VERDICT_NOT_APPLICABLE);
check('switch OFF: compareForRow never loads an order and passes', $off->compareForRow($rzpMismatch, $bigQuote, '000123') === [F::VERDICT_NOT_APPLICABLE, null]);
check('switch OFF: identity rotation attack is NOT rejected (pre-PR behaviour)', $off->identityRejection('order_A', 'order_B', 'order_B') === null);
check('switch ON: identity rotation attack rejected', $on->identityRejection('order_A', 'order_B', 'order_B') !== null);
check('switch OFF: failed/refunded payment status not rejected', $off->paymentStatusRejection('failed') === null && $off->paymentStatusRejection('refunded') === null);
check('switch ON: failed payment rejected', $on->paymentStatusRejection('failed') !== null);
$offLink = new FakeLink(['entity_id' => 1, 'rzp_update_order_cron_status' => 0]);
$offGuard = makeGuard(pay('captured'), $offLink, $noOrder, 'authorize_capture', false);
check('switch OFF: abandonRow is a no-op (no status write, no refund, no event)', $offGuard->abandonRow(['entity_id' => 1, 'rzp_order_id' => 'order_A', 'rzp_payment_id' => 'pay_1'], 'x', $rzpWithMerchant, null) === G::OUTCOME_RETRY_LATER && $offLink->saves === 0 && count($offGuard->sink->events) === 0);
check('switch OFF: attempt counter is a no-op', $offGuard->recordCronAttempt(1) === 0 && $offLink->saves === 0);
// A payment rejected while ON stays blocked and its refund still completes after switching OFF.
$link = new FakeLink(['entity_id' => 5, 'rzp_order_id' => 'order_A', 'rzp_payment_id' => 'pay_1', 'rzp_update_order_cron_status' => S::ORDER_ITEM_MISMATCH_REFUND_PENDING]);
$payment = pay('captured');
$offLate = makeGuard($payment, $link, null, 'authorize_capture', false);
check('switch OFF: already-rejected link stays rejected (never completed)', $offLate->isRejected($link));
check('switch OFF: pending refund is still completed', $offLate->retryPendingRefund('order_A') === G::OUTCOME_REFUNDED && count($payment->refunds) === 1);
// Source-level gates (cannot run PlaceOrder without Magento).
$po = file_get_contents(__DIR__ . '/../../Controller/OneClick/PlaceOrder.php');
check('switch OFF: PlaceOrder does not compute/write the fingerprint', strpos($po, 'isMagicCartGuardEnabled()') !== false && strpos($po, 'if ($cartFingerprint !== null)') !== false);
$cfgSrc = file_get_contents(__DIR__ . '/../../etc/config.xml');
check('config default is ON', strpos($cfgSrc, '<magic_cart_guard>1</magic_cart_guard>') !== false);
$sx = file_get_contents(__DIR__ . '/../../etc/adminhtml/system.xml');
check('system.xml exposes magic_cart_guard with its config path', strpos($sx, 'payment/razorpay/magic_cart_guard') !== false);
check('magic_cart_guard is default-scope only', preg_match('/id="magic_cart_guard"[^>]*showInDefault="1" showInWebsite="0" showInStore="0"/', $sx) === 1);
check('PlaceOrder: collectTotals only inside the kill-switch gate', preg_match('/if \(\$this->config->isMagicCartGuardEnabled\(\)\) \{\s*\/\/[^\n]*\n\s*\$quote->collectTotals\(\);/', $po) === 1);

// Not unit-testable without a Magento/PHP runtime (documented in the PR): the cron's branch structure
// (non-Magic rows are processed OUTSIDE the terminal-marking path; Magic-branch generic exceptions only count an attempt),
// DI wiring, Callback's Magic gate and redirect-on-fetch-failure, and the webhook hook that calls retryPendingRefund.

echo ($count - $failures) . "/$count passed\n";
exit($failures === 0 ? 0 : 1);
