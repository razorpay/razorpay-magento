<?php

namespace Razorpay\Payments\Controller\Payment;

use Razorpay\Payments\Model\PaymentMethod;
use Magento\Framework\Controller\ResultFactory;

class Order extends \Razorpay\Payments\Controller\BaseController
{
	protected $quote;

	protected $checkoutSession;

	protected $_currency = PaymentMethod::CURRENCY;
	/**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Razorpay\Model\Config\Payment $razorpayConfig
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Razorpay\Payments\Model\CheckoutFactory $checkoutFactory,
        \Razorpay\Payments\Model\Config $config
    ) {
        parent::__construct(
            $context,
            $customerSession,
            $checkoutSession,
            $config
        );

        $this->checkoutFactory = $checkoutFactory;
    }

    public function execute()
    {
        $amount = (int) round($this->getQuote()->getGrandTotal(), 2)*100;

        $receipt_id = $this->getQuote()->getId();

        try
        {
            $order = $this->rzp->order->create([
                'amount' => $amount,
                'receipt' => $receipt_id,
                'currency' => $this->_currency
            ]);

            $responseContent = [
                'success'	=> true,
                'rzp_order' => $order->id,
                'order_id'  => $receipt_id,
                'amount'	=> $order->amount
            ];
        }
        catch(\Razorpay\Api\Errors\Error $e)
        {
            $responseContent = [
                'success'   => false,
                'message'   => $e->getMessage()
            ];
        }
        catch(\Exception $e)
        {
            $responseContent = [
                'success'   => false,
                'message'   => $e->getMessage()
            ];
        }

        $response = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $response->setData($responseContent);

        return $response;
    }
}