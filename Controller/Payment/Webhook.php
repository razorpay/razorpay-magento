<?php 
namespace Razorpay\Magento\Controller\Payment;

use Magento\Framework\Controller\ResultFactory;
use Magento\Sales\Model\ResourceModel\Order\Payment\Transaction\CollectionFactory as TransactionCollectionFactory;
use Magento\Sales\Model\Order\Payment\Transaction;
use Razorpay\Magento\Model\Config;
use Magento\Catalog\Model\Session;

class Webhook extends \Razorpay\Magento\Controller\BaseController
{
    public function __construct(
      \Magento\Framework\App\Action\Context $context,
      \Magento\Customer\Model\Session $customerSession,
      \Magento\Checkout\Model\Session $checkoutSession,
      \Razorpay\Magento\Model\CheckoutFactory $checkoutFactory,
      \Razorpay\Magento\Model\Config $config,
      \Magento\Framework\App\RequestInterface $request,
      \Magento\Catalog\Model\Session $catalogSession,
      TransactionCollectionFactory $salesTransactionCollectionFactory
    ) 
    {
        parent::__construct(
           $context,
           $customerSession,
           $checkoutSession,
           $config
        );
        $this->checkoutFactory = $checkoutFactory;
	    $this->config = $config;
        $this->request = $request;
        $this->catalogSession = $catalogSession;
        $this->salesTransactionCollectionFactory = $salesTransactionCollectionFactory;
    }
    /**
     * Processes the incoming webhook
     */
    public function execute()
    {
        echo "Hello World\n";
        
        //$request = $this->getPostData();
        //$txn_id = $request['rzp_payment_id'];
        $txn_id = getTxnId();
        echo "\nTransaction ID = " . $txn_id;
    }
    
    protected function getTxnId(\Magento\Sales\Model\Order\Payment\Transaction $transaction)
    {
        return $transaction->getTxnId();
    }
}
   
