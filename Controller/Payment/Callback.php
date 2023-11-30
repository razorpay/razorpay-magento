<?php
namespace Razorpay\Magento\Controller\Payment;
use Razorpay\Magento\Model\Config;
use Razorpay\Api\Api;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Payment\State\CaptureCommand;
use Magento\Sales\Model\Order\Payment\State\AuthorizeCommand;
use Magento\Framework\Controller\ResultFactory;
use Razorpay\Magento\Model\PaymentMethod;
use Razorpay\Magento\Constants\OrderCronStatus;

/**
 * CancelPendingOrders controller to cancel Magento order
 * Used for off site redirect payment
 * ...
 */
class Callback extends \Razorpay\Magento\Controller\BaseController
{
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
    const AUTHORIZED = 'authorized';
    const CAPTURED = 'captured';
    
    /**
     * @var \Razorpay\Magento\Model\Config
     */
    protected $config;

    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $checkoutSession;

    /**
     * @var \Magento\Customer\Model\Session
     */
    protected $customerSession;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    protected $orderRepository;

    protected $objectManagement;

    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\InvoiceSender
     */
    protected $_invoiceSender;

    /**
     * @var \Magento\Catalog\Model\Session
     */
    protected $catalogSession;

    /**
     * @var \Magento\Sales\Api\Data\OrderInterface
     */
    protected $order;

    /**
     * @var \Magento\Sales\Model\Order\Payment\State\CaptureCommand
     */
    protected $captureCommand;

    /**
     * @var \Magento\Sales\Model\Order\Payment\State\AuthorizeCommand
     */
    protected $authorizeCommand;

    protected $razorpayOrderID;

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

        $this->captureCommand = new CaptureCommand();
        $this->authorizeCommand = new AuthorizeCommand();
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
            
            $orderLink = $this->_objectManager->get('Razorpay\Magento\Model\OrderLink')
                                ->getCollection()
                                ->addFilter('order_id', $collection->getEntityId())
                                ->getFirstItem();

            $this->razorpayOrderID = $orderLink->getRzpOrderId();
            $order = $this
                ->order
                ->load($collection->getEntityId());

        }
        catch(\Exception $e)
        {
            // @codeCoverageIgnoreStart
            $this
                ->logger
                ->critical("Callback Error: " . $e->getMessage());
            // @codeCoverageIgnoreEnd
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

                $rzpPayment = $this->rzp->request->request('GET', 'payments/'.$paymentId);

                $payment->setLastTransId($paymentId)->setTransactionId($paymentId)->setIsTransactionClosed(true)
                    ->setShouldCloseParentTransaction(true);

                $payment->setParentTransactionId($payment->getTransactionId());

                if ($this
                    ->config
                    ->getPaymentAction() === \Razorpay\Magento\Model\PaymentMethod::ACTION_AUTHORIZE_CAPTURE)
                {
                    $payment->addTransactionCommentsToOrder("$paymentId", $this->captureCommand->execute($payment, $order->getGrandTotal() , $order) , "");
                }
                else
                {
                    $payment->addTransactionCommentsToOrder("$paymentId", $this->authorizeCommand->execute($payment, $order->getGrandTotal() , $order) , "");
                }

                $transaction = $payment->addTransaction(\Magento\Sales\Model\Order\Payment\Transaction::TYPE_AUTH, null, true, "");
                $transaction->setIsClosed(true);
                $transaction->save();

                $order->save();

                $this
                    ->orderRepository
                    ->save($order);

                //update/disable the quote
                $quote = $this->objectManagement->get('Magento\Quote\Model\Quote')
                    ->load($order->getQuoteId());
                $quote->setIsActive(false)
                    ->save();
                
                $amountPaid = number_format($rzpPayment['amount'] / 100, 2, ".", "");
        
                $order->addStatusHistoryComment(
                    __(
                        'Amount %1 of %2, with Razorpay Offer/Fee applied.',
                        $rzpPayment['status'],
                        $order->getBaseCurrency()->formatTxt($amountPaid)
                    )
                );

                $orderLink->setRzpPaymentId($paymentId);

                $orderLink->setRzpUpdateOrderCronStatus(OrderCronStatus::PAYMENT_AUTHORIZED_COMPLETED);
                $this->logger->info('Payment authorized completed for id : '. $order->getIncrementId());

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

                    $orderLink->setRzpUpdateOrderCronStatus(OrderCronStatus::INVOICE_GENERATED);
                    $this->logger->info('Invoice generated for id : '. $order->getIncrementId());
                }
                else if($this->config->getPaymentAction()  === \Razorpay\Magento\Model\PaymentMethod::ACTION_AUTHORIZE_CAPTURE and
                        ($order->canInvoice() === false or
                        $this->config->canAutoGenerateInvoice() === false))
                {

                    $orderLink->setRzpUpdateOrderCronStatus(OrderCronStatus::INVOICE_GENERATION_NOT_POSSIBLE);
                    $this->logger->info('Invoice generation not possible for id : '. $order->getIncrementId());
            
                }
                $orderLink->save();
                $order->save();

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
                    // @codeCoverageIgnoreStart
                    $this
                        ->logger
                        ->critical("Validate: MailException Error message:" . $exception->getMessage());
                    // @codeCoverageIgnoreEnd
                }
                catch(\Exception $e)
                {
                    // @codeCoverageIgnoreStart
                    $this
                        ->logger
                        ->critical("Validate: Exception Error message:" . $e->getMessage());
                    // @codeCoverageIgnoreEnd
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
                // @codeCoverageIgnoreStart
                $this
                    ->logger
                    ->critical("Validate: Razorpay Error message:" . $e->getMessage());
                // @codeCoverageIgnoreEnd
                $responseContent['message'] = $e->getMessage();

                $code = $e->getCode();
            }
            catch(\Exception $e)
            {
                // @codeCoverageIgnoreStart
                $this
                    ->logger
                    ->critical("Validate: Exception Error message:" . $e->getMessage());
                // @codeCoverageIgnoreEnd
                $responseContent['message'] = $e->getMessage();

                $code = $e->getCode();
            }
        }
        else
        {
            $quote = $this->objectManagement->get('Magento\Quote\Model\Quote')
                ->load($order->getQuoteId());
            $quote->setIsActive(1)
                ->setReservedOrderId(null)
                ->save();

            $this
                ->checkoutSession
                ->replaceQuote($quote);

            // @codeCoverageIgnoreStart
            $this
                ->logger
                ->critical(__('Razorpay front-end callback: Payment Failed with response:  ' . json_encode($params, 1)));
            // @codeCoverageIgnoreEnd

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

        $this->logger->info('razorpay_payment_id = '. $request['razorpay_payment_id']);
        $this->logger->info('razorpay_order_id = '. $this->razorpayOrderID);
        $this->logger->info('razorpay_signature = '. $request['razorpay_signature']);
        
        
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

