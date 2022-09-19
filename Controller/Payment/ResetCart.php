<?php

namespace Razorpay\Magento\Controller\Payment;

use Razorpay\Magento\Model\PaymentMethod;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\App\ObjectManager;

class ResetCart extends \Razorpay\Magento\Controller\BaseController
{
	protected $quote;

	protected $checkoutSession;

    protected $logger;

	/**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Razorpay\Model\CheckoutFactory $checkoutFactory
     * @param \Magento\Razorpay\Model\Config\Payment $razorpayConfig
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Razorpay\Magento\Model\CheckoutFactory $checkoutFactory,
        \Razorpay\Magento\Model\Config $config,
        \Magento\Catalog\Model\Session $catalogSession,
        \Psr\Log\LoggerInterface $logger
    ) {
        parent::__construct(
            $context,
            $customerSession,
            $checkoutSession,
            $config
        );

        $this->checkoutFactory = $checkoutFactory;
        $this->catalogSession  = $catalogSession;
        $this->config          = $config;
        $this->logger          = $logger;
    }

    public function execute()
    {
        $this->logger->info("Reset Cart started.");

        $lastQuoteId = $this->checkoutSession->getLastQuoteId();

        $lastOrderId = $this->checkoutSession->getLastRealOrder();

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

        if ($lastQuoteId && $lastOrderId)
        {
            $this->logger->info("Reset Cart: with lastQuoteId:" . $lastQuoteId);

            $orderModel = $objectManager->get('Magento\Sales\Model\Order')->load($lastOrderId->getEntityId());

            if ($orderModel->canCancel())
            {
                $quote = $objectManager->get('Magento\Quote\Model\Quote')->load($lastQuoteId);

                $quote->setIsActive(true)->save();

                $this->checkoutSession->replaceQuote($quote);
                 
                //not canceling order as cancled order can't be used again for order processing.
                //$orderModel->cancel(); 
                $orderModel->setStatus('canceled');

                $orderModel->save();

                $this->checkoutSession->setFirstTimeChk('0');

                $this->logger->info("Reset Cart: redirect_url: checkout/#payment");

                $responseContent = [
                    'success'           => true,
                    'redirect_url'         => 'checkout/#payment'
                ];
            }
        }
       
        if (!$lastQuoteId || !$lastOrderId)
        {
            $this->logger->info("Reset Cart: redirect_url: checkout/cart");

            $responseContent = [
                'success'           => true,
                'redirect_url'         => 'checkout/cart'
            ];
        }

        $this->messageManager->addError(__('Payment Failed or Payment closed'));

        $this->logger->critical("Reset Cart: Payment Failed or Payment closed");

        $response = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        $response->setData($responseContent);

        $response->setHttpResponseCode(200);

        return $response;

    }

}
