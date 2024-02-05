<?php

namespace Razorpay\Magento\Controller\OneClick;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Framework\Pricing\Helper\Data;
// use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Razorpay\Magento\Model\PaymentMethod;
use Razorpay\Magento\Model\Config;
use Razorpay\Api\Api;
use Magento\Framework\App\Request\Http;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\QuoteIdToMaskedQuoteIdInterface;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Quote\Model\ResourceModel\Quote\QuoteIdMask as QuoteIdMaskResourceModel;
use Magento\Sales\Model\Order\Payment\State\CaptureCommand;
use Magento\Sales\Model\Order\Payment\State\AuthorizeCommand;
use Magento\Sales\Block\Order\Totals;
use Magento\Quote\Api\Data\TotalsInterface;
use Magento\Framework\DataObject;

class CompleteOrder extends Action
{
    /**
     * @var Http
     */
    protected $request;

    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * @var CartManagementInterface
     */
    protected $cartManagement;

    /**
     * @var ProductRepositoryInterface
     */
    protected $productRepository;

    /**
     * @var Data
     */
    protected $priceHelper;

    /**
     * @var ScopeConfigInterface
     */
    protected $config;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    protected $rzp;

    protected $_totals;

    /**
     * @var QuoteItem
     */
    protected $quoteItem;

    protected $maskedQuoteIdInterface;

    private QuoteIdMaskFactory $quoteIdMaskFactory;

    private QuoteIdMaskResourceModel $quoteIdMaskResourceModel;

    protected $storeManager;

    protected $cartRepositoryInterface;

    protected $order;
    protected $invoiceService;
    protected $invoiceSender;
    protected $transaction;
    protected $orderSender;
    protected $authorizeCommand;
    protected $captureCommand;
    protected $orderStatus;
    protected $checkoutSession;
    protected $totals;
    protected $totalsInterface;
    protected $_order = null;

    protected const STATUS_PROCESSING = 'processing';

    /**
     * CompleteOrder constructor.
     * @param Http $request
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param CartManagementInterface $cartManagement
     * @param Data $priceHelper
     * @param ScopeConfigInterface $config
     * @param PaymentMethod $paymentMethod
     * @param \Psr\Log\LoggerInterface $logger
     * @param ProductRepositoryInterface $productRepository
     */
    public function __construct(
        Context $context,
        Http $request,
        JsonFactory $jsonFactory,
        CartManagementInterface $cartManagement,
        Data $priceHelper,
        PaymentMethod $paymentMethod,
        \Razorpay\Magento\Model\Config $config,
        \Psr\Log\LoggerInterface $logger,
        ProductRepositoryInterface $productRepository,
        Item $quoteItem,
        QuoteIdToMaskedQuoteIdInterface $maskedQuoteIdInterface,
        QuoteIdMaskFactory $quoteIdMaskFactory,
        QuoteIdMaskResourceModel $quoteIdMaskResourceModel,
        StoreManagerInterface $storeManager,
        \Magento\Quote\Api\CartRepositoryInterface $cartRepositoryInterface,
        \Magento\Sales\Model\Order $order,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender,
        \Magento\Framework\DB\Transaction $transaction,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
        \Magento\Checkout\Model\Session $checkoutSession,
        Totals $totals,
        TotalsInterface $totalsInterface
    ) {
        parent::__construct($context);
        $this->request = $request;
        $this->resultJsonFactory = $jsonFactory;
        $this->cartManagement = $cartManagement;
        $this->priceHelper = $priceHelper;
        $this->config = $config;
        $this->rzp    = $paymentMethod->setAndGetRzpApiInstance();
        $this->logger = $logger;
        $this->productRepository = $productRepository;
        $this->quoteItem = $quoteItem;
        $this->maskedQuoteIdInterface = $maskedQuoteIdInterface;
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->quoteIdMaskResourceModel = $quoteIdMaskResourceModel;
        $this->storeManager = $storeManager;
        $this->cartRepositoryInterface = $cartRepositoryInterface;
        $this->order = $order;
        $this->invoiceService  = $invoiceService;
        $this->invoiceSender   = $invoiceSender;
        $this->transaction     = $transaction;
        $this->orderSender     = $orderSender;
        $this->checkoutSession  = $checkoutSession;
        $this->totals  = $totals;
        $this->totalsInterface  = $totalsInterface;
        $this->resultRedirectFactory = $context->getResultFactory();;
        $this->orderStatus     = static::STATUS_PROCESSING;
        $this->authorizeCommand = new AuthorizeCommand();
        $this->captureCommand = new CaptureCommand();
    }

    public function execute()
    {
        $params = $this->request->getParams();

        $rzp_order_id = $params['razorpay_order_id'];
        $rzp_payment_id = $params['razorpay_payment_id'];

        $rzp_order_data = $this->rzp->order->fetch($rzp_order_id);
        $rzp_payment_data = $this->rzp->payment->fetch($rzp_payment_id);
        $receipt = isset($rzp_order_data->receipt) ? $rzp_order_data->receipt : null;
        $cart_id = isset($rzp_order_data->notes) ? $rzp_order_data->notes->cart_id : null;
        $this->logger->info('graphQL: Razorpay Order receipt:' . $cart_id);

        $quote = $this->cartRepositoryInterface->get($cart_id);

        $quote->setCustomerEmail($rzp_order_data->customer_details->email);
        // $quote->setCustomerEmail('chetan.naik@razorpay.com');

        $name = explode(' ', $rzp_order_data->customer_details->shipping_address->name);


        $tempOrder=[
             'email'        => $rzp_order_data->customer_details->email, //buyer email id
             // 'email'        => 'chetan.naik@razorpay.com',
             'shipping_address' =>[
                    'firstname'      => $name[0], //address Details
                    'lastname'       => $name[1]?? '.',
                            'street' => $rzp_order_data->customer_details->shipping_address->line1,
                            'city' => $rzp_order_data->customer_details->shipping_address->city,
                    'country_id' => strtoupper($rzp_order_data->customer_details->shipping_address->country),
                    'region' => 'KA',
                    'postcode' => $rzp_order_data->customer_details->shipping_address->zipcode,
                    'telephone' => $rzp_order_data->customer_details->shipping_address->contact,
                    'save_in_address_book' => 1
                         ]
        ];
        $quote->getBillingAddress()->addData($tempOrder['shipping_address']);
        // $quote->getShippingAddress()->addData($tempOrder['shipping_address']);

        if($rzp_order_data->shipping_fee > 0)
        {
            $shippingMethod = 'flatrate_flatrate';
        }
        else
        {
            $shippingMethod = 'freeshipping_freeshipping';
        }

        $shippingAddress=$quote->getShippingAddress();
        $shippingAddress->setCollectShippingRates(true)
                        ->collectShippingRates()
                        ->setShippingMethod($shippingMethod);


        if(isset($rzp_order_data->promotions[0]->code) == true)
        {
         $quote->setCouponCode($rzp_order_data->promotions[0]->code);   
        }

        if($rzp_payment_data->method === 'cod')
        {
            $paymentMethod = 'cashondelivery';
        }
        else {
            $paymentMethod = 'razorpay';
        }

        $quote->setPaymentMethod($paymentMethod); //payment method
        $quote->setInventoryProcessed(false); //not effetc inventory
        // Set Sales Order Payment
        $quote->getPayment()->importData(['method' => $paymentMethod]);

        $quote->save();

        $orderId = $this->cartManagement->placeOrder($cart_id);
        $order = $this->order->load($orderId);
       
        $order->setEmailSent(0);
        $increment_id = $order->getRealOrderId();
        if ($order)
            {
                // $amountPaid = number_format($rzpOrderAmount / 100, 2, ".", "");
                if ($order->getStatus() === 'pending')
                {
                    $order->setState(static::STATUS_PROCESSING)->setStatus($this->orderStatus);

                    $this->logger->info('graphQL: Order Status Updated to ' . $this->orderStatus);
                }

                $payment = $order->getPayment();

                $payment->setLastTransId($rzp_payment_id)
                        ->setTransactionId($rzp_payment_id)
                        ->setIsTransactionClosed(true)
                        ->setShouldCloseParentTransaction(true);

                $payment->setParentTransactionId($payment->getTransactionId());

                if ($this->config->getPaymentAction()  === \Razorpay\Magento\Model\PaymentMethod::ACTION_AUTHORIZE_CAPTURE)
                {
                    $payment->addTransactionCommentsToOrder(
                        "$rzp_payment_id",
                        $this->captureCommand->execute(
                            $payment,
                            $order->getGrandTotal(),
                            $order
                        ),
                        ""
                    );
                } else
                {
                    $payment->addTransactionCommentsToOrder(
                        "$rzp_payment_id",
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

                $this->logger->info('Payment authorized completed for id : '. $order->getIncrementId());

                if ($order->canInvoice() && $this->config->canAutoGenerateInvoice()
                    && $rzp_order_data->status === 'paid')
                {
                    $invoice = $this->invoiceService->prepareInvoice($order);
                    $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                    $invoice->setTransactionId($rzp_payment_id);
                    $invoice->register();
                    $invoice->save();

                    $this->logger->info('graphQL: Created Invoice for '
                    . 'order_id ' . $rzp_order_id . ', '
                    . 'rzp_payment_id ' . $rzp_payment_id);

                    $transactionSave = $this->transaction
                        ->addObject($invoice)
                        ->addObject($invoice->getOrder());
                    $transactionSave->save();

                    $this->invoiceSender->send($invoice);

                    $order->addStatusHistoryComment(
                        __('Notified customer about invoice #%1.', $invoice->getId())
                    )->setIsCustomerNotified(true);

                    $this->logger->info('Invoice generated for id : '. $order->getIncrementId());
                }
                else if($rzp_order_data->status === 'paid' and
                        ($order->canInvoice() === false or
                        $this->config->canAutoGenerateInvoice() === false))
                {
                    $this->logger->info('Invoice generation not possible for id : '. $order->getIncrementId());
                }

                try
                {
                    $this->checkoutSession->setRazorpayMailSentOnSuccess(true);
                    $this->orderSender->send($order);
                    $this->checkoutSession->unsRazorpayMailSentOnSuccess();
                }
                catch (\Magento\Framework\Exception\MailException $e)
                {
                    $this->logger->critical('graphQL: '
                    . 'Razorpay Error:' . $e->getMessage());

                    throw new GraphQlInputException(__('Razorpay Error: %1.', $e->getMessage()));
                }
                catch (\Exception $e)
                {
                    $this->logger->critical('graphQL: '
                    . 'Error:' . $e->getMessage());

                    throw new GraphQlInputException(__('Error: %1.', $e->getMessage()));
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

                $order->save();

                return $this->_redirect('checkout/onepage/success');

            }

    }

    public function addTotalBeforeCOD(\Magento\Framework\DataObject $total, $before = null)
    {
        if ($before !== null) {
            if (!is_array($before)) {
                $before = [$before];
                echo "is_array";
                var_dump($before);

            }
            foreach ($before as $beforeTotals) {
                echo "foreach";
                var_dump($beforeTotals);
                if (isset($this->_totals[$beforeTotals])) {
                    $totals = [];
                    echo "isset";
                    foreach ($this->_totals as $code => $item) {
                        if ($code == $beforeTotals) {
                            echo "code";
                var_dump($beforeTotals);
                            $totals[$total->getCode()] = $total;
                        }
                        $totals[$code] = $item;
                    }
                    echo "totals";
                var_dump($totals);
                    $this->_totals = $totals;
                    return $this;
                }
            }
        }
        $totals = [];
        $first = array_shift($this->_totals);
        $totals[$first->getCode()] = $first;
        $totals[$total->getCode()] = $total;
        foreach ($this->_totals as $code => $item) {
            $totals[$code] = $item;
        }
        $this->_totals = $totals;
        return $this;
    }
}