<?php

namespace Razorpay\Magento\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\SalesSequence\Model\Manager as SequenceManager;

class CustomQuoteSaveAfter implements ObserverInterface
{
    /**
     * @var SequenceManager
     */
    protected $sequenceManager;

    public function __construct(
        SequenceManager $sequenceManager
    ) {
        $this->sequenceManager = $sequenceManager;
    }

    public function execute(Observer $observer)
    {
        $quote = $observer->getQuote();

        // Check if the quote is new
        if ($quote->getOrigData('entity_id') === null) {

            $sequence = $this->sequenceManager->getSequence(
                \Magento\Sales\Model\Order::ENTITY,
                $quote->getStoreId()
            );

            // Generate a reserved order ID using the sequence
            $reservedOrderId = $sequence->getNextValue();
            $this->logQuoteId($reservedOrderId);

            // Check if the order and quote are available
            if ($reservedOrderId) {
                // Save the order ID in the quote for future reference
                // $quote->setReservedOrderId($reservedOrderId);

            }

            // Example: Log the quote ID
            $this->logQuoteId($quote->getId());
        }
    }

    private function logQuoteId($id)
    {
        $logger = \Magento\Framework\App\ObjectManager::getInstance()->get(\Psr\Log\LoggerInterface::class);
        $logger->info('New ID: ' . $id);
    }
}
