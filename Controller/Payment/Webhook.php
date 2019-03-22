<?php 
namespace Razorpay\Magento\Controller\Payment;

use Magento\Sales\Model\Order\Payment\Transaction;


class Webhook extends \Razorpay\Magento\Controller\BaseController
{
    /**
     * @var \Magento\Sales\Model\Order\Payment\Transaction
     */
    protected $transaction;
    
    public function __construct(
      Transaction $transaction 
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
   
