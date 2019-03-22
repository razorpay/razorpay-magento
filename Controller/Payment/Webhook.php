<?php 
namespace Razorpay\Magento\Controller\Payment;

class Webhook extends \Razorpay\Magento\Controller\BaseController
{
    public function __construct(
      \Magento\Sales\Model\Order\Payment\Transaction $transaction 
    ) 
    {
        parent::__construct(
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
   
