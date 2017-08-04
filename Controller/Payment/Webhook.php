<?php 

namespace Razorpay\Magento\Controller\Payment;

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

    const STATUS_APPROVED = 'APPROVED';

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
        \Magento\Sales\Api\Data\OrderInterface $order
    ) 
    {
        parent::__construct(
            $context,
            $customerSession,
            $checkoutSession,
            $config
        );

        $this->order           = $order;
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

        //
        // Need to add signature verification
        //
        switch ($post['event'])
        {
            case 'payment.authorized':
                return $this->paymentAuthorized($post);

            default:
                return;
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

        $orderId = $quote->getReservedOrderId();

        $order = $this->order->loadByIncrementId($orderId);

        $payment = $order->getPayment();

        $payment->setStatus(self::STATUS_APPROVED)
                ->setAmountPaid($amount)
                ->setLastTransId($paymentId)
                ->setTransactionId($paymentId)
                ->setIsTransactionClosed(true)
                ->setShouldCloseParentTransaction(true);

        $order->setStatus('processing');
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