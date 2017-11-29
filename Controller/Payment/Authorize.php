<?php

namespace Razorpay\Magento\Controller\Payment;

use Magento\Framework\Exception\LocalizedException;
use Razorpay\Api\Api;
use Razorpay\Magento\Model\Config;
use Razorpay\Magento\Model\PaymentMethod;

class Authorize extends \Razorpay\Magento\Controller\BaseController
{
    const METHOD_CODE                   = 'razorpay';

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * Razorpay Api Key ID
     * @var string
     */
    protected $keyId;

    /**
     * Razorpay Api Key Secret
     * @var string
     */
    protected $keySecret;

    /**
     * @var Api
     */
    protected $rzp;

    /**
     * @var \Razorpay\Magento\Model\Config
     */
    protected $config;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Razorpay\Magento\Model\Config $config,
        \Psr\Log\LoggerInterface $logger
    )
    {
        parent::__construct(
            $context,
            $customerSession,
            $checkoutSession,
            $config
        );

        $this->keyId = $this->config->getConfigData(Config::KEY_PUBLIC_KEY);
        $this->keySecret = $this->config->getConfigData(Config::KEY_PRIVATE_KEY);

        $this->rzp = new Api($this->keyId, $this->keySecret);

        $this->logger = $logger;
    }

    public function execute()
    {
        try
        {
            $magentoOrder = $this->checkoutSession->getLastRealOrder();

            // Order amount has to be in INR, and base currenct should be in INR
            $amount = (int) (round($magentoOrder->getBaseGrandTotal() * 100, 2));

            $payment = $magentoOrder->getPayment();

            $attributes = $this->getRazorpayRequestArray();

            $this->rzp->utility->verifyPaymentSignature($attributes);

            $paymentId = $attributes['razorpay_payment_id'];

            $magentoOrder->setState('processing')
                         ->setStatus('processing')
                         ->save();

            $payment->setStatus(PaymentMethod::STATUS_APPROVED)
                    ->setAmountPaid($amount)
                    ->setLastTransId($paymentId)
                    ->setTransactionId($paymentId)
                    ->setIsTransactionClosed(true)
                    ->setShouldCloseParentTransaction(true)
                    ->place();
        }
        catch (\Exception $e)
        {
            $magentoOrder->setState('pending')
                         ->setStatus('pending')
                         ->save();

            $this->logger->critical($e);
            throw new LocalizedException(__('Razorpay Error: %1.', $e->getMessage()));
        }
    }

    protected function getRazorpayRequestArray()
    {
        return [
            'razorpay_payment_id' => $_POST['razorpay_payment_id'],
            'razorpay_order_id'   => $this->checkoutSession->getRazorpayOrderID(),
            'razorpay_signature'  => $_POST['razorpay_signature'],
        ];
    }
}