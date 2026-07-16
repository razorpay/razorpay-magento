<?php

declare(strict_types=1);

namespace Razorpay\Magento\Test\Integration\Controller\Payment;

use Magento\Framework\Registry;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use Razorpay\Magento\Constants\OrderCronStatus;
use Razorpay\Magento\Controller\Payment\Webhook;
use Razorpay\Magento\Model\OrderLink;

/**
 * Covers the real webhook -> order-status-transition data flow: a webhook
 * payload persists rzp_webhook_data / rzp_webhook_notified_at on the real
 * razorpay_sales_order row, which Cron/UpdateOrdersToProcessingV2 later reads
 * to move the order to "processing". Webhook::execute() itself reads
 * php://input and calls header()/exit() on signature failure, so this test
 * exercises the protected persistence methods directly against a real DB,
 * the same way Test/Unit/Controller/Payment/WebhookTest.php does with mocks --
 * the difference here is every read/write actually round-trips through MySQL.
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation disabled
 */
class WebhookTest extends TestCase
{
    private \Magento\Framework\ObjectManagerInterface $objectManager;
    private OrderRepositoryInterface $orderRepository;
    private Webhook $webhook;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->orderRepository = $this->objectManager->get(OrderRepositoryInterface::class);
        $this->webhook = $this->objectManager->create(Webhook::class);
    }

    private function invokeProtected(string $method, array $args)
    {
        $reflection = new \ReflectionMethod(Webhook::class, $method);
        return $reflection->invokeArgs($this->webhook, $args);
    }

    private function loadFixtureOrder(): Order
    {
        $registry = $this->objectManager->get(Registry::class);
        $order = $registry->registry('_fixture/Razorpay_Magento_Order_With_Payment');
        return $this->objectManager->create(Order::class)->load($order->getEntityId());
    }

    private function loadOrderLink(int $orderId): OrderLink
    {
        return $this->objectManager->create(OrderLink::class)
            ->getCollection()
            ->addFilter('order_id', $orderId)
            ->getFirstItem();
    }

    /**
     * @magentoDataFixture Razorpay_Magento::Test/Integration/_files/order_with_razorpay_payment.php
     */
    public function testWebhookReceivedPersistsDataAndCronThenTransitionsOrderToProcessing(): void
    {
        $order = $this->loadFixtureOrder();
        $orderLinkBefore = $this->loadOrderLink($order->getEntityId());
        $rzpOrderId = $orderLinkBefore->getRzpOrderId();
        $this->assertNotEmpty($rzpOrderId, 'Fixture must have created a real rzp_order_id row.');

        $post = [
            'event' => 'order.paid',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_INTEGRATIONTEST1',
                        'order_id' => $rzpOrderId,
                        'notes' => ['merchant_order_id' => $order->getIncrementId()],
                        'amount' => 10000,
                    ],
                ],
                'order' => [
                    'entity' => ['amount_paid' => 10000],
                ],
            ],
        ];

        // Step 1: webhook receipt persists the payload against the real
        // razorpay_sales_order row.
        $this->invokeProtected('setWebhookData', [
            $post,
            $order->getEntityId(),
            true,
            'pay_INTEGRATIONTEST1',
            10000,
        ]);
        $this->invokeProtected('setWebhookNotifiedAt', [$order->getEntityId(), $post]);

        $orderLinkAfterWebhook = $this->loadOrderLink($order->getEntityId());
        $this->assertNotNull(
            $orderLinkAfterWebhook->getRzpWebhookNotifiedAt(),
            'Webhook receipt must stamp rzp_webhook_notified_at on the real row.'
        );

        $webhookData = unserialize($orderLinkAfterWebhook->getRzpWebhookData());
        $this->assertSame('pay_INTEGRATIONTEST1', $webhookData['order.paid']['payment_id']);
        $this->assertSame(10000, $webhookData['order.paid']['amount']);

        // Step 2: the order itself is still untouched at this point --
        // Webhook::execute() only persists data, it never mutates order state
        // directly. Cron/UpdateOrdersToProcessingV2 is what reads this data
        // and performs the actual state transition (covered by unit tests
        // with mocked collaborators; this integration test asserts the real
        // DB write this cron depends on actually happened).
        $reloadedOrder = $this->orderRepository->get($order->getEntityId());
        $this->assertSame('pending', $reloadedOrder->getStatus());
    }

    /**
     * @magentoDataFixture Razorpay_Magento::Test/Integration/_files/order_with_razorpay_payment.php
     */
    public function testWebhookDataIsNotOverwrittenWhenSameEventReplayed(): void
    {
        $order = $this->loadFixtureOrder();
        $orderLink = $this->loadOrderLink($order->getEntityId());
        $rzpOrderId = $orderLink->getRzpOrderId();

        $firstPost = [
            'event' => 'payment.authorized',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_FIRST',
                        'order_id' => $rzpOrderId,
                        'amount' => 10000,
                    ],
                ],
            ],
        ];
        $this->invokeProtected('setWebhookData', [$firstPost, $order->getEntityId(), true, 'pay_FIRST', 10000]);

        // Replay of the same event with a different payment id -- must not
        // clobber the already-recorded entry in the real DB row.
        $replayPost = $firstPost;
        $replayPost['payload']['payment']['entity']['id'] = 'pay_REPLAY';
        $this->invokeProtected('setWebhookData', [$replayPost, $order->getEntityId(), true, 'pay_REPLAY', 99999]);

        $orderLinkAfter = $this->loadOrderLink($order->getEntityId());
        $webhookData = unserialize($orderLinkAfter->getRzpWebhookData());
        $this->assertSame('pay_FIRST', $webhookData['payment.authorized']['payment_id']);
    }

    /**
     * @magentoDataFixture Razorpay_Magento::Test/Integration/_files/order_with_razorpay_payment.php
     */
    public function testOrderPaidAfterManualCaptureTransitionsCronStatusInRealRow(): void
    {
        $order = $this->loadFixtureOrder();
        $orderLink = $this->loadOrderLink($order->getEntityId());
        $rzpOrderId = $orderLink->getRzpOrderId();

        $orderLink->setRzpUpdateOrderCronStatus(OrderCronStatus::PAYMENT_AUTHORIZED_CRON_REPEAT)->save();

        $post = [
            'event' => 'order.paid',
            'payload' => [
                'payment' => [
                    'entity' => ['id' => 'pay_MANUALCAPTURE', 'order_id' => $rzpOrderId, 'amount' => 10000],
                ],
                'order' => ['entity' => ['amount_paid' => 10000]],
            ],
        ];
        $this->invokeProtected('setWebhookData', [$post, $order->getEntityId(), true, 'pay_MANUALCAPTURE', 10000]);

        $orderLinkAfter = $this->loadOrderLink($order->getEntityId());
        $this->assertEquals(
            OrderCronStatus::ORDER_PAID_AFTER_MANUAL_CAPTURE,
            $orderLinkAfter->getRzpUpdateOrderCronStatus()
        );
    }
}
