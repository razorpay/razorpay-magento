<?php 
namespace Razorpay\Magento\Controller\Payment;

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
      \Razorpay\Magento\Model\Config $config,
      \Magento\Framework\App\RequestInterface $request,
      TransactionCollectionFactory $salesTransactionCollectionFactory
    ) 
    {
        parent::__construct(
           $context,
           $customerSession,
           $checkoutSession,
           $config
        );
        $this->request = $request;
        $this->salesTransactionCollectionFactory = $salesTransactionCollectionFactory;
    }
    /**
     * Processes the incoming webhook
     */
    public function execute()
    {
        echo "Hello World\n";
        
        $request = $this->getPostData();
        $txn_id = $request['rzp_payment_id'];
        echo "Transaction ID = " . $txn_id;
    }
    
    protected function getPostData()
    {
        $request = file_get_contents('php://input');
        return json_decode($request, true);
    }
}
   
