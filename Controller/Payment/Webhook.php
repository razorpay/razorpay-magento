<?php 
namespace Razorpay\Magento\Controller\Payment;

use Magento\Framework\Controller\ResultFactory;

class Webhook extends \Razorpay\Magento\Controller\BaseController
{
    public function __construct(
        \Magento\Framework\App\Action\Context $context      
    ) {
        return parent::__construct($context);
    }
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
