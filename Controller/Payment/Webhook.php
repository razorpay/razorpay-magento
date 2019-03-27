<?php

namespace Razorpay\Magento\Controller\Payment;

class Webhook extends \Razorpay\Magento\Controller\BaseController
{
    /** @var \Psr\Log\LoggerInterface  */
    protected $logger;
    /**
    * @param \Magento\Framework\App\Action\Context $context,
    * @param \Psr\Log\LoggerInterface $logger
    */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Razorpay\Magento\Model\Config $config,
        \Psr\Log\LoggerInterface $logger
    ) {
        $this->logger = $logger;
      
        parent::__construct($context,$customerSession,$checkoutSession,$config);
    }
    public function execute()
    {
        $this->logger->addDebug('razorpay/payment/webhook endpoint was hit');
        
        $request = file_get_contents('php://input');
        $msg = json_encode($request, true);
        $this->logger->addDebug("Json Output: ".print_r($msg, true));
    }
}
