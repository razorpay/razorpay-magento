<?php
namespace Razorpay\Magento\Controller\Payment;
use Razorpay\Magento\Model\Config;
use Razorpay\Api\Api;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Payment\State\CaptureCommand;
use Magento\Sales\Model\Order\Payment\State\AuthorizeCommand;
use Magento\Framework\Controller\ResultFactory;
use Razorpay\Magento\Model\PaymentMethod;

/**
 * CancelPendingOrders controller to cancel Magento order
 *
 * ...
 */
class Callback extends \Razorpay\Magento\Controller\BaseController
{
    /**
     * @var \Magento\Sales\Api\Data\OrderInterface
     */
    protected $setup;

    /**
     * @var \Magento\Sales\Model\Service\InvoiceService
     */
    protected $_invoiceService;
    protected $orderSender;

    /**
     * @var \Magento\Framework\DB\Transaction
     */
    protected $_transaction;

    const STATUS_APPROVED = 'APPROVED';
    const STATUS_PROCESSING = 'processing';
    /**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Razorpay\Magento\Model\Config $config
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param \Magento\Catalog\Model\Session $catalogSession
     */
    public function __construct(\Magento\Framework\App\Action\Context $context, \Magento\Customer\Model\Session $customerSession, \Magento\Checkout\Model\Session $checkoutSession, \Razorpay\Magento\Model\Config $config, \Psr\Log\LoggerInterface $logger, OrderRepositoryInterface $orderRepository, \Magento\Framework\DB\Transaction $transaction, \Magento\Sales\Model\Service\InvoiceService $invoiceService, \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender, \Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender, \Magento\Catalog\Model\Session $catalogSession, \Magento\Sales\Api\Data\OrderInterface $order
)
    {
        parent::__construct($context, $customerSession, $checkoutSession, $config);

        $this->config           = $config;
        $this->checkoutSession  = $checkoutSession;
        $this->customerSession  = $customerSession;
        $this->logger           = $logger;
        $this->orderRepository  = $orderRepository;
        $this->objectManagement = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_transaction     = $transaction;
        $this->orderSender      = $orderSender;
        $this->_invoiceService  = $invoiceService;
        $this->_invoiceSender   = $invoiceSender;
        $this->catalogSession   = $catalogSession;
        $this->order            = $order;

    }
    public function execute()
    {

        $params = $this->getRequest()
            ->getParams();

        $orderId = strip_tags($params["order_id"]);
        try
        {
            $collection = $this
                ->objectManagement
                ->get('Magento\Sales\Model\Order')
                ->getCollection()
                ->addFieldToSelect('entity_id')
                ->addFieldToSelect('rzp_order_id')
                ->addFilter('increment_id', $orderId)->getFirstItem();

            $this->razorpayOrderID = $collection->getRzpOrderId();
            $order = $this
                ->order
                ->load($collection->getEntityId());

        }
        catch(\Exception $e)
        {
            $this
                ->logger
                ->critical("Callback Error: " . $e->getMessage());
        }

        if (empty($orderId) === true)
        {
            $this
                ->messageManager
                ->addError(__('Razorpay front-end callback: Payment Failed, As no active cart ID found.'));

            return $this->_redirect('checkout/cart');
        }

        if (isset($params['razorpay_payment_id']))
        {
            try
            {
                $this->validateSignature($params);

                $orderId = $order->getIncrementId();

                $order->setState(static ::STATUS_PROCESSING)
                    ->setStatus(static ::STATUS_PROCESSING);

                $payment = $order->getPayment();
                $paymentId = $params['razorpay_payment_id'];

                $payment->setLastTransId($paymentId)->setTransactionId($paymentId)->setIsTransactionClosed(true)
                    ->setShouldCloseParentTransaction(true);

                $payment->setParentTransactionId($payment->getTransactionId());

                if ($this
                    ->config
                    ->getPaymentAction() === \Razorpay\Magento\Model\PaymentMethod::ACTION_AUTHORIZE_CAPTURE)
                {
                    $payment->addTransactionCommentsToOrder("$paymentId", (new CaptureCommand())->execute($payment, $order->getGrandTotal() , $order) , "");
                }
                else
                {
                    $payment->addTransactionCommentsToOrder("$paymentId", (new AuthorizeCommand())->execute($payment, $order->getGrandTotal() , $order) , "");
                }

                $transaction = $payment->addTransaction(\Magento\Sales\Model\Order\Payment\Transaction::TYPE_AUTH, null, true, "");
                $transaction->setIsClosed(true);
                $transaction->save();

                $order->save();

                $this
                    ->orderRepository
                    ->save($order);

                //update/disable the quote
                $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                $quote = $objectManager->get('Magento\Quote\Model\Quote')
                    ->load($order->getQuoteId());
                $quote->setIsActive(false)
                    ->save();

                if ($order->canInvoice() and ($this
                    ->config
                    ->getPaymentAction() === \Razorpay\Magento\Model\PaymentMethod::ACTION_AUTHORIZE_CAPTURE) and $this
                    ->config
                    ->canAutoGenerateInvoice())
                {
                    $invoice = $this
                        ->_invoiceService
                        ->prepareInvoice($order);
                    $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                    $invoice->setTransactionId($paymentId);
                    $invoice->register();
                    $invoice->save();
                    $transactionSave = $this
                        ->_transaction
                        ->addObject($invoice)->addObject($invoice->getOrder());
                    $transactionSave->save();

                    $this
                        ->_invoiceSender
                        ->send($invoice);
                    //send notification code
                    $order->setState(static ::STATUS_PROCESSING)
                        ->setStatus(static ::STATUS_PROCESSING);
                    $order->addStatusHistoryComment(__('Notified customer about invoice #%1.', $invoice->getId()))
                        ->setIsCustomerNotified(true)
                        ->save();
                }

                //send Order email, after successfull payment
                try
                {
                    $this
                        ->checkoutSession
                        ->setRazorpayMailSentOnSuccess(true);
                    $this
                        ->orderSender
                        ->send($order);
                    $this
                        ->checkoutSession
                        ->unsRazorpayMailSentOnSuccess();

                }
                catch(\Magento\Framework\Exception\MailException $exception)
                {
                    $this
                        ->logger
                        ->critical("Validate: MailException Error message:" . $exception->getMessage());
                }
                catch(\Exception $e)
                {
                    $this
                        ->logger
                        ->critical("Validate: Exception Error message:" . $e->getMessage());
                }

                $this
                    ->checkoutSession
                    ->setLastSuccessQuoteId($order->getQuoteId())
                    ->setLastQuoteId($order->getQuoteId())
                    ->clearHelperData();
                if (empty($order) === false)
                {
                    $this
                        ->checkoutSession
                        ->setLastOrderId($order->getId())
                        ->setLastRealOrderId($order->getIncrementId())
                        ->setLastOrderStatus($order->getStatus());
                }
                return $this->_redirect('checkout/onepage/success');

            }
            catch(\Razorpay\Api\Errors\Error $e)
            {

                $this
                    ->logger
                    ->critical("Validate: Razorpay Error message:" . $e->getMessage());
                $responseContent['message'] = $e->getMessage();

                $code = $e->getCode();
            }
            catch(\Exception $e)
            {

                $this
                    ->logger
                    ->critical("Validate: Exception Error message:" . $e->getMessage());
                $responseContent['message'] = $e->getMessage();

                $code = $e->getCode();
            }
        }
        else
        {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $quote = $objectManager->get('Magento\Quote\Model\Quote')
                ->load($order->getQuoteId());
            $quote->setIsActive(1)
                ->setReservedOrderId(null)
                ->save();

            $this
                ->checkoutSession
                ->replaceQuote($quote);

            $this
                ->logger
                ->critical(__('Razorpay front-end callback: Payment Failed with response:  ' . json_encode($params, 1)));

            $this
                ->messageManager
                ->addError(__('Payment Failed.'));

            return $this->_redirect('checkout/cart');

        }
    }

    protected function validateSignature($request)
    {
        if (empty($request['error']) === false)
        {
            $this
                ->logger
                ->critical("Validate: Payment Failed or error from gateway");
            $this
                ->messageManager
                ->addError(__('Payment Failed'));
            throw new \Exception("Payment Failed or error from gateway");
        }

        $attributes = array(
            'razorpay_payment_id' => $request['razorpay_payment_id'],
            'razorpay_order_id' => $this->razorpayOrderID,
            'razorpay_signature' => $request['razorpay_signature'],
        );

        $this
            ->rzp
            ->utility
            ->verifyPaymentSignature($attributes);
    }
}

