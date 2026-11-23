<?php
/**
 * PLUG-02 tests. Standalone (this module has no PHPUnit setup):
 *   php Test/plug02_fingerprint_check.php
 * Exits non-zero on failure. Declares no named classes, so nothing here can shadow a real class.
 */

if (!interface_exists('Psr\Log\LoggerInterface')) {
    eval('namespace Psr\Log; interface LoggerInterface {
        public function emergency($message, array $context = array());
        public function alert($message, array $context = array());
        public function critical($message, array $context = array());
        public function error($message, array $context = array());
        public function warning($message, array $context = array());
        public function notice($message, array $context = array());
        public function info($message, array $context = array());
        public function debug($message, array $context = array());
        public function log($level, $message, array $context = array());
    }');
}

require_once __DIR__ . '/../Constants/OrderCronStatus.php';
require_once __DIR__ . '/../Model/Config.php';
require_once __DIR__ . '/../Model/Util/CartFingerprint.php';
require_once __DIR__ . '/../Model/CartGuard.php';

use Razorpay\Magento\Constants\OrderCronStatus;
use Razorpay\Magento\Model\CartGuard;
use Razorpay\Magento\Model\Util\CartFingerprint as FP;

$failures = 0;
$count = 0;

function check($name, $condition)
{
    global $failures, $count;
    $count++;
    if (!$condition) {
        $failures++;
        echo "FAIL: $name\n";
    }
}

/** Quote/order item double: getSku() and getData($field). */
function item($sku, $qty, $field = 'qty')
{
    return new class($sku, [$field => $qty]) {
        private $sku;
        private $data;
        public function __construct($sku, $data) { $this->sku = $sku; $this->data = $data; }
        public function getSku() { return $this->sku; }
        public function getData($key) { return isset($this->data[$key]) ? $this->data[$key] : null; }
    };
}

function items(array $pairs, $field = 'qty')
{
    $out = [];
    foreach ($pairs as $pair) {
        $out[] = item($pair[0], $pair[1], $field);
    }
    return $out;
}

function rzpOrder($fingerprint, $magic = true, $id = 'order_X', $receipt = '000000045')
{
    $notes = [];
    if ($magic) {
        $notes['cart_mask_id'] = 'mask123';
    }
    if ($fingerprint !== null) {
        $notes[FP::NOTE_KEY] = $fingerprint;
    }
    return ['id' => $id, 'receipt' => $receipt, 'notes' => $notes];
}

// ---------------------------------------------------------------- fingerprint
$cart = items([['TSHIRT-RED-M', 2], ['MUG', 1]]);
$fp = FP::compute($cart, 'qty');

check('sha256 hex', preg_match('/^[a-f0-9]{64}$/', $fp) === 1);
check('same cart, same hash', FP::compute(items([['TSHIRT-RED-M', 2], ['MUG', 1]]), 'qty') === $fp);
check('item order does not matter', FP::compute(items([['MUG', 1], ['TSHIRT-RED-M', 2]]), 'qty') === $fp);
check('quote qty and order qty_ordered agree', FP::compute(items([['TSHIRT-RED-M', 2], ['MUG', 1]], 'qty_ordered'), 'qty_ordered') === $fp);
check('added item changes hash', FP::compute(items([['TSHIRT-RED-M', 2], ['MUG', 1], ['TV', 1]]), 'qty') !== $fp);
check('removed item changes hash', FP::compute(items([['TSHIRT-RED-M', 2]]), 'qty') !== $fp);
check('qty change changes hash', FP::compute(items([['TSHIRT-RED-M', 3], ['MUG', 1]]), 'qty') !== $fp);
check('variant swap (child SKU) changes hash', FP::compute(items([['TSHIRT-BLUE-M', 2], ['MUG', 1]]), 'qty') !== $fp);
check('same SKU on two lines is summed', FP::compute(items([['MUG', 1], ['MUG', 1]]), 'qty') === FP::compute(items([['MUG', 2]]), 'qty'));
check('decimal qty matches string and float', FP::compute(items([['RICE', '1.5000']]), 'qty') === FP::compute(items([['RICE', 1.5]]), 'qty'));
check('decimal qty difference detected', FP::compute(items([['RICE', 1.5]]), 'qty') !== FP::compute(items([['RICE', 1.4]]), 'qty'));
check('numeric SKUs, any order', FP::compute(items([['100', 1], ['20', 1]]), 'qty') === FP::compute(items([['20', 1], ['100', 1]]), 'qty'));
check('numeric SKU differs from padded SKU', FP::compute(items([['100', 1]]), 'qty') !== FP::compute(items([['0100', 1]]), 'qty'));
check('SKU text cannot imitate another line', FP::compute(items([['a";i:1;}', 1]]), 'qty') !== FP::compute(items([['a', 1]]), 'qty'));
check('invalid UTF-8 SKU hashes and is distinct', FP::compute(items([["SKU\xB1", 1]]), 'qty') !== FP::compute(items([["SKU\xB2", 1]]), 'qty'));
check('empty cart hashes', preg_match('/^[a-f0-9]{64}$/', FP::compute([], 'qty')) === 1);

// ---------------------------------------------------------------- compare / notes
check('match', FP::compare(rzpOrder($fp), $cart, 'qty') === FP::MATCH);
check('mismatch', FP::compare(rzpOrder($fp), items([['TSHIRT-RED-M', 5], ['MUG', 1]]), 'qty') === FP::MISMATCH);
check('not Magic (no cart_mask_id)', FP::compare(rzpOrder($fp, false), $cart, 'qty') === FP::NOT_MAGIC);
check('Magic without fingerprint is unknown', FP::compare(rzpOrder(null), $cart, 'qty') === FP::UNKNOWN);
check('empty fingerprint is unknown', FP::compare(rzpOrder(''), $cart, 'qty') === FP::UNKNOWN);
check('malformed fingerprint is unknown', FP::compare(rzpOrder('abc'), $cart, 'qty') === FP::UNKNOWN);
check('uppercase fingerprint is unknown', FP::compare(rzpOrder(strtoupper($fp)), $cart, 'qty') === FP::UNKNOWN);
check('notes as object', FP::compare((object)['notes' => (object)['cart_mask_id' => 'm', 'cart_fingerprint' => $fp]], $cart, 'qty') === FP::MATCH);
check('notes as ArrayAccess', FP::compare(new ArrayObject(['notes' => new ArrayObject(['cart_mask_id' => 'm', 'cart_fingerprint' => $fp])]), $cart, 'qty') === FP::MATCH);
check('empty notes array (Razorpay returns [])', FP::compare(['notes' => []], $cart, 'qty') === FP::NOT_MAGIC);
check('non-scalar note ignored', FP::readNote(['notes' => ['cart_mask_id' => ['x']]], 'cart_mask_id') === null);

// ---------------------------------------------------------------- CartGuard
function logger()
{
    return new class implements \Psr\Log\LoggerInterface {
        public $lines = [];
        public function emergency($m, array $c = array()) { $this->lines[] = $m; }
        public function alert($m, array $c = array()) { $this->lines[] = $m; }
        public function critical($m, array $c = array()) { $this->lines[] = $m; }
        public function error($m, array $c = array()) { $this->lines[] = $m; }
        public function warning($m, array $c = array()) { $this->lines[] = $m; }
        public function notice($m, array $c = array()) { $this->lines[] = $m; }
        public function info($m, array $c = array()) { $this->lines[] = $m; }
        public function debug($m, array $c = array()) { $this->lines[] = $m; }
        public function log($l, $m, array $c = array()) { $this->lines[] = $m; }
    };
}

function guard($enabled, $logger = null)
{
    $config = new class($enabled) extends \Razorpay\Magento\Model\Config {
        private $enabled;
        public function __construct($enabled) { $this->enabled = $enabled; }
        public function isMagicCartGuardEnabled() { return $this->enabled; }
    };
    return new CartGuard($config, $logger ?: logger());
}

/** Shared event log, to assert ordering across doubles. */
$events = [];

function orderLink($status = 0, $failSave = false, $id = 10)
{
    return new class($status, $failSave, $id) {
        public $status; public $paymentId; private $failSave; private $id;
        public function __construct($s, $f, $id) { $this->status = $s; $this->failSave = $f; $this->id = $id; }
        public function getId() { return $this->id; }
        public function getRzpUpdateOrderCronStatus() { return $this->status; }
        public function setRzpUpdateOrderCronStatus($s) { $this->status = $s; return $this; }
        public function setRzpPaymentId($p) { $this->paymentId = $p; return $this; }
        public function save() {
            global $events;
            if ($this->failSave) { throw new \Exception('db down'); }
            $events[] = 'link_saved:' . $this->status;
            return $this;
        }
    };
}

function rzpApi($amount = 50000, $refunded = 0, $refundThrows = false)
{
    $payment = new class($amount, $refunded, $refundThrows) extends ArrayObject {
        public $refundArgs;
        private $throws;
        public function __construct($a, $r, $t) { parent::__construct(['amount' => $a, 'amount_refunded' => $r]); $this->throws = $t; }
        public function refund($args) {
            global $events;
            if ($this->throws) { throw new \Exception('refund api down'); }
            $events[] = 'refund:' . $args['amount'];
            $this->refundArgs = $args;
            return $this;
        }
    };
    $api = new stdClass();
    $api->payment = new class($payment) {
        public $p; public $fetched = 0;
        public function __construct($p) { $this->p = $p; }
        public function fetch($id) { $this->fetched++; return $this->p; }
    };
    return $api;
}

function order($items, $canCancel = true)
{
    return new class($items, $canCancel) {
        public $cancelled = false; public $comments = []; private $items; private $canCancel;
        public function __construct($i, $c) { $this->items = $i; $this->canCancel = $c; }
        public function getId() { return 7; }
        public function getIncrementId() { return '000000045'; }
        public function getAllVisibleItems() { return $this->items; }
        public function canCancel() { return $this->canCancel; }
        public function cancel() { global $events; $events[] = 'cancel'; $this->cancelled = true; return $this; }
        public function addStatusHistoryComment($c) { $this->comments[] = $c; return $this; }
        public function save() { return $this; }
    };
}

function quote($items)
{
    return new class($items) {
        private $items; public $read = 0;
        public function __construct($i) { $this->items = $i; }
        public function getAllVisibleItems() { $this->read++; return $this->items; }
    };
}

$captured = ['id' => 'pay_1', 'method' => 'upi', 'status' => 'captured'];
$changed = items([['TSHIRT-RED-M', 2], ['MUG', 1], ['TV', 1]]);

// checks
check('quote mismatch detected', guard(true)->quoteMismatch(rzpOrder($fp), quote($changed)) === true);
check('quote match passes', guard(true)->quoteMismatch(rzpOrder($fp), quote($cart)) === false);
check('order mismatch uses qty_ordered', guard(true)->orderMismatch(rzpOrder($fp), order(items([['TSHIRT-RED-M', 3], ['MUG', 1]], 'qty_ordered'))) === true);
check('order match uses qty_ordered', guard(true)->orderMismatch(rzpOrder($fp), order(items([['TSHIRT-RED-M', 2], ['MUG', 1]], 'qty_ordered'))) === false);
$q = quote($changed);
check('flag off: never a mismatch', guard(false)->quoteMismatch(rzpOrder($fp), $q) === false);
check('flag off: items not read', $q->read === 0);
$q = quote($changed);
check('not Magic: never a mismatch', guard(true)->quoteMismatch(rzpOrder($fp, false), $q) === false);
check('not Magic: items not read', $q->read === 0);
check('no fingerprint: passes', guard(true)->quoteMismatch(rzpOrder(null), quote($changed)) === false);

// isRejected
check('isRejected: status 7', guard(true)->isRejected(orderLink(OrderCronStatus::ORDER_ITEM_MISMATCH_REJECTED)) === true);
check('isRejected: also with flag off', guard(false)->isRejected(orderLink(OrderCronStatus::ORDER_ITEM_MISMATCH_REJECTED)) === true);
check('isRejected: other status', guard(true)->isRejected(orderLink(OrderCronStatus::PAYMENT_AUTHORIZED_COMPLETED)) === false);
check('isRejected: null link', guard(true)->isRejected(null) === false);
check('isRejected: link not found (no id)', guard(true)->isRejected(orderLink(7, false, null)) === false);

// reject: captured payment, no existing order
$events = [];
$link = orderLink();
$api = rzpApi(50000);
guard(true)->reject($api, $link, rzpOrder($fp), $captured, null, 'test');
check('reject: status 7 saved', $link->status === OrderCronStatus::ORDER_ITEM_MISMATCH_REJECTED);
check('reject: payment id stored on the link', $link->paymentId === 'pay_1');
check('reject: status saved before refund', $events === ['link_saved:7', 'refund:50000']);
check('reject: refund receipt and notes', $api->payment->p->refundArgs['receipt'] === '000000045'
    && $api->payment->p->refundArgs['notes']['refund_from_magic'] === true);

// partly refunded already: refunds the remainder only
$events = [];
guard(true)->reject(rzpApi(50000, 20000), orderLink(), rzpOrder($fp), $captured, null, 'test');
check('reject: refunds only the remaining amount', $events === ['link_saved:7', 'refund:30000']);

// fully refunded already
$events = [];
guard(true)->reject(rzpApi(50000, 50000), orderLink(), rzpOrder($fp), $captured, null, 'test');
check('reject: nothing left to refund', $events === ['link_saved:7']);

// COD
$events = [];
$api = rzpApi();
guard(true)->reject($api, orderLink(), rzpOrder($fp), ['id' => 'pay_2', 'method' => 'cod', 'status' => 'pending'], null, 'test');
check('reject: COD is not refunded', $events === ['link_saved:7'] && $api->payment->fetched === 0);

// authorized (authorize mode or not captured yet)
$events = [];
$api = rzpApi();
$order = order($changed);
guard(true)->reject($api, orderLink(), rzpOrder($fp), ['id' => 'pay_3', 'method' => 'card', 'status' => 'authorized'], $order, 'test');
check('reject: uncaptured payment is not refunded', $api->payment->fetched === 0 && !in_array('refund:50000', $events, true));
check('reject: comment says do not capture', strpos($order->comments[0], 'Do not capture it') !== false);

// status save fails: no refund
$events = [];
$logger = logger();
$api = rzpApi();
guard(true, $logger)->reject($api, orderLink(0, true), rzpOrder($fp), $captured, null, 'test');
check('reject: no refund when the rejection was not saved', $api->payment->fetched === 0 && $events === []);
check('reject: that case is logged', count(array_filter($logger->lines, function ($l) { return strpos($l, 'not refunded because the rejection was not saved') !== false; })) === 1);

// no link row at all: no refund
$events = [];
$api = rzpApi();
guard(true)->reject($api, null, rzpOrder($fp), $captured, null, 'test');
check('reject: no link row means no refund', $api->payment->fetched === 0);

// refund API fails: logged, never throws, comment tells admin
$events = [];
$order = order($changed);
$threw = false;
try {
    guard(true)->reject(rzpApi(50000, 0, true), orderLink(), rzpOrder($fp), $captured, $order, 'test');
} catch (\Exception $e) {
    $threw = true;
}
check('reject: refund failure does not throw', $threw === false);
check('reject: refund failure noted on the order', strpos($order->comments[0], 'Automatic refund failed') !== false);

// existing order: cancelled after refund, with comment
$events = [];
$order = order($changed);
guard(true)->reject(rzpApi(), orderLink(), rzpOrder($fp), $captured, $order, 'test');
check('reject: existing order cancelled after the refund', $events === ['link_saved:7', 'refund:50000', 'cancel']);
check('reject: comment mentions refund', strpos($order->comments[0], 'Payment refunded.') !== false
    && strpos($order->comments[0], 'Order cancelled.') !== false);

// existing order that cannot be cancelled
$order = order($changed, false);
guard(true)->reject(rzpApi(), orderLink(), rzpOrder($fp), $captured, $order, 'test');
check('reject: order not cancellable is flagged for review', $order->cancelled === false
    && strpos($order->comments[0], 'could not be cancelled automatically') !== false);

// refunded / failed payments: no refund call, accurate comment
foreach (['refunded' => 'Payment was already refunded.', 'failed' => 'Payment failed, nothing to refund.'] as $status => $note) {
    $api = rzpApi();
    $order = order($changed);
    guard(true)->reject($api, orderLink(), rzpOrder($fp), ['id' => 'pay_4', 'method' => 'card', 'status' => $status], $order, 'test');
    check("reject: $status payment is not refunded", $api->payment->fetched === 0);
    check("reject: $status comment", strpos($order->comments[0], $note) !== false && strpos($order->comments[0], 'Do not capture') === false);
}

echo $count - $failures . "/$count passed\n";
exit($failures === 0 ? 0 : 1);
