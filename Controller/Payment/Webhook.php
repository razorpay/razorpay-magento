<?php 
namespace Razorpay\Magento\Controller\Payment;

class Webhook extends \Razorpay\Magento\Controller\BaseController
{
    public function __construct(
      \Magento\Framework\App\Action\Context $context,
      \Magento\Customer\Model\Session $customerSession,
      \Magento\Checkout\Model\Session $checkoutSession,
      \Razorpay\Magento\Model\Config $config,
      \Magento\Sales\Model\Order\Payment\Transaction $transaction 
    ) 
    {
        parent::__construct(
           $context,
           $customerSession,
           $checkoutSession,
           $transaction
        );
        
        $this->transaction = $transaction;
    }
    /**
     * Processes the incoming webhook
     */
    public function execute()
    {
        var_dump("Proof of life");
        $txn_id = $this->transaction->getTxnId();
        echo $txn_id;
    }
}
   
