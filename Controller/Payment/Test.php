<?php

namespace Razorpay\Magento\Controller\Payment;
use Razorpay\Magento\Model\Config;

/**
 * CancelPendingOrders controller to cancel Magento order
 *
 * ...
 */
class Test extends \Razorpay\Magento\Controller\BaseController
{
    protected $setup;

    /**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Razorpay\Magento\Model\Config $config
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Razorpay\Magento\Model\Config $config,
        \Psr\Log\LoggerInterface $logger
    ) {
        parent::__construct(
            $context,
            $customerSession,
            $checkoutSession,
            $config
        );
        
        $this->config          = $config;
        $this->checkoutSession = $checkoutSession;
        $this->customerSession = $customerSession;
        $this->logger          = $logger;
    }
    public function execute()
    {
       // echo 'hi';
       $params = $this->getRequest()->getParams();
        print_r($params); 
        
    }

}