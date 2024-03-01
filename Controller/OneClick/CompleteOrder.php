<?php

namespace Razorpay\Magento\Controller\OneClick;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Framework\Pricing\Helper\Data;
use Razorpay\Magento\Model\PaymentMethod;
use Razorpay\Magento\Model\Config;
use Razorpay\Api\Api;
use Magento\Framework\App\Request\Http;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Quote\Model\ResourceModel\Quote\QuoteIdMask as QuoteIdMaskResourceModel;
use Magento\Sales\Model\Order\Payment\State\CaptureCommand;
use Magento\Sales\Model\Order\Payment\State\AuthorizeCommand;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory;
use Magento\Directory\Model\ResourceModel\Region\Collection;
use Razorpay\Magento\Controller\OneClick\StateMap;

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

    protected $collectionFactory;

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
    protected $stateNameMap;
    protected $_order = null;

    protected const STATUS_PROCESSING = 'processing';
    protected const COD               = 'cashondelivery';
    protected const RAZORPAY          = 'razorpay';

    /**
     * CompleteOrder constructor.
     * @param Http $request
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param CartManagementInterface $cartManagement
     * @param Data $priceHelper
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
        CollectionFactory $collectionFactory,
        StateMap $stateNameMap
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
        $this->collectionFactory  = $collectionFactory;
        $this->stateNameMap       = $stateNameMap;
        $this->resultRedirectFactory = $context->getResultFactory();;
        $this->orderStatus     = static::STATUS_PROCESSING;
        $this->authorizeCommand = new AuthorizeCommand();
        $this->captureCommand = new CaptureCommand();
    }

    public function execute()
    {
        $params = $this->request->getParams();

        $resultJson = $this->resultJsonFactory->create();

        $rzpOrderId = $params['razorpay_order_id'];
        $rzpPaymentId = $params['razorpay_payment_id'];

        $rzpOrderData = $this->rzp->order->fetch($rzpOrderId);
        $rzpPaymentData = $this->rzp->payment->fetch($rzpPaymentId);

        $cartId = isset($rzpOrderData->notes) ? $rzpOrderData->notes->cart_id : null;

        $quote = $this->cartRepositoryInterface->get($cartId);

        $this->updateQuote($quote, $rzpOrderData, $rzpPaymentData);

        $orderId = $this->cartManagement->placeOrder($cartId);
        $order = $this->order->load($orderId);
       
        $order->setEmailSent(0);
        if ($order)
        {
            if ($order->getStatus() === 'pending')
            {
                $order->setState(static::STATUS_PROCESSING)->setStatus($this->orderStatus);

                $this->logger->info('graphQL: Order Status Updated to ' . $this->orderStatus);
            }

            if(!empty($rzpOrderData->offers))
            {
                $discountAmount = $order->getDiscountAmount();

                $codFee = $rzpOrderData->cod_fee;

                $offerDiff = $rzpOrderData->line_items_total + $rzpOrderData->shipping_fee + $codFee - $rzpPaymentData->amount - ($discountAmount * 100);

                if($offerDiff > 0)
                {
                    $offerDiscount = ($offerDiff/100);
                    $newDiscountAmount = $discountAmount + $offerDiscount;
                    $this->updateDiscountAmount($orderId, $newDiscountAmount, $offerDiscount);

                }
            }

            $payment = $order->getPayment();

            $payment->setLastTransId($rzpPaymentId)
                    ->setTransactionId($rzpPaymentId)
                    ->setIsTransactionClosed(true)
                    ->setShouldCloseParentTransaction(true);

            $payment->setParentTransactionId($payment->getTransactionId());

            if ($this->config->getPaymentAction()  === \Razorpay\Magento\Model\PaymentMethod::ACTION_AUTHORIZE_CAPTURE)
            {
                $payment->addTransactionCommentsToOrder(
                    "$rzpPaymentId",
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
                    "$rzpPaymentId",
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
                && $rzpOrderData->status === 'paid')
            {
                $invoice = $this->invoiceService->prepareInvoice($order);
                $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                $invoice->setTransactionId($rzpPaymentId);
                $invoice->register();
                $invoice->save();

                $this->logger->info('graphQL: Created Invoice for '
                . 'order_id ' . $rzpOrderId . ', '
                . 'rzp_payment_id ' . $rzpPaymentId);

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
            else if($rzpOrderData->status === 'paid' and
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

            $result = [
                'status' => 'success'
            ];

            return $resultJson->setData($result);

        }
    }

    public function updateDiscountAmount($orderId, $newDiscountAmount, $offerAmount)
    {
        try {
            // Load the order
            $order = $this->order->load($orderId);

            // Update discount amount
            $order->setDiscountAmount($newDiscountAmount);
            $order->setBaseDiscountAmount($newDiscountAmount);

            $totalBaseGrandTotal = $order->getBaseGrandTotal();
            $totalGrandTotal = $order->getGrandTotal();

            $order->setBaseGrandTotal($totalBaseGrandTotal - $offerAmount);
            $order->setGrandTotal($totalGrandTotal - $offerAmount);


            return true;
        } catch (\Exception $e) {
            // Handle exception
            return false;
        }
    }

    protected function updateQuote($quote, $rzpOrderData, $rzpPaymentData)
    {
        $carrierCode = $rzpOrderData->notes->carrier_code ?? 'freeshipping';
        $methodCode = $rzpOrderData->notes->method_code ?? 'freeshipping';

        $email = $rzpOrderData->customer_details->email ?? '';

        $quote->setCustomerEmail($email);

        $shippingCountry = $rzpOrderData->customer_details->shipping_address->country;
        $shippingState = $rzpOrderData->customer_details->shipping_address->state;

        $billingingCountry = $rzpOrderData->customer_details->billing_address->country;
        $billingingState = $rzpOrderData->customer_details->billing_address->state;

        $shippingRegionCode = $this->getRegionCode($shippingCountry, $shippingState);
        $billingRegionCode = $this->getRegionCode($billingingCountry, $billingingState);

        $shipping = $this->getAddress($rzpOrderData->customer_details->shipping_address, $shippingRegionCode, $email);
        $billing = $this->getAddress($rzpOrderData->customer_details->billing_address, $billingRegionCode, $email);

        $quote->getBillingAddress()->addData($billing['address']);
        $quote->getShippingAddress()->addData($shipping['address']);

        $shippingMethod = 'NA';
        if(empty($carrierCode) === false && empty($methodCode) === false)
        {
            $shippingMethod = $carrierCode ."_". $methodCode;
        }

        $shippingAddress=$quote->getShippingAddress();
        $shippingAddress->setCollectShippingRates(true)
                        ->collectShippingRates()
                        ->setShippingMethod($shippingMethod);

        if(isset($rzpOrderData->promotions[0]->code) == true)
        {
            $quote->setCouponCode($rzpOrderData->promotions[0]->code);   
        }

        if($rzpPaymentData->method === 'cod')
        {
            $paymentMethod = static::COD;
        }
        else
        {
            $paymentMethod = static::RAZORPAY;
        }

        $quote->setPaymentMethod($paymentMethod);
        $quote->setInventoryProcessed(false);
        // Set Sales Order Payment
        $quote->getPayment()->importData(['method' => $paymentMethod]);

        $quote->save();
        
    }

    protected function getRegionCode($country, $state)
    {
        $magentoStateName = $this->stateNameMap->getMagentoStateName($country, $state);

        $this->logger->info('graphQL: Magento state name:' . $magentoStateName);

        $regionCode = $this->collectionFactory->create()
            ->addRegionNameFilter($magentoStateName)
            ->getFirstItem()
            ->toArray();

        return $regionCode['code'] ?? 'NA';

    }

    protected function getAddress($rzpAddress, $regionCode, $email)
    {
        $name = explode(' ', $rzpAddress->name);

        return [
            'email'   => $email, //buyer email id
            'address' =>[
                'firstname'  => $name[0], //address Details
                'lastname'   => $name[1]?? '.',
                    'street' => $rzpAddress->line1,
                    'city' => $rzpAddress->city,
                'country_id' => strtoupper($rzpAddress->country),
                'region' => $regionCode,
                'postcode' => $rzpAddress->zipcode,
                'telephone' => $rzpAddress->contact,
                'save_in_address_book' => 1
            ]
        ];
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
        $this->logger->info('razorpay_order_id = '. $request['razorpay_order_id']);
        $this->logger->info('razorpay_signature = '. $request['razorpay_signature']);
        
        
        $attributes = array(
            'razorpay_payment_id' => $request['razorpay_payment_id'],
            'razorpay_order_id' => $request['razorpay_order_id'],
            'razorpay_signature' => $request['razorpay_signature'],
        );

        $this
            ->rzp
            ->utility
            ->verifyPaymentSignature($attributes);
    }

}