<?php

namespace Razorpay\Magento\Controller\Payment;

class Webhook extends \Razorpay\Magento\Controller\BaseController
{
    /** @var \Psr\Log\LoggerInterface  */
    protected $logger;
    /**
    * @param \Psr\Log\LoggerInterface $logger
    */
    public function __construct(\Psr\Log\LoggerInterface $logger) {
        $this->logger = $logger;
    }
    public function execute()
    {
        $this->logger->addDebug('razorpay/payment/webhook endpoint was hit');
        
        $request = file_get_contents('php://input');
        $msg = json_encode($request, true);
        $this->logger->addDebug("Json Output: ".print_r($msg, true));
    }
}
