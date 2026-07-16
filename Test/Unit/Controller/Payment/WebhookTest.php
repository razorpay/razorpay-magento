<?php

namespace Razorpay\Magento\Test\Unit\Controller\Payment;

use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Razorpay\Magento\Constants\OrderCronStatus;
use Razorpay\Magento\Controller\Payment\Webhook;
use Razorpay\Magento\Model\Config;
use Razorpay\Magento\Model\OrderLink;
use Razorpay\Magento\Model\TrackPluginInstrumentation;
use Razorpay\Magento\Model\Util\DebugUtils;

/**
 * Webhook::execute() itself reads php://input and calls header()/exit() on the
 * signature-failure path, which cannot be exercised in a unit test without
 * refactoring the controller. These tests instead call the protected
 * setWebhookData()/setWebhookNotifiedAt()/getOrderData() methods directly via
 * reflection, since that is where the real business risk lives: persisting
 * webhook state and the serialize()/unserialize() cron-status handoff flagged
 * during the Phase 1 static-analysis pass.
 */
class WebhookTest extends TestCase
{
    private Webhook $webhook;
    private \Magento\Framework\ObjectManagerInterface $objectManagerMock;
    private Order $orderModel;

    protected function setUp(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getConfigData')->willReturn(null);
        $config->method('isCustomPaidOrderStatusEnabled')->willReturn(false);

        $customerSession = $this->createMock(\Magento\Customer\Model\Session::class);
        $checkoutSession = $this->createMock(\Magento\Checkout\Model\Session::class);
        $logger = $this->createMock(LoggerInterface::class);
        $invoiceService = $this->createMock(\Magento\Sales\Model\Service\InvoiceService::class);
        $transaction = $this->createMock(\Magento\Framework\DB\Transaction::class);
        $invoiceSender = $this->createMock(\Magento\Sales\Model\Order\Email\Sender\InvoiceSender::class);
        $orderSender = $this->createMock(\Magento\Sales\Model\Order\Email\Sender\OrderSender::class);
        $debug = $this->createMock(DebugUtils::class);
        $trackPluginInstrumentation = $this->createMock(TrackPluginInstrumentation::class);

        $this->orderModel = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load', 'getEntityId', 'getIncrementId'])
            ->getMock();

        // BaseController::__construct() and Action::__construct() both pull
        // dependencies off Context, so the mock must answer every getter they call.
        $context = $this->createMock(Context::class);
        $context->method('getObjectManager')->willReturn(
            $this->objectManagerMock = $this->createMock(\Magento\Framework\ObjectManagerInterface::class)
        );
        $context->method('getRequest')->willReturn($this->createMock(\Magento\Framework\App\RequestInterface::class));
        $context->method('getResponse')->willReturn($this->createMock(\Magento\Framework\App\ResponseInterface::class));
        $context->method('getMessageManager')->willReturn($this->createMock(\Magento\Framework\Message\ManagerInterface::class));
        $context->method('getRedirect')->willReturn($this->createMock(\Magento\Framework\App\Response\RedirectInterface::class));
        $context->method('getActionFlag')->willReturn($this->createMock(\Magento\Framework\App\ActionFlag::class));
        $context->method('getUrl')->willReturn($this->createMock(\Magento\Framework\UrlInterface::class));
        $context->method('getView')->willReturn($this->createMock(\Magento\Framework\App\ViewInterface::class));
        $context->method('getEventManager')->willReturn($this->createMock(\Magento\Framework\Event\ManagerInterface::class));
        $context->method('getResultRedirectFactory')->willReturn(
            $this->createMock(\Magento\Framework\Controller\Result\RedirectFactory::class)
        );
        $context->method('getResultFactory')->willReturn($this->createMock(\Magento\Framework\Controller\ResultFactory::class));

        // BaseController::__construct() also calls ObjectManager::getInstance()
        // as a fallback path (via the Razorpay Api constructor being real, not
        // mocked) -- prime the static instance defensively.
        ObjectManager::setInstance($this->objectManagerMock);

        $this->webhook = new Webhook(
            $context,
            $customerSession,
            $checkoutSession,
            $config,
            $this->orderModel,
            $logger,
            $invoiceService,
            $transaction,
            $invoiceSender,
            $orderSender,
            $debug,
            $trackPluginInstrumentation
        );
    }

    private function invokeProtected(string $method, array $args)
    {
        $reflection = new \ReflectionMethod(Webhook::class, $method);
        return $reflection->invokeArgs($this->webhook, $args);
    }

    private function mockOrderLinkLookup(?string $orderLinkId, ?string $existingWebhookData, ?int $cronStatus): OrderLink
    {
        $orderLink = $this->getMockBuilder(OrderLink::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'save'])
            ->addMethods([
                'getRzpWebhookData',
                'setOrderId',
                'setRzpWebhookData',
                'getRzpUpdateOrderCronStatus',
                'setRzpUpdateOrderCronStatus',
                'setRzpWebhookNotifiedAt',
            ])
            ->getMock();
        $orderLink->method('getId')->willReturn($orderLinkId);
        $orderLink->method('getRzpWebhookData')->willReturn($existingWebhookData);
        $orderLink->method('getRzpUpdateOrderCronStatus')->willReturn($cronStatus);
        $orderLink->method('setOrderId')->willReturnSelf();
        $orderLink->method('setRzpWebhookData')->willReturnSelf();
        $orderLink->method('setRzpUpdateOrderCronStatus')->willReturnSelf();
        $orderLink->method('setRzpWebhookNotifiedAt')->willReturnSelf();

        $collection = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['addFieldToFilter', 'getFirstItem'])
            ->getMock();
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($orderLink);

        $orderLinkModel = $this->getMockBuilder(OrderLink::class)
            ->disableOriginalConstructor()
            ->getMock();
        $orderLinkModel->method('getCollection')->willReturn($collection);

        $this->objectManagerMock->method('get')
            ->with('Razorpay\Magento\Model\OrderLink')
            ->willReturn($orderLinkModel);

        return $orderLink;
    }

    public function testSetWebhookDataWhenOrderLinkNotFoundThenNothingIsSaved(): void
    {
        $orderLink = $this->mockOrderLinkLookup(orderLinkId: null, existingWebhookData: null, cronStatus: null);
        $orderLink->expects($this->never())->method('save');

        $this->orderModel->method('load')->willReturn($this->orderModel);
        $this->orderModel->method('getEntityId')->willReturn(55);
        $this->orderModel->method('getIncrementId')->willReturn('000000055');

        $post = [
            'event' => 'payment.authorized',
            'payload' => ['payment' => ['entity' => ['order_id' => 'order_ABC']]],
        ];

        $this->invokeProtected('setWebhookData', [$post, 55, true, 'pay_XYZ', 50000]);
    }

    public function testSetWebhookDataWhenNoExistingDataThenNewSerializedPayloadIsSaved(): void
    {
        $orderLink = $this->mockOrderLinkLookup(orderLinkId: '10', existingWebhookData: null, cronStatus: 0);

        $capturedData = null;
        $orderLink->method('setRzpWebhookData')->willReturnCallback(function ($data) use ($orderLink, &$capturedData) {
            $capturedData = $data;
            return $orderLink;
        });
        $orderLink->expects($this->once())->method('save');

        $this->orderModel->method('load')->willReturn($this->orderModel);
        $this->orderModel->method('getEntityId')->willReturn(55);
        $this->orderModel->method('getIncrementId')->willReturn('000000055');

        $post = [
            'event' => 'payment.authorized',
            'payload' => ['payment' => ['entity' => ['order_id' => 'order_ABC', 'amount' => 50000]]],
        ];

        $this->invokeProtected('setWebhookData', [$post, 55, true, 'pay_XYZ', 50000]);

        $decoded = unserialize($capturedData);
        $this->assertSame(50000, $decoded['payment.authorized']['amount']);
        $this->assertSame('pay_XYZ', $decoded['payment.authorized']['payment_id']);
    }

    public function testSetWebhookDataWhenEventAlreadyRecordedThenExistingEntryIsNotOverwritten(): void
    {
        // A replayed/duplicate webhook for an event already stored must not
        // clobber the first recorded payload for that event.
        $existing = serialize([
            'payment.authorized' => ['webhook_verified_status' => true, 'payment_id' => 'pay_FIRST', 'amount' => 12345],
        ]);
        $orderLink = $this->mockOrderLinkLookup(orderLinkId: '10', existingWebhookData: $existing, cronStatus: 0);

        $capturedData = null;
        $orderLink->method('setRzpWebhookData')->willReturnCallback(function ($data) use ($orderLink, &$capturedData) {
            $capturedData = $data;
            return $orderLink;
        });

        $this->orderModel->method('load')->willReturn($this->orderModel);
        $this->orderModel->method('getEntityId')->willReturn(55);
        $this->orderModel->method('getIncrementId')->willReturn('000000055');

        $post = [
            'event' => 'payment.authorized',
            'payload' => ['payment' => ['entity' => ['order_id' => 'order_ABC', 'amount' => 99999]]],
        ];

        $this->invokeProtected('setWebhookData', [$post, 55, true, 'pay_REPLAY', 99999]);

        $decoded = unserialize($capturedData);
        $this->assertSame('pay_FIRST', $decoded['payment.authorized']['payment_id']);
        $this->assertSame(12345, $decoded['payment.authorized']['amount']);
    }

    public function testSetWebhookDataWhenOrderPaidAfterManualCaptureThenCronStatusTransitions(): void
    {
        // order.paid arriving while the cron status is still
        // PAYMENT_AUTHORIZED_CRON_REPEAT means the merchant captured manually
        // between webhooks; the cron status must advance to
        // ORDER_PAID_AFTER_MANUAL_CAPTURE, not silently stay stuck.
        $orderLink = $this->mockOrderLinkLookup(
            orderLinkId: '10',
            existingWebhookData: null,
            cronStatus: OrderCronStatus::PAYMENT_AUTHORIZED_CRON_REPEAT
        );
        $orderLink->expects($this->once())
            ->method('setRzpUpdateOrderCronStatus')
            ->with(OrderCronStatus::ORDER_PAID_AFTER_MANUAL_CAPTURE)
            ->willReturnSelf();

        $this->orderModel->method('load')->willReturn($this->orderModel);
        $this->orderModel->method('getEntityId')->willReturn(55);
        $this->orderModel->method('getIncrementId')->willReturn('000000055');

        $post = [
            'event' => 'order.paid',
            'payload' => [
                'payment' => ['entity' => ['order_id' => 'order_ABC']],
                'order' => ['entity' => ['amount_paid' => 50000]],
            ],
        ];

        $this->invokeProtected('setWebhookData', [$post, 55, true, 'pay_XYZ', 50000]);
    }

    public function testSetWebhookNotifiedAtWhenOrderLinkFoundThenTimestampIsSaved(): void
    {
        $orderLink = $this->mockOrderLinkLookup(orderLinkId: '10', existingWebhookData: null, cronStatus: 0);
        $orderLink->expects($this->once())->method('setRzpWebhookNotifiedAt')->willReturnSelf();
        $orderLink->expects($this->once())->method('save');

        $this->orderModel->method('load')->willReturn($this->orderModel);
        $this->orderModel->method('getEntityId')->willReturn(55);

        $post = ['payload' => ['payment' => ['entity' => ['order_id' => 'order_ABC']]]];

        $this->invokeProtected('setWebhookNotifiedAt', [55, $post]);
    }

    public function testSetWebhookNotifiedAtWhenOrderLinkNotFoundThenNothingIsSaved(): void
    {
        $orderLink = $this->mockOrderLinkLookup(orderLinkId: null, existingWebhookData: null, cronStatus: null);
        $orderLink->expects($this->never())->method('setRzpWebhookNotifiedAt');
        $orderLink->expects($this->never())->method('save');

        $this->orderModel->method('load')->willReturn($this->orderModel);
        $this->orderModel->method('getEntityId')->willReturn(55);

        $post = ['payload' => ['payment' => ['entity' => ['order_id' => 'order_ABC']]]];

        $this->invokeProtected('setWebhookNotifiedAt', [55, $post]);
    }
}
