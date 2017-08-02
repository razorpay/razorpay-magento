<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Razorpay\Magento\Observer;

use Razorpay\Magento\Model\PaymentMethod;
use Magento\Framework\Event\ObserverInterface;

class ProcessRazorpayPayment implements ObserverInterface
{
    const CONFIG_PATH_CAPTURE_ACTION    = 'capture_action';
    const CONFIG_PATH_PAYMENT_ACTION    = 'payment_action';

    /**
     * @var \Magento\Braintree\Model\Config\Cc
     */
    protected $config;

    /**
     * @var \Magento\Framework\DB\TransactionFactory
     */
    protected $transactionFactory;

    /**
     * @param Razorpay\Magento\Model\Config\Cc $config
     * @param \Magento\Framework\DB\TransactionFactory $transactionFactory
     */
    public function __construct(
        \Magento\Braintree\Model\Config\Cc $config,
        \Magento\Framework\DB\TransactionFactory $transactionFactory
    ) 
    {
        $this->config = $config;
        $this->transactionFactory = $transactionFactory;
    }

    /**
     * If it's configured to capture on shipment - do this
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return $this
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $shipment = $observer->getEvent()->getShipment();
        $order = $shipment->getOrder();

        if ($order->getPayment()->getMethod() == PaymentMethod::METHOD_CODE
            && $order->canInvoice()
            && $this->shouldInvoice()
        ) 
        {
            $qtys = [];
            foreach ($shipment->getAllItems() as $shipmentItem) 
            {
                $qtys[$shipmentItem->getOrderItem()->getId()] = $shipmentItem->getQty();
            }

            foreach ($order->getAllItems() as $orderItem) 
            {
                if (!array_key_exists($orderItem->getId(), $qtys)) 
                {
                    $qtys[$orderItem->getId()] = 0;
                }
            }

            $invoice = $order->prepareInvoice($qtys);
            $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
            $invoice->register();
            /** @var \Magento\Framework\DB\Transaction $transaction */
            $transaction = $this->transactionFactory->create();
            $transaction->addObject($invoice)
                ->addObject($invoice->getOrder())
                ->save();
        }

        return $this;
    }

    /**
     * If it's configured to capture on each shipment
     *
     * @return bool
     */
    private function shouldInvoice()
    {
        $flag = (($this->config->getConfigData(self::CONFIG_PATH_PAYMENT_ACTION) ==
                \Magento\Payment\Model\Method\AbstractMethod::ACTION_AUTHORIZE) &&
            ($this->config->getConfigData(self::CONFIG_PATH_CAPTURE_ACTION) ==
                PaymentMethod::CAPTURE_ON_SHIPMENT));

        return $flag;
    }
}
