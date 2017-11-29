<?php

namespace Razorpay\Magento\Controller\Payment;

use Razorpay\Magento\Model\PaymentMethod;
use Magento\Framework\Controller\ResultFactory;

class Order extends \Razorpay\Magento\Controller\BaseController
{
	protected $quote;

	protected $logger;

	protected $checkoutSession;

	protected $_currency = PaymentMethod::CURRENCY;

    /**
     * Order constructor.
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Razorpay\Magento\Model\CheckoutFactory $checkoutFactory
     * @param \Razorpay\Magento\Model\Config $config
     * @param \Magento\Catalog\Model\Session $catalogSession
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Razorpay\Magento\Model\CheckoutFactory $checkoutFactory,
        \Razorpay\Magento\Model\Config $config,
        \Magento\Catalog\Model\Session $catalogSession,
        \Psr\Log\LoggerInterface $logger
    ) {
        parent::__construct(
            $context,
            $customerSession,
            $checkoutSession,
            $config
        );

        $this->logger = $logger;
        $this->checkoutFactory = $checkoutFactory;
    }

    public function execute()
    {
        $magentoOrder = $this->checkoutSession->getLastRealOrder();

        // Order amount has to be in INR, and base currenct should be in INR
        $amount = (int) (round($magentoOrder->getBaseGrandTotal() * 100, 2));

        $orderId = $magentoOrder->getIncrementId();

        $code = 400;

        try
        {
            $order = $this->rzp->order->create([
                'amount' => $amount,
                'receipt' => $orderId,
                'currency' => $this->_currency,
                'payment_capture' => 1                 // auto-capture
            ]);

            $responseContent = [
                'message'   => 'Unable to create your order. Please contact support.',
                'parameters' => []
            ];

            if (null !== $order && !empty($order->id))
            {
                $responseContent = [
                    'success'        => true,
                    'rzp_order'      => $order->id,
                    'order_id'       => $orderId,
                    'amount'         => $order->amount,
                    'quote_currency' => $magentoOrder->getOrderCurrencyCode(),
                    'quote_amount'   => round($magentoOrder->getGrandTotal(), 2)
                ];

                $code = 200;

                $this->checkoutSession->setRazorpayOrderID($order->id);
            }
        }
        catch(\Razorpay\Api\Errors\Error $e)
        {
            $responseContent = [
                'message'   => $e->getMessage(),
                'parameters' => []
            ];
        }
        catch(\Exception $e)
        {
            $responseContent = [
                'message'   => $e->getMessage(),
                'parameters' => []
            ];
        }

        $response = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $response->setData($responseContent);
        $response->setHttpResponseCode($code);

        return $response;
    }

    public function getOrderID()
    {
        return $this->catalogSession->getRazorpayOrderID();
    }
}