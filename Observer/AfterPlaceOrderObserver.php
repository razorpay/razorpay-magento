<?php

namespace Razorpay\Magento\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Payment;
use Razorpay\Magento\Model\PaymentMethod;

/**
 * Class AfterPlaceOrderObserver
 * @package PayU\PaymentGateway\Observer
 */
class AfterPlaceOrderObserver implements ObserverInterface
{
    /**
     * Status pending
     */
    const STATUS_PENDING_PAYMENT = 'pending_payment';

    /**
     * Store key
     */
    const STORE = 'store';

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var AfterPlaceOrderRepayEmailProcessor
     */
    private $emailProcessor;

    /**
     * StatusAssignObserver constructor.
     *
     * @param OrderRepositoryInterface $orderRepository
     * @param AfterPlaceOrderRepayEmailProcessor $emailProcessor
     */
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        \Magento\Checkout\Model\Session $checkoutSession
    ) {
        $this->orderRepository = $orderRepository;
        $this->checkoutSession = $checkoutSession;
    }

    /**
     * {@inheritdoc}
     */
    public function execute(Observer $observer)
    { 
        /** @var Payment $payment */
        $payment = $observer->getData('payment');

        $pay_method = $payment->getMethodInstance();

        $code = $pay_method->getCode();

        if($code === PaymentMethod::METHOD_CODE)
        {
            $this->assignStatus($payment);
            $this->checkoutSession->setRazorpayMailSentOnSuccess(false);
        }
        
    }

    /**
     * @param Payment $payment
     *
     * @return void
     */
    private function assignStatus(Payment $payment)
    {
        $order = $payment->getOrder();
   
        $order->setState(static::STATUS_PENDING_PAYMENT)
              ->setStatus(static::STATUS_PENDING_PAYMENT);
        $this->orderRepository->save($order);
    }

}
