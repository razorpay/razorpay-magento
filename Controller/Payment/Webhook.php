<?php

namespace Razorpay\Magento\Controller\Payment;

class Webhook extends \Magento\Framework\App\Action\Action
{
    public function execute()
    {
        echo "hello"."\n\n";
        $request = file_get_contents('php://input');
        $msg = json_encode($request, true);
        echo "Request = ".$msg;
    }
}
