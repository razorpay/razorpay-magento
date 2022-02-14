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
        Logger $logger
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

        $this->objectManagement   = \Magento\Framework\App\ObjectManager::getInstance();
        $this->catalogSession     = $catalogSession;
        $this->orderRepository    = $orderRepository;
        $this->orderSender        = $orderSender;

        $this->_invoiceService = $invoiceService;
        $this->_invoiceSender = $invoiceSender;
        $this->_transaction = $transaction;
        $this->logger       = $logger;
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
        $this->logger->info("Validate: Validation started for the incoming webhook");
        $post = $this->getPostData(); 

        if (json_last_error() !== 0)
        {
            return;
        }

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
            $order->setState(static::STATUS_PROCESSING)->setStatus(static::STATUS_PROCESSING);
            

            $payment = $order->getPayment();        
        
            $paymentId = $post['razorpay_payment_id'];
            
            $payment->setLastTransId($paymentId)
                ->setTransactionId($paymentId)
                ->setIsTransactionClosed(true)
                ->setShouldCloseParentTransaction(true);

            $payment->setParentTransactionId($payment->getTransactionId());

            if ($this->config->getPaymentAction()  === \Razorpay\Magento\Model\PaymentMethod::ACTION_AUTHORIZE_CAPTURE)
            {
                $payment->addTransactionCommentsToOrder(
                    "$paymentId",
                    (new CaptureCommand())->execute(
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
                    (new AuthorizeCommand())->execute(
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
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $quote = $objectManager->get('Magento\Quote\Model\Quote')->load($order->getQuoteId());
            $quote->setIsActive(false)->save();

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
                $order->setState(static::STATUS_PROCESSING)->setStatus(static::STATUS_PROCESSING);
                $order->addStatusHistoryComment(
                    __('Notified customer about invoice #%1.', $invoice->getId())
                )
                ->setIsCustomerNotified(true)
                ->save();
            }

            //send Order email, after successfull payment
            try
            {
                $this->checkoutSession->setRazorpayMailSentOnSuccess(true);
                $this->orderSender->send($order);
                $this->checkoutSession->unsRazorpayMailSentOnSuccess();
            }
            catch (\Magento\Framework\Exception\MailException $exception)
            {
                $this->logger->critical("Validate: MailException Error message:" . $exception->getMessage());
            }
            catch (\Exception $e)
            {
                $this->logger->critical("Validate: Exception Error message:" . $e->getMessage());
            }

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

        }
        catch(\Razorpay\Api\Errors\Error $e)
        {
            $this->logger->critical("Validate: Razorpay Error message:" . $e->getMessage());
            $responseContent['message'] = $e->getMessage();

            $code = $e->getCode();
        }
        catch(\Exception $e)
        {
            $this->logger->critical("Validate: Exception Error message:" . $e->getMessage());
            $responseContent['message'] = $e->getMessage();

            $code = $e->getCode();
        } 

        //update/disable the quote
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $quote = $objectManager->get('Magento\Quote\Model\Quote')->load($order->getQuoteId());
        $quote->setIsActive(true)->save();
        $this->checkoutSession->setFirstTimeChk('0');
        
        $response = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $response->setData($responseContent);
        $response->setHttpResponseCode($code);
        return $response;
    }


    protected function validateSignature($request)
    { 
        if(empty($request['error']) === false)
        {
            $this->logger->critical("Validate: Payment Failed or error from gateway");
            $this->messageManager->addError(__('Payment Failed'));
            throw new \Exception("Payment Failed or error from gateway");
        }

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