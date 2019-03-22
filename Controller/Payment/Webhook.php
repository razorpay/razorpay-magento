<?php 
namespace Razorpay\Magento\Controller\Payment;

use Magento\Sales\Model\ResourceModel\Order\Payment\Transaction\CollectionFactory as TransactionCollectionFactory;

class Webhook extends \Razorpay\Magento\Controller\BaseController
{
    public function __construct(
      \Magento\Framework\App\Action\Context $context,
      \Magento\Customer\Model\Session $customerSession,
      \Magento\Checkout\Model\Session $checkoutSession,
      \Razorpay\Magento\Model\Config $config,
      TransactionCollectionFactory $salesTransactionCollectionFactory
    ) 
    {
        parent::__construct(
           $context,
           $customerSession,
           $checkoutSession,
           $config
        );
        
        $this->salesTransactionCollectionFactory = $salesTransactionCollectionFactory;
    }
    /**
     * Processes the incoming webhook
     */
    public function execute()
    {
        var_dump("Proof of life");
        $txn_id = $this->salesTransactionCollectionFactory->getTxnId();
        echo $txn_id;
    }
}
   
