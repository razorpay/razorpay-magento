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
use Magento\Sales\Model\Order\Payment\State\CaptureCommand;
use Magento\Sales\Model\Order\Payment\State\AuthorizeCommand;
use Psr\Log\LoggerInterface as Logger;
use Razorpay\Magento\Constants\OrderCronStatus;
use Razorpay\Magento\Model\TrackPluginInstrumentation;

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

    protected $enableCustomPaidOrderStatus;

    protected $orderStatus;

    const STATUS_APPROVED = 'APPROVED';
    const STATUS_PROCESSING = 'processing';
    const AUTHORIZED = 'authorized';
    const CAPTURED = 'captured';

    /**
     * @var \Magento\Sales\Model\Service\InvoiceService
     */
    protected $_invoiceService;
    protected $_invoiceSender;

    /**
     * @var \Magento\Framework\DB\Transaction
     */
    protected $_transaction;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var \Razorpay\Magento\Model\Config
     */
    protected $config;

    /**
     * @var \Magento\Catalog\Model\Session
     */
    protected $catalogSession;

    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var  \Magento\Sales\Model\Order\Payment\State\AuthorizeCommand
     */
    protected $authorizeCommand;

    /**
     * @var \Magento\Sales\Model\Order\Payment\State\CaptureCommand
     */
    protected $captureCommand;

    /**
     * @var \Razorpay\Magento\Model\TrackPluginInstrumentation
     */
    protected $trackPluginInstrumentation;

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
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Framework\DB\Transaction $transaction,
        \Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender,
        OrderRepositoryInterface $orderRepository,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
        Logger $logger,
        TrackPluginInstrumentation $trackPluginInstrumentation
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
        $this->config          = $config;

        $this->catalogSession     = $catalogSession;
        $this->orderRepository    = $orderRepository;
        $this->orderSender        = $orderSender;
        $this->_invoiceService    = $invoiceService;
        $this->_invoiceSender     = $invoiceSender;
        $this->_transaction       = $transaction;
        $this->logger             = $logger;
        $this->orderStatus        = static::STATUS_PROCESSING;

        $this->enableCustomPaidOrderStatus = $this->config->isCustomPaidOrderStatusEnabled();

        if ($this->enableCustomPaidOrderStatus === true
            && empty($this->config->getCustomPaidOrderStatus()) === false)
        {
            $this->orderStatus = $this->config->getCustomPaidOrderStatus();
        }

        $this->authorizeCommand = new AuthorizeCommand();
        $this->captureCommand = new CaptureCommand();
        $this->trackPluginInstrumentation = $trackPluginInstrumentation;
    }

    // @codeCoverageIgnoreStart
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
    // @codeCoverageIgnoreEnd

    /**
     * Processes the incoming webhook
     */
    public function execute()
    {
        $post = $this->getPostData(); 

        // @codeCoverageIgnoreStart
        $this->logger->info("Handler Signature Validate: RZP Signature Validation started.". print_r($post, true));
        // @codeCoverageIgnoreEnd

        // @codeCoverageIgnoreStart
        if (json_last_error() !== 0)
        {
            return;
        }
        // @codeCoverageIgnoreEnd

        $order = $this->checkoutSession->getLastRealOrder();

        $responseContent = [
                'success'       => false,
                'redirect_url'  => 'checkout/#payment',
                'parameters'    => []
            ];

        try
        {
            $this->validateSignature($post);

            $orderId = $order->getIncrementId();
            $order->setState(static::STATUS_PROCESSING)->setStatus($this->orderStatus);
            

            $payment = $order->getPayment();        
        
            $paymentId = $post['razorpay_payment_id'];

            $rzpPayment = $this->rzp->request->request('GET', 'payments/'.$paymentId);
        
            $payment->setLastTransId($paymentId)
                ->setTransactionId($paymentId)
                ->setIsTransactionClosed(true)
                ->setShouldCloseParentTransaction(true);

            $payment->setParentTransactionId($payment->getTransactionId());

            if ($this->config->getPaymentAction()  === \Razorpay\Magento\Model\PaymentMethod::ACTION_AUTHORIZE_CAPTURE)
            {
                $payment->addTransactionCommentsToOrder(
                    "$paymentId",
                    $this->captureCommand->execute(
                        $payment,
                        $order->getGrandTotal(),
                        $order
                    ),
                    ""
                );
            }
            else
            {
                $payment->addTransactionCommentsToOrder(
                    "$paymentId",
                    $this->authorizeCommand->execute(
                        $payment,
                        $order->getGrandTotal(),
                        $order
                    ),
                    ""
                );
            }

            $transaction = $payment->addTransaction(\Magento\Sales\Model\Order\Payment\Transaction::TYPE_AUTH, null, true, "");
            $transaction->setIsClosed(true);
            $transaction->save();

            $order->save();

            $this->orderRepository->save($order);

            //update/disable the quote
            $quote = $this->_objectManager->get('Magento\Quote\Model\Quote')->load($order->getQuoteId());
            $quote->setIsActive(false)->save();

            $amountPaid = number_format($rzpPayment['amount'] / 100, 2, ".", "");
            
            $order->addStatusHistoryComment(
                __(
                    'Actual Amount %1 of %2, with Razorpay Offer/Fee applied.',
                    $rzpPayment['status'],
                    $order->getBaseCurrency()->formatTxt($amountPaid)
                )
            );

            $orderLink = $this->_objectManager->get('Razorpay\Magento\Model\OrderLink')
                            ->getCollection()
                            ->addFilter('order_id', $order->getEntityId())
                            ->getFirstItem();

            $orderLink->setRzpPaymentId($paymentId);

            $orderLink->setRzpUpdateOrderCronStatus(OrderCronStatus::PAYMENT_AUTHORIZED_COMPLETED);

            $this->logger->info('Payment authorized completed for id : '. $order->getIncrementId());

            if($order->canInvoice() and
                ($this->config->getPaymentAction()  === \Razorpay\Magento\Model\PaymentMethod::ACTION_AUTHORIZE_CAPTURE) and
                $this->config->canAutoGenerateInvoice())
            {
                $invoice = $this->_invoiceService->prepareInvoice($order);
                $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                $invoice->setTransactionId($paymentId);
                $invoice->register();
                $invoice->save();
                $transactionSave = $this->_transaction->addObject($invoice)
                                                      ->addObject($invoice->getOrder());
                $transactionSave->save();

                $this->_invoiceSender->send($invoice);
                //send notification code
                $order->setState(static::STATUS_PROCESSING)->setStatus($this->orderStatus);
                $order->addStatusHistoryComment(
                    __('Notified customer about invoice #%1.', $invoice->getId())
                )
                ->setIsCustomerNotified(true)
                ->save();

                $orderLink->setRzpUpdateOrderCronStatus(OrderCronStatus::INVOICE_GENERATED);

                $this->logger->info('Invoice generated for id : '. $order->getIncrementId());
            }
            else if($this->config->getPaymentAction()  === \Razorpay\Magento\Model\PaymentMethod::ACTION_AUTHORIZE_CAPTURE and
                    ($order->canInvoice() === false or
                    $this->config->canAutoGenerateInvoice() === false))
            {
                // This ensures the custom status flow is maintained after payment authorization and capture
                $order->setState(static::STATUS_PROCESSING)->setStatus($this->orderStatus);
                
                $orderLink->setRzpUpdateOrderCronStatus(OrderCronStatus::INVOICE_GENERATION_NOT_POSSIBLE);
                
                $this->logger->info('Invoice generation not possible for id : '. $order->getIncrementId());
            } 
            else if ($this->config->getPaymentAction() === \Razorpay\Magento\Model\PaymentMethod::ACTION_AUTHORIZE) 
            {
                // For authorize-only payments, set the order status and state
                // This ensures the custom status flow is maintained after payment authorization
                $order->setState(static::STATUS_PROCESSING)->setStatus($this->orderStatus);
                
                $this->logger->info('Order status updated for authorize-only payment for id: ' . $order->getIncrementId());
            }
            $orderLink->save();
            $order->save();

            //send Order email, after successfull payment
            try
            {
                $this->checkoutSession->setRazorpayMailSentOnSuccess(true);
                $this->orderSender->send($order);
                $this->checkoutSession->unsRazorpayMailSentOnSuccess();
            } // @codeCoverageIgnoreStart
            catch (\Magento\Framework\Exception\MailException $exception)
            {
                $this->logger->critical("Validate: MailException Error message:" . $exception->getMessage());

                $properties = [
                    "error_message" => $exception->getMessage(),
                    "file_path" => "controller/Payment/Validate.php",
                    "exception_type" => get_class($exception),
                    "notes" => "Error while sending order email after payment success"
                ];

                $this->trackPluginInstrumentation->rzpTrackDataLake('razorpay.std.handler.sent.email.failed', $properties);

            }
            catch (\Exception $e)
            {
                $this->logger->critical("Validate: Exception Error message:" . $e->getMessage());

                $properties = [
                    "error_message" => $e->getMessage(),
                    "file_path" => "controller/Payment/Validate.php",
                    "exception_type" => get_class($e),
                    "notes" => "Error while sending order email after payment success"
                ];

                $this->trackPluginInstrumentation->rzpTrackDataLake('razorpay.std.handler.sent.email.failed', $properties);
            }
            // @codeCoverageIgnoreEnd

            $responseContent = [
                'success'           => true,
                'redirect_url'         => 'checkout/onepage/success/',
                'order_id'  => $orderId,
            ];

            $code = 200;

            $response = $this->resultFactory->create(ResultFactory::TYPE_JSON);
            $response->setData($responseContent);
            $response->setHttpResponseCode($code);
            return $response;

        } // @codeCoverageIgnoreStart
        catch(\Razorpay\Api\Errors\Error $e)
        {
            $this->logger->critical("Validate: Razorpay Error message:" . $e->getMessage());
            $responseContent['message'] = $e->getMessage();

            $code = $e->getCode();

            $properties = [
                "error_message" => $e->getMessage(),
                "file_path" => "controller/Payment/Validate.php",
                "exception_type" => get_class($e),
                "notes" => "Error while validating order failed."
            ];

            $this->trackPluginInstrumentation->rzpTrackDataLake('razorpay.std.validate.handler.failed', $properties);
        }
        catch(\Exception $e)
        {
            $this->logger->critical("Validate: Exception Error message:" . $e->getMessage());
            $responseContent['message'] = $e->getMessage();

            $code = $e->getCode();

            $properties = [
                "error_message" => $e->getMessage(),
                "file_path" => "controller/Payment/Validate.php",
                "exception_type" => get_class($e),
                "notes" => "Error while validating order failed."
            ];

            $this->trackPluginInstrumentation->rzpTrackDataLake('razorpay.std.validate.handler.failed', $properties);
        }
        // @codeCoverageIgnoreEnd

        //update/disable the quote
        $quote = $this->_objectManager->get('Magento\Quote\Model\Quote')->load($order->getQuoteId());
        $quote->setIsActive(true)->save();
        $this->checkoutSession->setFirstTimeChk('0');
        
        $response = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $response->setData($responseContent);
        $response->setHttpResponseCode($code);
        return $response;
    }

    protected function validateSignature($request)
    { 
        // @codeCoverageIgnoreStart
        if(empty($request['error']) === false)
        {
            $this->logger->critical("Validate: Payment Failed or error from gateway");
            $this->messageManager->addError(__('Payment Failed'));

            $properties = [
                "error_message" => "Payment Failed or error from gateway",
                "file_path" => "controller/Payment/Validate.php",
                "exception_type" => null,
                "notes" => "validateSignature failed"
            ];

            $this->trackPluginInstrumentation->rzpTrackDataLake('razorpay.std.handler.validate.signature.failed', $properties);
            
            throw new \Exception("Payment Failed or error from gateway");
        }
        // @codeCoverageIgnoreEnd

        $this->logger->info('RZP signature validate: razorpay_payment_id = ' . $request['razorpay_payment_id'] . ', razorpay_order_id = ' . $this->catalogSession->getRazorpayOrderID()  . ', razorpay_signature = ' . $request['razorpay_signature']);
       

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
        $request = $this->fileGetContents();

        return json_decode($request, true);
    }

    // @codeCoverageIgnoreStart
    protected function fileGetContents()
    {
        return file_get_contents('php://input');
    }
    // @codeCoverageIgnoreEnd
}
