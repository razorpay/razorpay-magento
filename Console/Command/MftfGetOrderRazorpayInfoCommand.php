<?php

declare(strict_types=1);

namespace Razorpay\Magento\Console\Command;

use Magento\Framework\Console\Cli;
use Magento\Sales\Api\Data\OrderInterface;
use Razorpay\Magento\Model\OrderLink;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints "increment_id|rzp_order_id|rzp_webhook_notified_at" for the most
 * recently placed order matching a given customer email, for use only by
 * MFTF functional tests. rzp_webhook_notified_at is empty until a webhook has
 * been received and persisted for that order (see
 * Controller/Payment/Webhook::setWebhookNotifiedAt()).
 *
 * MFTF's <magentoCLI> action captures a command's stdout into a step
 * variable (see Test/Mftf/Test/StorefrontRazorpayCheckoutFlowTest.php), which
 * is otherwise the only way to retrieve the order/rzp_order_id created by the
 * storefront JS flow -- the checkout never navigates to a visible success
 * page for Razorpay's off-site payment flow (Controller/Payment/Order places
 * the order and opens Razorpay's hosted checkout.js modal in the background,
 * without leaving /checkout#payment).
 */
class MftfGetOrderRazorpayInfoCommand extends Command
{
    /**
     * @var OrderInterface
     */
    private $order;

    /**
     * @var OrderLink
     */
    private $orderLink;

    /**
     * @param OrderInterface $order
     * @param OrderLink $orderLink
     */
    public function __construct(OrderInterface $order, OrderLink $orderLink)
    {
        parent::__construct();
        $this->order = $order;
        $this->orderLink = $orderLink;

        $this->setName('razorpay:mftf:get-order-info')
            ->setDescription(
                'MFTF test helper: prints increment_id|rzp_order_id|rzp_webhook_notified_at for the latest order '
                . 'matching a customer email'
            )
            ->addArgument('customer-email', InputArgument::REQUIRED, 'Customer email to look up the latest order for');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $customerEmail = $input->getArgument('customer-email');

        $orderCollection = $this->order->getCollection()
            ->addFieldToSelect(['entity_id', 'increment_id'])
            ->addFieldToFilter('customer_email', $customerEmail)
            ->setOrder('entity_id', 'DESC')
            ->setPageSize(1);

        $matchedOrder = $orderCollection->getFirstItem();

        if (!$matchedOrder->getId()) {
            $output->writeln('<error>No order found for customer-email=' . $customerEmail . '</error>');
            return Cli::RETURN_FAILURE;
        }

        $orderLinkItem = $this->orderLink->getCollection()
            ->addFilter('order_id', $matchedOrder->getEntityId())
            ->getFirstItem();

        if (!$orderLinkItem->getId()) {
            $output->writeln('<error>No razorpay_sales_order row found for order_id=' . $matchedOrder->getEntityId() . '</error>');
            return Cli::RETURN_FAILURE;
        }

        $output->writeln(
            $matchedOrder->getIncrementId()
            . '|' . $orderLinkItem->getRzpOrderId()
            . '|' . (string) $orderLinkItem->getRzpWebhookNotifiedAt()
        );

        return Cli::RETURN_SUCCESS;
    }
}
