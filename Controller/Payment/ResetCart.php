<?php

namespace Razorpay\Magento\Controller\Payment;

use Razorpay\Magento\Model\PaymentMethod;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\App\ObjectManager;

class ResetCart extends \Razorpay\Magento\Controller\BaseController
{
	protected $quote;

	protected $checkoutSession;

	/**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Razorpay\Model\CheckoutFactory $checkoutFactory
     * @param \Magento\Razorpay\Model\Config\Payment $razorpayConfig
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Razorpay\Magento\Model\CheckoutFactory $checkoutFactory,
        \Razorpay\Magento\Model\Config $config,
        \Magento\Catalog\Model\Session $catalogSession
    ) {
        parent::__construct(
            $context,
            $customerSession,
            $checkoutSession,
            $config
        );

        $this->checkoutFactory = $checkoutFactory;
        $this->catalogSession = $catalogSession;
        $this->config = $config;
    }

    public function execute()
    {
        $lastQuoteId = $this->checkoutSession->getLastQuoteId();
        $lastOrderId = $this->checkoutSession->getLastRealOrder();

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();



        if ($lastQuoteId && $lastOrderId) {
            $orderModel = $objectManager->get('Magento\Sales\Model\Order')->load($lastOrderId->getEntityId());

            if($orderModel->canCancel()) {
               
                $quote = $objectManager->get('Magento\Quote\Model\Quote')->load($lastQuoteId);
                $quote->setIsActive(true)->save();
                 
                //not canceling order as cancled order can't be used again for order processing.
                //$orderModel->cancel(); 
                $orderModel->setStatus('canceled');
                $orderModel->save();
                $this->checkoutSession->setFirstTimeChk('0');                
                
                $responseContent = [
                    'success'           => true,
                    'redirect_url'         => 'checkout/#payment'
                    ];
            }
        }
       
        if (!$lastQuoteId || !$lastOrderId) {
            $responseContent = [
                'success'           => true,
                'redirect_url'         => 'checkout/cart'
                ];
        }

        $this->messageManager->addError(__('Payment Failed or Payment closed'));
        
        $response = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $response->setData($responseContent);
        $response->setHttpResponseCode(200);

        return $response;

    }

}
