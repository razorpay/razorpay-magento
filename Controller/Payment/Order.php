<?php

namespace Razorpay\Magento\Controller\Payment;

use Razorpay\Api\Api;
use Razorpay\Magento\Model\PaymentMethod;
use Magento\Framework\Controller\ResultFactory;

class Order extends \Razorpay\Magento\Controller\BaseController
{
    protected $quote;

    protected $checkoutSession;

    protected $_currency = PaymentMethod::CURRENCY;

    protected $cartManagement;

    /**
     * @var \Magento\Sales\Api\Data\OrderInterface
     */
    protected $order;

    const STATUS_APPROVED = 'APPROVED';
    /**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Razorpay\Model\Config\Payment $razorpayConfig
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Razorpay\Magento\Model\Config $config,
        \Magento\Catalog\Model\Session $catalogSession,
        \Magento\Quote\Api\CartManagementInterface $cartManagement
    ) {
        parent::__construct(
            $context,
            $customerSession,
            $checkoutSession,
            $config
        );

        $this->catalogSession  = $catalogSession;
        $this->config          = $config;
        $this->cartManagement  = $cartManagement;
        $this->customerSession = $customerSession;
    }

    public function execute()
    {
        $amount = (int) (round($this->getQuote()->getGrandTotal(), 2) * 100);

        $receipt_id = $this->getQuote()->getId();

        if(empty($_POST['error']) === false)
        {
            $this->messageManager->addError(__('Payment Failed'));
            return $this->_redirect('checkout/cart');
        }

        if(isset($_POST['razorpay_payment_id']))
        {
            $this->getQuote()->getPayment()->setMethod(PaymentMethod::METHOD_CODE);

            try
            {
                if(!$this->customerSession->isLoggedIn()) {
                    $this->getQuote()->setCheckoutMethod($this->cartManagement::METHOD_GUEST);
                    $this->getQuote()->setCustomerEmail($this->customerSession->getCustomerEmailAddress());
                }
                $this->cartManagement->placeOrder($this->getQuote()->getId());
                return $this->_redirect('checkout/onepage/success');
            }
            catch(\Exception $e)
            {
                $this->messageManager->addError(__($e->getMessage()));
                return $this->_redirect('checkout/cart');
            }
        }
        else
        {
            $payment_action = $this->config->getPaymentAction();

            $maze_version = $this->_objectManager->get('Magento\Framework\App\ProductMetadataInterface')->getVersion();
            $module_version =  $this->_objectManager->get('Magento\Framework\Module\ModuleList')->getOne('Razorpay_Magento')['setup_version'];

            $this->customerSession->setCustomerEmailAddress($_POST['email']);

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
                    'currency' => $this->getQuote()->getQuoteCurrencyCode(),
                    'payment_capture' => $payment_capture
                ]);

                $responseContent = [
                    'message'   => 'Unable to create your order. Please contact support.',
                    'parameters' => []
                ];

                if (null !== $order && !empty($order->id))
                {
                    $is_hosted = false;

                    $merchantPreferences    = $this->getMerchantPreferences();

                    $responseContent = [
                        'success'           => true,
                        'rzp_order'         => $order->id,
                        'order_id'          => $receipt_id,
                        'amount'            => $order->amount,
                        'quote_currency'    => $this->getQuote()->getQuoteCurrencyCode(),
                        'quote_amount'      => round($this->getQuote()->getGrandTotal(), 2),
                        'maze_version'      => $maze_version,
                        'module_version'    => $module_version,
                        'is_hosted'         => $merchantPreferences['is_hosted'],
                        'image'             => $merchantPreferences['image'],
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
    }

    public function getOrderID()
    {
        return $this->catalogSession->getRazorpayOrderID();
    }

    protected function getMerchantPreferences()
    {
        try
        {
            $api = new Api($this->config->getKeyId(),"");

            $response = $api->request->request("GET", "preferences");
        }
        catch (Exception $e)
        {
            echo 'Magento Error : ' . $e->getMessage();
        }

        $preferences = [];

        $preferences['is_hosted'] = false;
        $preferences['image'] = $response['options']['image'];

        if(isset($response['options']['redirect']) && $response['options']['redirect'] === true)
        {
            $preferences['is_hosted'] = true;
        }

        return $preferences;
    }
}
