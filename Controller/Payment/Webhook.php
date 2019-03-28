<?php

namespace Razorpay\Magento\Controller\Payment;

class Webhook extends \Razorpay\Magento\Controller\BaseController
{
	protected $checkoutSession;
	/**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Razorpay\Model\CheckoutFactory $checkoutFactory
     * @param \Magento\Razorpay\Model\Config\Payment $razorpayConfig
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Razorpay\Magento\Model\Config $config
    ) 
    {
        parent::__construct(
            $context,
            $customerSession,
            $checkoutSession,
            $config
        );
	    $this->config = $config;
    }
    public function execute()
    {
        echo "hello"."\n\n";
        $request = file_get_contents('php://input');
        $msg = json_encode($request, true);
        echo "Request = ".$msg;
    }
}
