<?php

namespace Razorpay\Magento\Controller\Payment;

use Razorpay\Magento\Model\PaymentMethod;
use Magento\Framework\Controller\ResultFactory;

class Order extends \Razorpay\Magento\Controller\BaseController
{
	protected $quote;

	protected $checkoutSession;

	protected $_currency = PaymentMethod::CURRENCY;
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
        $amount = (int) (round($this->getQuote()->getBaseGrandTotal(), 2) * 100);

        $receipt_id = $this->getQuote()->getId();

        $payment_action = $this->config->getPaymentAction();

        $maze_version = $this->_objectManager->get('Magento\Framework\App\ProductMetadataInterface')->getVersion();
        $module_version =  $this->_objectManager->get('Magento\Framework\Module\ModuleList')->getOne('Razorpay_Magento')['setup_version'];

        if ($payment_action === 'authorize') 
        {
                $payment_capture = 0;
        }
        else
        {
                $payment_capture = 1;
        }

        $code = 400;

        try
        {
            $order = $this->rzp->order->create([
                'amount' => $amount,
                'receipt' => $receipt_id,
                'currency' => $this->_currency,
                'payment_capture' => $payment_capture
            ]);

            $responseContent = [
                'message'   => 'Unable to create your order. Please contact support.',
                'parameters' => []
            ];

            if (null !== $order && !empty($order->id))
            {
                $responseContent = [
                    'success'           => true,
                    'rzp_order'         => $order->id,
                    'order_id'          => $receipt_id,
                    'amount'            => $order->amount,
                    'quote_currency'    => $this->getQuote()->getQuoteCurrencyCode(),
                    'quote_amount'      => round($this->getQuote()->getGrandTotal(), 2),
                    'maze_version'      => $maze_version,
                    'module_version'    => $module_version,
                ];

                $code = 200;

                $this->catalogSession->setRazorpayOrderID($order->id);
            }
        }
        catch(\Razorpay\Api\Errors\Error $e)
        {
            $responseContent = [
                'message'   => $e->getMessage(),
                'parameters' => []
            ];
        }
        catch(\Exception $e)
        {
            $responseContent = [
                'message'   => $e->getMessage(),
                'parameters' => []
            ];
        }

        $response = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $response->setData($responseContent);
        $response->setHttpResponseCode($code);

        return $response;
    }

    public function getOrderID()
    {
        return $this->catalogSession->getRazorpayOrderID();
    }
}
