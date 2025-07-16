<?php

namespace Razorpay\Magento\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Payment;
use Razorpay\Magento\Model\PaymentMethod;
use Razorpay\Magento\Model\TrackPluginInstrumentation;

/**
 * Class AfterPlaceOrderObserver
 * @package PayU\PaymentGateway\Observer
 */
class AfterPlaceOrderObserver implements ObserverInterface
{

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
     * @var \Magento\Checkout\Model\Session
     */
    protected $checkoutSession;

    /**
     * @var \Razorpay\Magento\Model\Config
     */
    protected $config;

    /**
     * @var \Razorpay\Magento\Model\TrackPluginInstrumentation
     */
    protected $trackPluginInstrumentation;
    
    /**
     * StatusAssignObserver constructor.
     *
     * @param OrderRepositoryInterface $orderRepository
     * @param AfterPlaceOrderRepayEmailProcessor $emailProcessor
     */
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Razorpay\Magento\Model\Config $config,
        TrackPluginInstrumentation $trackPluginInstrumentation
    ) {
        $this->orderRepository = $orderRepository;
        $this->checkoutSession = $checkoutSession;
        $this->config = $config;
        $this->trackPluginInstrumentation = $trackPluginInstrumentation;
    }

    /**
     * {@inheritdoc}
     */
    public function execute(Observer $observer)
    { 
        try {
            /** @var Payment $payment */
            $payment = $observer->getData('payment');

            $pay_method = $payment->getMethodInstance();

            $code = $pay_method->getCode();

            if($code === PaymentMethod::METHOD_CODE)
            {
                $this->assignStatus($payment);
                $this->checkoutSession->setRazorpayMailSentOnSuccess(false);
            }
        } catch (\Exception $e) {

            $properties = [
                "error_message" => $e->getMessage(),
                "file_path" => "observer/AfterPlaceOrderObserver.php",
                "exception_type" => get_class($e),
                "notes" => "observer: execute after place order failed",
            ];

            $this->trackPluginInstrumentation->rzpTrackDataLake('razorpay.std.observer.afterplaceorder.failed', $properties);
        }
        
    }

    /**
     * @param Payment $payment
     *
     * @return void
     */
    private function assignStatus(Payment $payment)
    {
        try {
            $order = $payment->getOrder();

            $new_order_status = $this->config->getNewOrderStatus();

            $order->setState('new')
                ->setStatus($new_order_status);

            $this->orderRepository->save($order);

            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

            $lastQuoteId = $order->getQuoteId();
            $quote = $objectManager->get('Magento\Quote\Model\Quote')->load($lastQuoteId);
            $quote->setIsActive(true)->save();
            $this->checkoutSession->replaceQuote($quote);
        } catch (\Exception $e) {

            $properties = [
                "error_message" => $e->getMessage(),
                "file_path" => "observer/AfterPlaceOrderObserver.php",
                "exception_type" => get_class($e),
                "notes" => "observer: assign status failed",
            ];

            $this->trackPluginInstrumentation->rzpTrackDataLake('razorpay.std.observer.afterplaceorder.failed', $properties);
        }
    }

}
