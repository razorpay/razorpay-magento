<?php 
namespace Razorpay\Magento\Controller\Payment;

use Magento\Framework\Controller\ResultFactory;
use Magento\Sales\Model\Order\Payment;

class Webhook extends \Razorpay\Magento\Controller\BaseController
{
    
    /**
     * Processes the incoming webhook
     */
    public function execute()
    {
        $post = $this->getPostData();
        mail("seher@kdc.in","Test Webhook 1",var_dump($post),"From: webmaster@m23.aws.rzp.re");
        var_dump($post);
    }    
    protected function getPostData()
    {
        $request = file_get_contents('php://input');
        return json_decode($request, true);
    }
}
