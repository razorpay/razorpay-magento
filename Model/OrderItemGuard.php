<?php

namespace Razorpay\Magento\Model;

use Razorpay\Magento\Constants\OrderCronStatus;
use Razorpay\Magento\Model\Util\CartFingerprint;

/**
 * Compares the placed order's items with the cart fingerprint stored on the Razorpay order (PLUG-02).
 */
class OrderItemGuard
{
    const RESULT_PASS = 'pass';
    const RESULT_FLAGGED = 'flagged';
    const RESULT_HELD = 'held';

    const EVENT_NAME = 'Magic Order Item Mismatch';

    protected $config;
    protected $trackPluginInstrumentation;
    protected $logger;

    public function __construct(
        Config $config,
        TrackPluginInstrumentation $trackPluginInstrumentation,
        \Psr\Log\LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->trackPluginInstrumentation = $trackPluginInstrumentation;
        $this->logger = $logger;
    }

    public function isAlreadyFlagged($orderLink): bool
    {
        return (int)$orderLink->getRzpUpdateOrderCronStatus() === OrderCronStatus::ORDER_ITEMS_MISMATCH;
    }

    /**
     * Call after the Magento order exists and before it is marked paid/processing.
     * On mismatch: adds a history comment, sends a datalake event, marks the order link and,
     * only if the hold flag is on, puts the order on hold (caller must then skip processing).
     * The caller saves the order; the order link status is set here (caller saves the link).
     *
     * @return string RESULT_PASS | RESULT_FLAGGED | RESULT_HELD
     */
    public function check($order, $rzpOrderData, $orderLink, $paymentMethod = null): string
    {
        $expected = $rzpOrderData->notes->{CartFingerprint::NOTE_KEY} ?? null;

        if (empty($expected) === true) {
            $this->logger->info('Magic item fingerprint not present on rzp order '
                . ($rzpOrderData->id ?? '') . ', skipping item check');
            return self::RESULT_PASS;
        }

        $items = $order->getAllVisibleItems();

        if (CartFingerprint::matches((string)$expected, $items)) {
            return self::RESULT_PASS;
        }

        $hold = $this->config->isItemMismatchHoldEnabled();

        $this->logger->critical('Magic order items differ from paid cart for order '
            . $order->getIncrementId() . ' rzp order ' . ($rzpOrderData->id ?? '') . ' hold=' . (int)$hold);

        $order->addStatusHistoryComment(__('Magic: order items differ from what was paid for'));

        $orderLink->setRzpUpdateOrderCronStatus(OrderCronStatus::ORDER_ITEMS_MISMATCH);

        if ($hold) {
            if ($order->canHold()) {
                $order->hold();
            } else {
                $order->setState(\Magento\Sales\Model\Order::STATE_HOLDED)
                    ->setStatus(\Magento\Sales\Model\Order::STATE_HOLDED);
            }
        }

        $this->trackPluginInstrumentation->rzpTrackDataLake(self::EVENT_NAME, [
            'rzp_order_id'      => $rzpOrderData->id ?? '',
            'magento_order_id'  => $order->getIncrementId(),
            'payment_method'    => $paymentMethod,
            'expected_hash'     => (string)$expected,
            'actual_lines'      => CartFingerprint::canonicalLines($items),
            'held'              => $hold,
        ]);

        return $hold ? self::RESULT_HELD : self::RESULT_FLAGGED;
    }
}
