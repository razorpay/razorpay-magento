<?php 

namespace Razorpay\Magento\Controller\Payment;

use Razorpay\Api\Api;
use Razorpay\Api\Errors;
use Razorpay\Magento\Model\Config;
use Razorpay\Magento\Model\PaymentMethod;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Sales\Api\OrderRepositoryInterface;

class Validate extends \Razorpay\Magento\Controller\BaseController implements CsrfAwareActionInterface
{
    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $checkoutSession;


    /**
     * @var \Magento\Sales\Api\Data\OrderInterface
     */
    protected $order;

    protected $api;

    protected $objectManagement;

    protected $orderSender;

    const STATUS_APPROVED = 'APPROVED';
    const STATUS_PROCESSING = 'processing';

    /**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Razorpay\Magento\Model\Config $config
     * @param \Magento\Catalog\Model\Session $catalogSession
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Razorpay\Magento\Model\Config $config,
        \Magento\Catalog\Model\Session $catalogSession,
        \Magento\Sales\Api\Data\OrderInterface $order,
        OrderRepositoryInterface $orderRepository,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender
    ) 
    {
        parent::__construct(
            $context,
            $customerSession,
            $checkoutSession,
            $config
        );

        $keyId                 = $this->config->getConfigData(Config::KEY_PUBLIC_KEY);
        $keySecret             = $this->config->getConfigData(Config::KEY_PRIVATE_KEY);

        $this->api             = new Api($keyId, $keySecret);
        $this->order           = $order;


        $this->objectManagement   = \Magento\Framework\App\ObjectManager::getInstance();
        $this->catalogSession     = $catalogSession;
        $this->orderRepository    = $orderRepository;
        $this->orderSender        = $orderSender;
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(
        RequestInterface $request
    ): ?InvalidRequestException {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * Processes the incoming webhook
     */
    public function execute()
    {  
        $post = $this->getPostData(); 

        if (json_last_error() !== 0)
        {
            return;
        }

        try
        {
            $this->validateSignature($post);

            $order = $this->checkoutSession->getLastRealOrder();
            $orderId = $order->getIncrementId();
            $order->setState(static::STATUS_PROCESSING)->setStatus(static::STATUS_PROCESSING);
            

            $payment = $order->getPayment();        
        
            $paymentId = $post['razorpay_payment_id'];
            
            $payment->setLastTransId($paymentId)
                ->setTransactionId($paymentId)
                ->setIsTransactionClosed(true)
                ->setShouldCloseParentTransaction(true);

            $payment->addTransactionCommentsToOrder(
                $paymentId,
                ""
            );
            $order->save();

            $this->orderRepository->save($order);

            //send Order email, after successfull payment
            $this->checkoutSession->setRazorpayMailSentOnSuccess(true);
            $this->orderSender->send($order, true);
            $this->checkoutSession->unsRazorpayMailSentOnSuccess();

            $responseContent = [
                'success'           => true,
                'redirect_url'         => 'onepage/success/',
                'order_id'  => $orderId,
            ];

            $code = 200;

        }
        catch(\Razorpay\Api\Errors\Error $e)
        {
            $responseContent = [
                'message'   => $e->getMessage(),
                'parameters' => []
            ];
            $code = $e->getCode();
        }
        catch(\Exception $e)
        {
            $responseContent = [
                'message'   => $e->getMessage(),
                'parameters' => []
            ];
            $code = $e->getCode();
        } 

        
        $response = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $response->setData($responseContent);
        $response->setHttpResponseCode($code);
        return $response;
    }


    protected function validateSignature($request)
    { 
        $attributes = array(
            'razorpay_payment_id' => $request['razorpay_payment_id'],
            'razorpay_order_id'   => $this->catalogSession->getRazorpayOrderID(),
            'razorpay_signature'  => $request['razorpay_signature'],
        );
        
        $this->rzp->utility->verifyPaymentSignature($attributes);
    }

    
    /**
     * @return Webhook post data as an array
     */
    protected function getPostData() : array
    {
        $request = file_get_contents('php://input');
        return json_decode($request, true);
    }
}