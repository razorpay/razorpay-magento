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
use Razorpay\Magento\Model\CartConverter;
use Razorpay\Magento\Model\CustomerConsent;
use Razorpay\Magento\Constants\OrderCronStatus;
use Razorpay\Magento\Model\TrackPluginInstrumentation;

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
    protected $cartConverter;
    protected $customerConsent;
    protected $_order = null;
    protected $trackPluginInstrumentation;

    const COD = 'cashondelivery';
    const RAZORPAY = 'razorpay';
    const STATE_PENDING_PAYMENT = 'pending_payment';
    const STATE_PROCESSING = 'processing';
    const STATE_PARTIALLY_PAID = 'partially_paid';
    const QUOTE_LINKED_RAZORPAY_ORDER_ID = "quote_linked_razorpay_order_id";
    const INVENTORY_OUT_OF_STOCK = "Some of the products are out of stock";
    const MAX_ATTEMPTS = "3";

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
        Context                                               $context,
        Http                                                  $request,
        JsonFactory                                           $jsonFactory,
        CartManagementInterface                               $cartManagement,
        Data                                                  $priceHelper,
        PaymentMethod                                         $paymentMethod,
        \Razorpay\Magento\Model\Config                        $config,
        \Psr\Log\LoggerInterface                              $logger,
        ProductRepositoryInterface                            $productRepository,
        Item                                                  $quoteItem,
        QuoteIdMaskFactory                                    $quoteIdMaskFactory,
        QuoteIdMaskResourceModel                              $quoteIdMaskResourceModel,
        StoreManagerInterface                                 $storeManager,
        \Magento\Quote\Api\CartRepositoryInterface            $cartRepositoryInterface,
        \Magento\Sales\Model\Order                            $order,
        \Magento\Sales\Model\Service\InvoiceService           $invoiceService,
        \Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender,
        \Magento\Framework\DB\Transaction                     $transaction,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender   $orderSender,
        \Magento\Checkout\Model\Session                       $checkoutSession,
        CollectionFactory                                     $collectionFactory,
        StateMap                                              $stateNameMap,
        CartConverter                                         $cartConverter,
        CustomerConsent                                       $customerConsent,
        TrackPluginInstrumentation                            $trackPluginInstrumentation
    )
    {
        parent::__construct($context);
        $this->request = $request;
        $this->resultJsonFactory = $jsonFactory;
        $this->cartManagement = $cartManagement;
        $this->priceHelper = $priceHelper;
        $this->config = $config;
        $this->rzp = $paymentMethod->setAndGetRzpApiInstance();
        $this->logger = $logger;
        $this->productRepository = $productRepository;
        $this->quoteItem = $quoteItem;
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->quoteIdMaskResourceModel = $quoteIdMaskResourceModel;
        $this->storeManager = $storeManager;
        $this->cartRepositoryInterface = $cartRepositoryInterface;
        $this->order = $order;
        $this->invoiceService = $invoiceService;
        $this->invoiceSender = $invoiceSender;
        $this->transaction = $transaction;
        $this->orderSender = $orderSender;
        $this->checkoutSession = $checkoutSession;
        $this->collectionFactory = $collectionFactory;
        $this->stateNameMap = $stateNameMap;
        $this->cartConverter = $cartConverter;
        $this->customerConsent = $customerConsent;
        $this->resultRedirectFactory = $context->getResultFactory();;
        $this->orderStatus = static::STATE_PROCESSING;
        $this->authorizeCommand = new AuthorizeCommand();
        $this->captureCommand = new CaptureCommand();
        $this->trackPluginInstrumentation = $trackPluginInstrumentation;
    }

    public function execute()
    {
        $params = $this->request->getParams();

        $resultJson = $this->resultJsonFactory->create();

        $rzpOrderId = $params['razorpay_order_id'];
        $rzpPaymentId = $params['razorpay_payment_id'];

        try {
            $rzpOrderData = $this->rzp->order->fetch($rzpOrderId);
            $rzpPaymentData = $this->rzp->payment->fetch($rzpPaymentId);

            $cartMaskId = isset($rzpOrderData->notes) ? $rzpOrderData->notes->cart_mask_id : null;

            $this->validateSignature($params, $cartMaskId);

            $cartId = isset($rzpOrderData->notes) ? $rzpOrderData->notes->cart_id : null;
            $email = $rzpOrderData->customer_details->email ?? null;

            $quote = $this->cartRepositoryInterface->get($cartId);
            $quote->setIsActive(true)->save();

            $this->updateQuote($quote, $rzpOrderData, $rzpPaymentData);

            $quoteId = $rzpOrderData->notes->cart_mask_id;

            // Set customer to quote
            $customerCartId = $this->cartConverter->convertGuestCartToCustomer($cartId);
            $this->logger->info('graphQL: customerCartId ' . $customerCartId);

            $isCustomerConsentSet = isset($rzpOrderData->notes) ? $rzpOrderData->notes->customer_consent : false;

            if ($isCustomerConsentSet) {
                // Subscribe news letter based on customer consent data
                $subscribeNewsLetter = $this->customerConsent->subscribeCustomer($customerCartId, $email);
                $this->logger->info('graphQL: news letter subscribed? ' . json_encode($subscribeNewsLetter));
            }

            $result = $this->placeMagentoOrder($cartId, $rzpPaymentData, $rzpOrderData);

            return $resultJson->setData($result);
            
        } catch (\Razorpay\Api\Errors\Error $e) {
            $this->logger->critical("Validate: Razorpay Error message:" . $e->getMessage());

            $code = $e->getCode();
            $this->messageManager->addError(__('Payment Failed.'));

            $properties = [
                'error_message' => $e->getMessage(),
                'file_path' => 'controller/OneClick/CompleteOrder.php',
                'exception_type' => get_class($e),
                'notes' => 'complete order failed'
            ];

            $this->trackPluginInstrumentation->rzpTrackDataLake('razorpay.1cc.complete.order.failed', $properties);

            return $resultJson->setData([
                'status' => 'error',
                'code' => $code,
                'message' => __('An error occurred on the server. Please try again after sometime.' . $e->getMessage()),
            ])->setHttpResponseCode(500);
        } catch (\Exception $e) {
            $this->logger->critical("Validate: Exception Error message:" . $e->getMessage());
            $this->messageManager->addError(__('Payment Failed.'));

            $code = $e->getCode();

            if (strpos($e->getMessage(), static::INVENTORY_OUT_OF_STOCK) !== false) {
                if ($rzpPaymentData->method != 'cod') {
                    $refundData = [
                        'amount' => $rzpPaymentData['amount'],
                        'receipt' => $rzpOrderData['receipt'],
                        'notes' => [
                            'reason' => $e->getMessage(),
                            'order_id' => $rzpOrderData['receipt'],
                            'refund_from_magic' => true,
                            'source' => 'Magento',
                        ]
                    ];

                    $orderLink = $this->_objectManager->get('Razorpay\Magento\Model\OrderLink')
                        ->getCollection()
                        ->addFilter('order_id', $rzpOrderData['receipt'])
                        ->getFirstItem();

                    $orderLink->setRzpUpdateOrderCronStatus(OrderCronStatus::ORDER_NOT_PLACED_DUE_TO_STOCK_UNAVAILABILITY);

                    $orderLink->save();

                    try {
                        $refund = $this->rzp->payment
                            ->fetch($rzpPaymentId)
                            ->refund($refundData);
                    } catch (\Exception $e) {
                        $this->logger->critical("Razorpay refund failed" . $e->getMessage());

                        $properties = [
                            'error_message' => $e->getMessage(),
                            'file_path' => 'controller/OneClick/CompleteOrder.php',
                            'exception_type' => get_class($e),
                            'notes' => 'razorpay refund failed in complete order'
                        ];
                        $this->trackPluginInstrumentation->rzpTrackDataLake('razorpay.1cc.refund.failed', $properties);
                    }
                }

                $this->messageManager->addError(__($e->getMessage()));
            } else {
                $properties = [
                    'error_message' => $e->getMessage(),
                    'file_path' => 'controller/OneClick/CompleteOrder.php',
                    'exception_type' => get_class($e),
                    'notes' => 'complete order failed'
                ];
                $this->trackPluginInstrumentation->rzpTrackDataLake('razorpay.1cc.complete.order.failed', $properties);
            }

            return $resultJson->setData([
                'status' => 'error',
                'code' => $code,
                'message' => __('An error occurred on the server. Please try again.' . $e->getMessage()),
            ])->setHttpResponseCode(500);
        }
    }

    public function placeMagentoOrder($cartId, $rzpPaymentData, $rzpOrderData)
    {
        $merchantOrderId = isset($rzpOrderData->notes) ? $rzpOrderData->notes->merchant_order_id : null;

        $orderLink = $this->_objectManager->get('Razorpay\Magento\Model\OrderLink')
            ->getCollection()
            ->addFilter('order_id', $merchantOrderId)
            ->getFirstItem();

        $rzpOrderId = $rzpOrderData->id;
        $rzpPaymentId = $rzpPaymentData->id;

        $rzpOrderId = $rzpOrderData->id;
        $rzpPaymentId = $rzpPaymentData->id;

        //Callback will retry MAX_ATTEMPTS to place order on magento.
        $attempts = 0;
        while ($attempts < self::MAX_ATTEMPTS) {
            $attempts++;
            try {
                $order = $this->order->loadByIncrementId($merchantOrderId);

                if (!$order->getId()) {
                    $orderId = $this->cartManagement->placeOrder($cartId);
                    $order = $this->order->load($orderId);
                } else {
                    $orderId = $order->getId();
                }
                break;
            } catch (\Exception $e) {
                $this->logger->critical("Magento order placement is failed for " . $rzpOrderId . " in " . $attempts . " attempt and the error message is - " . $e->getMessage());

                if ($attempts == self::MAX_ATTEMPTS) {
                    $this->logger->critical("All attempts to place the Magento order have failed for rzp order id " . $rzpOrderId . " & rzp payment id " . $rzpPaymentId . " & magento cart id " . $cartId);

                    $properties = [
                        'error_message' => $e->getMessage(),
                        'file_path' => 'controller/OneClick/CompleteOrder.php',
                        'exception_type' => get_class($e),
                        'notes' => 'magento order placement failed in all attempts'
                    ];
                    $this->trackPluginInstrumentation->rzpTrackDataLake('razorpay.1cc.order.placement.failed', $properties);
                    throw new \Exception("Magento order creation failed with error message." . $e->getMessage());
                }
                continue;
            }
        }

        $order->setEmailSent(0);
        if ($order) {
            // Return to failure page if payment is failed.
            if ($rzpPaymentData->status === 'failed') {
                $this->logger->critical("Razorpay payment is failed for the order id " . $rzpOrderId);
            }

            $isPartialCodOrder = $this->isPartialCod($rzpOrderData->status, $rzpPaymentData->status);

            if ($order->getStatus() === 'pending') {
                if ($rzpPaymentData->status === 'pending' && $rzpPaymentData->method === 'cod') {
                    $order->setState(static::STATE_PENDING_PAYMENT)
                        ->setStatus(static::STATE_PENDING_PAYMENT);
                } else {
                    $order->setState(static::STATE_PROCESSING)
                        ->setStatus(static::STATE_PROCESSING);
                }

                $this->logger->info('graphQL: Order Status Updated to ' . $this->orderStatus . " for order id " . $rzpOrderId);
            }
            
            //Check if any razorpay offer is applied or not and not COD/partial COD(because rzp payment offer is not applicable for Partial COD's partially paid amount)
            if (($rzpOrderData->amount !== $rzpOrderData->amount_paid) && $rzpPaymentData->method != 'cod' && $isPartialCodOrder != 1) {
                $this->logger->info('graphQL: Entering offer discount calculation - Amount mismatch detected and not COD/partial COD');
                $discountAmount = $order->getDiscountAmount();

                $codFee = $rzpOrderData->cod_fee;
                $totalPaid = $rzpPaymentData->amount;

                $rzpPromotionAmount = 0;

                if (empty($rzpOrderData->promotions) === false) {
                    foreach ($rzpOrderData->promotions as $promotion) {
                        if (empty($promotion['code']) === false) {
                            $rzpPromotionAmount = $promotion['value'];
                        }
                    }
                }

                $offerDiff = $rzpOrderData->line_items_total + $rzpOrderData->shipping_fee - $totalPaid - $rzpPromotionAmount;

                if ($offerDiff > 0) {
                    $offerDiscount = ($offerDiff / 100);
                    // abs is used here as discount amount is returned as minus from order object.
                    $newDiscountAmount = abs($discountAmount) + $offerDiscount;

                    $this->logger->info('graphQL: offerDiscount ' . $offerDiscount);
                    $this->logger->info('graphQL: newDiscountAmount ' . $newDiscountAmount);
                    $this->logger->info('graphQL: offerDiff ' . $offerDiff);
                    $this->logger->info('graphQL: codFee ' . $codFee);
                    $this->logger->info('graphQL: discountAmount ' . $discountAmount);

                    $this->updateDiscountAmount($orderId, $newDiscountAmount, $offerDiscount, $totalPaid);
                }
            }
            
            if ($isPartialCodOrder == 1) {
                //set proper paid and pending amount to order
                $amountDue = $rzpOrderData->amount_due ?? 0;
                $amountPaid = $rzpOrderData->amount_paid ?? 0;
                $codFee = $rzpOrderData->cod_fee ?? 0;

                $this->updateOrderDue($orderId, $amountPaid, $amountDue);
                
                // Store prepaid and COD amounts separately for display
                $prepaidAmount = $amountPaid / 100;
                $codAmount = $amountDue / 100;
                
                $order->setData('razorpay_prepaid_amount', $prepaidAmount);
                $order->setData('razorpay_cod_amount', $codAmount);

                $partialCodComment = __(
                    'Partial COD payment - Prepaid: ₹%1, COD Amount Due: ₹%2',
                    number_format($amountPaid / 100, 2),
                    number_format($amountDue / 100, 2)
                );
                $order->addStatusHistoryComment(
                    $partialCodComment
                )->setStatus($order->getStatus())->setIsCustomerNotified(false);
            }

            $payment = $order->getPayment();

            $payment->setLastTransId($rzpPaymentId)
                ->setTransactionId($rzpPaymentId)
                ->setIsTransactionClosed(true)
                ->setShouldCloseParentTransaction(true);

            $payment->setParentTransactionId($payment->getTransactionId());

            if ($rzpPaymentData->method != 'cod') {
                if ($this->config->getPaymentAction() === \Razorpay\Magento\Model\PaymentMethod::ACTION_AUTHORIZE_CAPTURE && $isPartialCodOrder != 1) {
                    $payment->addTransactionCommentsToOrder(
                        "$rzpPaymentId",
                        $this->captureCommand->execute(
                            $payment,
                            $order->getGrandTotal(),
                            $order
                        ),
                        ""
                    );
                } else {
                    $payment->addTransactionCommentsToOrder(
                        "$rzpPaymentId",
                        $this->authorizeCommand->execute(
                            $payment,
                            $isPartialCodOrder == 1 ? $amountPaid/100 : $order->getGrandTotal(),
                            $order
                        ),
                        ""
                    );
                }
                $this->logger->info('Payment authorized completed for id : ' . $order->getIncrementId());
            } else {
                $order->addStatusHistoryComment("Razorpay Payment Id " . $rzpPaymentId)->setStatus($order->getStatus())->setIsCustomerNotified(true);
            }

            $transaction = $payment->addTransaction(\Magento\Sales\Model\Order\Payment\Transaction::TYPE_AUTH, null, true, "");

            $transaction->setIsClosed(true);

            $transaction->save();

            $this->logger->info('Payment authorized completed for id : ' . $order->getIncrementId());

            $orderLink->setRzpPaymentId($rzpPaymentId);

            $orderLink->setRzpUpdateOrderCronStatus(OrderCronStatus::PAYMENT_AUTHORIZED_COMPLETED);

            if (
                $order->canInvoice() && $this->config->canAutoGenerateInvoice()
                && $rzpOrderData->status === 'paid' && $isPartialCodOrder != 1
            ) {
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

                $this->logger->info('Invoice generated for id : ' . $order->getIncrementId());
            } else if (
                $rzpOrderData->status === 'paid' && $isPartialCodOrder != 1 &&
                ($order->canInvoice() === false or
                    $this->config->canAutoGenerateInvoice() === false)
            ) {
                $this->logger->info('Invoice generation not possible for id : ' . $order->getIncrementId());
            } else if ($order->canInvoice() && $this->config->canAutoGenerateInvoice() && $isPartialCodOrder === true) {
                //partial cod flow, add partial cod comments to order
                $partialCodComment = __('Partial COD payment received for order id %1.', $rzpOrderId);
                $order->addStatusHistoryComment(
                    $partialCodComment
                )->setStatus($order->getStatus())->setIsCustomerNotified(false);
            }

            $orderLink->setRzpUpdateOrderCronStatus(OrderCronStatus::INVOICE_GENERATED);

            $comment = __('Razorpay order id %1.', $rzpOrderId);

            $order->addStatusHistoryComment(
                $comment
            )->setStatus($order->getStatus())->setIsCustomerNotified(true);

            $comment = __('Razorpay magic order.');

            $order->addStatusHistoryComment(
                $comment
            )->setStatus($order->getStatus())->setIsCustomerNotified(true);

            $commentCallback = __('Razorpay magic order placed through callback.');

            $order->addStatusHistoryComment(
                $commentCallback
            )->setStatus($order->getStatus())->setIsCustomerNotified(false);

            $gstin = $rzpOrderData->notes->gstin ?? '';
            if (empty($gstin) === false) {
                $gstinComment = __('Customer GSTIN number %1.', $gstin);

                $order->addStatusHistoryComment(
                    $gstinComment
                )->setStatus($order->getStatus())->setIsCustomerNotified(true);
            }

            $codFee = $rzpOrderData->cod_fee;
            if ($codFee > 0) {
                $codFeeComment = __('Razorpay COD Fee %1.', $codFee / 100);

                $order->addStatusHistoryComment(
                    $codFeeComment
                )->setStatus($order->getStatus())->setIsCustomerNotified(true);
            }

            //In case customer address not completely added to order details, we will set the address details in order comments.
            $shippingRZPAddress = $rzpOrderData->customer_details->shipping_address;

            if (isset($shippingRZPAddress->line2)) {
                $shippingStreetRzp = $shippingRZPAddress->line1 . ', ' . $shippingRZPAddress->line2;
            } else {
                $shippingStreetRzp = $shippingRZPAddress->line1;
            }

            if (strlen($shippingStreetRzp) > 255) {
                $shippingAddress = 'Customer Complete Shipping Address - ' . $shippingRZPAddress->name . ', ' .
                    $shippingStreetRzp . ', ' .
                    $shippingRZPAddress->city . ', ' .
                    strtoupper($shippingRZPAddress->country) . ' - ' .
                    $shippingRZPAddress->zipcode;
                $order->addStatusHistoryComment(
                    $shippingAddress
                )->setStatus($order->getStatus())->setIsCustomerNotified(false);
            }

            $billingRZPAddress = $rzpOrderData->customer_details->billing_address;
            if (isset($billingRZPAddress->line2)) {
                $billingStreetRzp = $billingRZPAddress->line1 . ', ' . $billingRZPAddress->line2;
            } else {
                $billingStreetRzp = $billingRZPAddress->line1;
            }

            if (strlen($billingStreetRzp) > 255) {
                $billingAddress = 'Customer Complete Billing Address - ' . $billingRZPAddress->name . ', ' .
                    $billingStreetRzp . ', ' .
                    $billingRZPAddress->city . ', ' .
                    strtoupper($billingRZPAddress->country) . ' - ' .
                    $billingRZPAddress->zipcode;
                $order->addStatusHistoryComment(
                    $billingAddress
                )->setStatus($order->getStatus())->setIsCustomerNotified(false);
            }

            try {
                $this->checkoutSession->setRazorpayMailSentOnSuccess(true);
                $this->orderSender->send($order);
                $this->checkoutSession->unsRazorpayMailSentOnSuccess();
            } catch (\Magento\Framework\Exception\MailException $e) {
                $this->logger->critical('graphQL: '
                    . 'Razorpay Error:' . $e->getMessage());

                $properties = [
                    'error_message' => $e->getMessage(),
                    'file_path' => 'controller/OneClick/CompleteOrder.php',
                    'exception_type' => get_class($e),
                    'notes' => 'email send failed in complete order'
                ];
                $this->trackPluginInstrumentation->rzpTrackDataLake('razorpay.1cc.email.send.failed', $properties);

                throw new GraphQlInputException(__('Razorpay Error: %1.', $e->getMessage()));
            } catch (\Exception $e) {
                $this->logger->critical('graphQL: '
                    . 'Error:' . $e->getMessage());

                $properties = [
                    'error_message' => $e->getMessage(),
                    'file_path' => 'controller/OneClick/CompleteOrder.php',
                    'exception_type' => get_class($e),
                    'notes' => 'email send failed in complete order'
                ];
                $this->trackPluginInstrumentation->rzpTrackDataLake('razorpay.1cc.email.send.failed', $properties);

                throw new GraphQlInputException(__('Error: %1.', $e->getMessage()));
            }

            $this
                ->checkoutSession
                ->setLastSuccessQuoteId($order->getQuoteId())
                ->setLastQuoteId($order->getQuoteId())
                ->clearHelperData();
            if (empty($order) === false) {
                $this
                    ->checkoutSession
                    ->setLastOrderId($order->getId())
                    ->setLastRealOrderId($order->getIncrementId())
                    ->setLastOrderStatus($order->getStatus());
            }

            // Check if this is a partial COD order and set status accordingly
            if ($isPartialCodOrder) {
                $this->logger->info('graphQL: This is a partial COD order, setting status to partially_paid');
                $order->setStatus(static::STATE_PARTIALLY_PAID);
                $order->setState(static::STATE_PROCESSING);
                
                // Ensure prepaid and COD amounts are stored if not already set
                if (!$order->getData('razorpay_prepaid_amount')) {
                    $amountPaid = $rzpOrderData->amount_paid ?? 0;
                    $amountDue = $rzpOrderData->amount_due ?? 0;
                    $order->setData('razorpay_prepaid_amount', $amountPaid / 100);
                    $order->setData('razorpay_cod_amount', $amountDue / 100);
                }
            }
            $order->save();
            $orderLink->save();

                        // Get applied discounts data from the order object
            $appliedDiscounts = $this->getAppliedDiscounts($order);

            $result = [
                'status' => 'success',
                'order_id' => $order->getIncrementId(),
                'total_amount' => $order->getGrandTotal() * 100,
                'total_tax' => $order->getTaxAmount() * 100,
                'shipping_fee' => $order->getShippingAmount() * 100,
                'promotions' => $appliedDiscounts,
                'cod_fee' => 0
            ];

            return $result;
        }

        $result = [
            'status' => 'failed'
        ];

        return $result;
    }

    public function getAppliedDiscounts($order)
    {
        try {
            // Get applied discounts data from the order object
            $appliedDiscounts = [];
            foreach ($order->getAllItems() as $item) {
                $discount = $item->getDiscountAmount();
                if ($discount > 0) {
                    $appliedDiscounts[] = [
                        'item_id' => $item->getId(),
                        'code' => $item->getName(),
                        'discount_amount' => $discount * 100
                    ];
                }
            }

            return $appliedDiscounts;
        } catch (\Exception $e) {
            $properties = [
                'error_message' => $e->getMessage(),
                'file_path' => 'controller/OneClick/CompleteOrder.php',
                'exception_type' => get_class($e),
                'notes' => 'get applied discounts failed in complete order'
            ];
            $this->trackPluginInstrumentation->rzpTrackDataLake('razorpay.1cc.get.applied.discounts.failed', $properties);
            // Handle exception if order retrieval fails
            return [];
        }
    }

    public function updateDiscountAmount($orderId, $newDiscountAmount, $offerAmount, $totalPaid)
    {
        // TODO: Verify if total paid and order total is matching or not before updating the discount.
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

            $order->setTotalPaid($totalPaid / 100);

            $comment = __('Razorpay offer applied ₹%1.', $offerAmount);

            $order->addStatusHistoryComment(
                $comment
            )->setStatus($order->getStatus())->setIsCustomerNotified(true);

            return true;
        } catch (\Exception $e) {
            $properties = [
                'error_message' => $e->getMessage(),
                'file_path' => 'controller/OneClick/CompleteOrder.php',
                'exception_type' => get_class($e),
                'notes' => 'update discount amount failed in complete order'
            ];
            $this->trackPluginInstrumentation->rzpTrackDataLake('razorpay.1cc.update.discount.amount.failed', $properties);
            // Handle exception
            return false;
        }
    }

    protected function updateQuote($quote, $rzpOrderData, $rzpPaymentData)
    {
        $carrierCode = $rzpOrderData->notes->carrier_code ?? null;
        $methodCode = $rzpOrderData->notes->method_code ?? null;

        //This change is to support email less checkout.
        $email = $quote->getCustomerEmail();
        if ($email == null) {
            $email = $rzpOrderData->customer_details->email ?? '';
        }
        $quote->setCustomerEmail($email);

        $shippingCountry = $rzpOrderData->customer_details->shipping_address->country;
        $shippingState = $rzpOrderData->customer_details->shipping_address->state;

        $billingCountry = $rzpOrderData->customer_details->billing_address->country;
        $billingState = $rzpOrderData->customer_details->billing_address->state;

        $shippingRegionCode = $this->getRegionCode($shippingCountry, $shippingState);
        $billingRegionCode = $this->getRegionCode($billingCountry, $billingState);

        $shipping = $this->getAddress($rzpOrderData->customer_details->shipping_address, $shippingRegionCode, $email);
        $billing = $this->getAddress($rzpOrderData->customer_details->billing_address, $billingRegionCode, $email);

        $quote->getBillingAddress()->addData($billing['address']);
        $quote->getShippingAddress()->addData($shipping['address']);

        // If shipping method is not provided, find the least expensive one
        if (empty($carrierCode) || empty($methodCode)) {
            $shippingAddress = $quote->getShippingAddress();

            // Force shipping rate collection
            $shippingAddress
                ->setCollectShippingRates(true)  // <-- THIS IS CRITICAL
                ->collectShippingRates();        // Collects rates

            $shippingRates = $shippingAddress->getAllShippingRates();

            if (empty($shippingRates)) {
                // Log error for debugging
                $this->logger->critical("No shipping rates found. Address: " . json_encode($shippingAddress->getData()));
                throw new \Exception("No shipping methods available for the given address.");
            }

            $lowestRate = null;
            foreach ($shippingRates as $rate) {
                if ($lowestRate === null || $rate->getPrice() < $lowestRate->getPrice()) {
                    $lowestRate = $rate;
                }
            }

            if ($lowestRate) {
                $carrierCode = $lowestRate->getCarrier();
                $methodCode = $lowestRate->getMethod();
            }
        }

        $shippingMethod = ($carrierCode && $methodCode) ? $carrierCode . "_" . $methodCode : 'NA';

        $shippingAddress = $quote->getShippingAddress();
        $shippingAddress->setCollectShippingRates(true)
            ->collectShippingRates()
            ->setShippingMethod($shippingMethod);

        // Todo: Loop through promotions and fetch the discount data.
        if (isset($rzpOrderData->promotions[0]->code) == true) {
            $quote->setCouponCode($rzpOrderData->promotions[0]->code);
        }

        if ($rzpPaymentData->method === 'cod') {
            $paymentMethod = static::COD;
            // Set the custom fee in the quote
            $codFee = $rzpOrderData->cod_fee ?? 0;

            $quote->setData('razorpay_cod_fee', $codFee);
        } else {
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
        $name = $rzpAddress->name;
        // Find the position of the first non-whitespace character
        $firstNonWhitespacePos = strpos($name, trim($name)[0]);

        // Trim the string up to the first non-whitespace character
        $trimmedName = substr($name, $firstNonWhitespacePos);

        $name = explode(' ', $trimmedName);

        // Extract the first word as the first name
        $firstName = $name[0];

        // Combine the rest of the words as the last name
        $lastName = implode(' ', array_slice($name, 1));

        if (isset($rzpAddress->line2)) {
            $streetRzp = $rzpAddress->line1 . ', ' . $rzpAddress->line2;
        } else {
            $streetRzp = $rzpAddress->line1;
        }

        $street = substr($streetRzp, 0, 255);

        return [
            'email' => $email, //buyer email id
            'address' => [
                'firstname' => $firstName, //address Details
                'lastname' => empty($lastName) === false ? $lastName : '.',
                'street' => $street,
                'city' => $rzpAddress->city,
                'country_id' => strtoupper($rzpAddress->country),
                'region' => $regionCode,
                'postcode' => $rzpAddress->zipcode,
                'telephone' => $rzpAddress->contact,
                'save_in_address_book' => 1
            ]
        ];
    }

    protected function validateSignature($request, $cartMaskId)
    {
        if (empty($request['error']) === false) {
            $this->logger->critical("Validate: Payment Failed or error from gateway" . $request['razorpay_order_id']);
            $this->messageManager->addError(__('Payment Failed'));

            $properties = [
                'error_message' => "Payment Failed or error from gateway for " . $request['razorpay_order_id'],
                'file_path' => 'controller/OneClick/CompleteOrder.php',
                'exception_type' => null,
                'notes' => 'validate signature validation failed in complete order'
            ];

            $this->trackPluginInstrumentation->rzpTrackDataLake('razorpay.1cc.webhook.validation.failed', $properties);

            throw new \Exception("Payment Failed or error from gateway for " . $request['razorpay_order_id']);
        }
        $catalogRzpKey = static::QUOTE_LINKED_RAZORPAY_ORDER_ID . '_' . $cartMaskId;

        $rzpOrderIdFromSession = $this->checkoutSession->getData($catalogRzpKey);
        $this->logger->info('razorpay_payment_id = ' . $request['razorpay_payment_id']);
        $this->logger->info('razorpay_order_id = ' . $rzpOrderIdFromSession);
        $this->logger->info('razorpay_signature = ' . $request['razorpay_signature']);

        $attributes = array(
            'razorpay_payment_id' => $request['razorpay_payment_id'],
            'razorpay_order_id' => $rzpOrderIdFromSession,
            'razorpay_signature' => $request['razorpay_signature'],
        );

        $this
            ->rzp
            ->utility
            ->verifyPaymentSignature($attributes);
    }

    protected function isPartialCod($orderStatus, $paymentStatus)
    {
        // This method checks if the order is a partial COD scenario
        // 
        // $orderStatus === 'attempted': The Razorpay order has been attempted (partial payment made)
        // $paymentStatus === 'captured': The online portion of the payment has been successfully captured
        return $orderStatus === 'attempted' && $paymentStatus === 'captured';
    }

    protected function updateOrderDue($orderId, $amountPaid, $amountDue)
    {
        $order = $this->order->load($orderId);
        $order->setTotalPaid($amountPaid/100);
        $order->setTotalDue($amountDue/100);
        $order->save();
    }
}
