<?php 

namespace Razorpay\Magento\Controller\Payment;

use Razorpay\Api\Api;
use Razorpay\Api\Errors;
use Razorpay\Magento\Model\Config;

class Webhook extends \Razorpay\Magento\Controller\BaseController
{
    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $checkoutSession;

    /**
     * @var \Magento\Quote\Model\QuoteRepository
     */
    protected $quoteRepository;

    /**
     * @var \Magento\Sales\Api\Data\OrderInterface
     */
    protected $order;

    protected $api;

    protected $logger;

    const STATUS_APPROVED = 'APPROVED';

    const ORDER_PROCESSING = 'processing';

    /**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Razorpay\Model\CheckoutFactory $checkoutFactory
     * @param \Razorpay\Magento\Model\Config $config
     * @param \Magento\Catalog\Model\Session $catalogSession
     * @param \Magento\Quote\Model\QuoteRepository $quoteRepository,
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Razorpay\Magento\Model\CheckoutFactory $checkoutFactory,
        \Razorpay\Magento\Model\Config $config,
        \Magento\Catalog\Model\Session $catalogSession,
        \Magento\Quote\Model\QuoteRepository $quoteRepository,
        \Magento\Sales\Api\Data\OrderInterface $order,
        \Psr\Log\LoggerInterface $logger
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
        $this->logger          = $logger;

        $this->checkoutFactory = $checkoutFactory;
        $this->catalogSession  = $catalogSession;
        $this->quoteRepository = $quoteRepository;
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

        if (($this->config->isWebhookEnabled() === true) && 
            (empty($post['event']) === false))
        {
            if (isset($_SERVER['HTTP_X_RAZORPAY_SIGNATURE']) === true)
            {
                $webhookSecret = $this->config->getWebhookSecret();

                //
                // To accept webhooks, the merchant must configure 
                // it on the magento backend by setting the secret
                // 
                if (empty($webhookSecret) === true)
                {
                    return;
                }

                try
                {
                    $this->api->utility->verifyWebhookSignature(json_encode($post), 
                                                                $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'], 
                                                                $webhookSecret);
                }
                catch (Errors\SignatureVerificationError $e)
                {
                    $this->logger->warning(
                        $e->getMessage(), 
                        [
                            'data'  => $post,
                            'event' => 'razorpay.wc.signature.verify_failed'
                        ]);

                    return;
                }

                switch ($post['event'])
                {
                    case 'payment.authorized':
                        return $this->paymentAuthorized($post);

                    default:
                        return;
                }
            }
        }
    }

    /**
     * Payment Authorized webhook
     * 
     * @param array $post
     */
    protected function paymentAuthorized(array $post)
    {
        $quoteId   = $post['payload']['payment']['entity']['notes']['merchant_order_id'];
        $amount    = $post['payload']['payment']['entity']['amount'];
        $paymentId = $post['payload']['payment']['entity']['id'];

        $quote = $this->quoteRepository->get($quoteId);

        //
        // We reserve a new order id if one is not reserved already
        //
        $orderId = $quote->reserveOrderId()->getReservedOrderId();

        $order = $this->order->loadByIncrementId($orderId);

        if ($order->getStatus() === self::ORDER_PROCESSING)
        {
            return;
        }

        $payment = $order->getPayment();

        $payment->setStatus(self::STATUS_APPROVED)
                ->setAmountPaid($amount)
                ->setLastTransId($paymentId)
                ->setTransactionId($paymentId)
                ->setIsTransactionClosed(true)
                ->setShouldCloseParentTransaction(true);

        $order->setStatus(self::ORDER_PROCESSING);
        $order->save();
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