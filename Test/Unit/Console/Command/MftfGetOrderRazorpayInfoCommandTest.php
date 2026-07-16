<?php

namespace Razorpay\Magento\Test\Unit\Console\Command;

use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\TestCase;
use Razorpay\Magento\Console\Command\MftfGetOrderRazorpayInfoCommand;
use Razorpay\Magento\Model\OrderLink;
use Symfony\Component\Console\Tester\CommandTester;

class MftfGetOrderRazorpayInfoCommandTest extends TestCase
{
    private OrderInterface $order;
    private OrderLink $orderLink;

    protected function setUp(): void
    {
        $this->order = $this->getMockBuilder(OrderInterface::class)
            ->addMethods(['getCollection'])
            ->getMockForAbstractClass();

        $this->orderLink = $this->getMockBuilder(OrderLink::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCollection'])
            ->getMock();
    }

    private function mockOrderCollection(?OrderInterface $matchedOrder): void
    {
        $collection = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['addFieldToSelect', 'addFieldToFilter', 'setOrder', 'setPageSize', 'getFirstItem'])
            ->getMock();
        $collection->method('addFieldToSelect')->willReturnSelf();
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($matchedOrder);

        $this->order->method('getCollection')->willReturn($collection);
    }

    private function mockOrderLinkCollection(?OrderLink $matchedOrderLink): void
    {
        $collection = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['addFilter', 'getFirstItem'])
            ->getMock();
        $collection->method('addFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($matchedOrderLink);

        $this->orderLink->method('getCollection')->willReturn($collection);
    }

    private function mockMatchedOrder(int $entityId, string $incrementId): OrderInterface
    {
        $matchedOrder = $this->getMockBuilder(OrderInterface::class)
            ->addMethods(['getId'])
            ->onlyMethods(['getEntityId', 'getIncrementId'])
            ->getMockForAbstractClass();
        $matchedOrder->method('getId')->willReturn($entityId);
        $matchedOrder->method('getEntityId')->willReturn($entityId);
        $matchedOrder->method('getIncrementId')->willReturn($incrementId);

        return $matchedOrder;
    }

    private function mockMatchedOrderLink(string $rzpOrderId, ?int $webhookNotifiedAt = null): OrderLink
    {
        $matchedOrderLink = $this->getMockBuilder(OrderLink::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->addMethods(['getRzpOrderId', 'getRzpWebhookNotifiedAt'])
            ->getMock();
        $matchedOrderLink->method('getId')->willReturn(10);
        $matchedOrderLink->method('getRzpOrderId')->willReturn($rzpOrderId);
        $matchedOrderLink->method('getRzpWebhookNotifiedAt')->willReturn($webhookNotifiedAt);

        return $matchedOrderLink;
    }

    public function testExecuteWhenOrderAndOrderLinkFoundThenPrintsIncrementIdAndRzpOrderId(): void
    {
        $matchedOrder = $this->mockMatchedOrder(42, '000000042');
        $matchedOrderLink = $this->mockMatchedOrderLink('order_MFTF1', 1700000000);

        $this->mockOrderCollection($matchedOrder);
        $this->mockOrderLinkCollection($matchedOrderLink);

        $command = new MftfGetOrderRazorpayInfoCommand($this->order, $this->orderLink);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['customer-email' => 'test@example.com']);

        $this->assertSame(0, $exitCode);
        $this->assertSame("000000042|order_MFTF1|1700000000\n", $tester->getDisplay());
    }

    public function testExecuteWhenNoOrderFoundThenReturnsFailureExitCode(): void
    {
        $noOrder = $this->getMockBuilder(OrderInterface::class)
            ->addMethods(['getId'])
            ->getMockForAbstractClass();
        $noOrder->method('getId')->willReturn(null);

        $this->mockOrderCollection($noOrder);

        $command = new MftfGetOrderRazorpayInfoCommand($this->order, $this->orderLink);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['customer-email' => 'nobody@example.com']);

        $this->assertSame(1, $exitCode);
    }

    public function testExecuteWhenOrderFoundButNoOrderLinkThenReturnsFailureExitCode(): void
    {
        $matchedOrder = $this->mockMatchedOrder(42, '000000042');
        $this->mockOrderCollection($matchedOrder);

        $noOrderLink = $this->getMockBuilder(OrderLink::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $noOrderLink->method('getId')->willReturn(null);
        $this->mockOrderLinkCollection($noOrderLink);

        $command = new MftfGetOrderRazorpayInfoCommand($this->order, $this->orderLink);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['customer-email' => 'test@example.com']);

        $this->assertSame(1, $exitCode);
    }
}
