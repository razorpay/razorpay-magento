<?php
declare(strict_types=1);

namespace Razorpay\Magento\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Quote\Api\CartManagementInterface;
use Razorpay\Magento\Model\PaymentMethod;
use Razorpay\Magento\Model\Config;
use Magento\Framework\Pricing\Helper\Data;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Quote\Model\ResourceModel\Quote\QuoteIdMask as QuoteIdMaskResourceModel;
use Magento\Sales\Model\Order\Payment\State\CaptureCommand;
use Magento\Sales\Model\Order\Payment\State\AuthorizeCommand;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory;
use Razorpay\Magento\Controller\OneClick\StateMap;
use Razorpay\Magento\Model\CartConverter;
use Razorpay\Magento\Model\CustomerConsent;
use Razorpay\Magento\Constants\OrderCronStatus;

require_once __DIR__ . "/../../../Razorpay/Razorpay.php";

class ConvertCartToOrder implements ResolverInterface
{
    protected $collectionFactory;
    protected $cartManagement;
    protected $productRepository;
    protected $priceHelper;
    protected $config;
    protected $logger;
    protected $rzp;
    protected $quoteItem;
    protected $quoteIdMaskFactory;
    protected $quoteIdMaskResourceModel;
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
    protected $scopeConfig;
    protected $orderRepository;
    protected $getCustomer;
    protected $createEmptyCartForCustomer;
    protected $createEmptyCartForGuest;
    protected $quoteManagement;
    protected $_objectManager;

    const COD                        = 'cashondelivery';
    const RAZORPAY                   = 'razorpay';
    const STATE_PENDING_PAYMENT      = 'pending_payment';
    const STATE_PROCESSING           = 'processing';
    const QUOTE_LINKED_RAZORPAY_ORDER_ID = 'quote_linked_razorpay_order_id';
    const INVENTORY_OUT_OF_STOCK     = 'Some of the products are out of stock';
    const MAX_ATTEMPTS               = '6';

    public function __construct(
        CartManagementInterface                                       $cartManagement,
        Data                                                          $priceHelper,
        PaymentMethod                                                 $paymentMethod,
        Config                                                        $config,
        \Psr\Log\LoggerInterface                                      $logger,
        ProductRepositoryInterface                                    $productRepository,
        Item                                                          $quoteItem,
        QuoteIdMaskFactory                                            $quoteIdMaskFactory,
        QuoteIdMaskResourceModel                                      $quoteIdMaskResourceModel,
        StoreManagerInterface                                         $storeManager,
        \Magento\Quote\Api\CartRepositoryInterface                    $cartRepositoryInterface,
        \Magento\Sales\Model\Order                                    $order,
        \Magento\Sales\Model\Service\InvoiceService                   $invoiceService,
        \Magento\Sales\Model\Order\Email\Sender\InvoiceSender         $invoiceSender,
        \Magento\Framework\DB\Transaction                             $transaction,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender           $orderSender,
        \Magento\Checkout\Model\Session                               $checkoutSession,
        CollectionFactory                                             $collectionFactory,
        StateMap                                                      $stateNameMap,
        CartConverter                                                 $cartConverter,
        CustomerConsent                                               $customerConsent,
        \Magento\CustomerGraphQl\Model\Customer\GetCustomer           $getCustomer,
        \Magento\QuoteGraphQl\Model\Cart\CreateEmptyCartForCustomer   $createEmptyCartForCustomer,
        \Magento\QuoteGraphQl\Model\Cart\CreateEmptyCartForGuest      $createEmptyCartForGuest,
        \Magento\Quote\Model\QuoteManagement                          $quoteManagement,
        \Magento\Framework\App\Config\ScopeConfigInterface            $scopeConfig,
        \Magento\Sales\Api\OrderRepositoryInterface                   $orderRepository
    ) {
        $this->cartManagement           = $cartManagement;
        $this->priceHelper              = $priceHelper;
        $this->config                   = $config;
        $this->rzp                      = $paymentMethod->setAndGetRzpApiInstance();
        $this->logger                   = $logger;
        $this->productRepository        = $productRepository;
        $this->quoteItem                = $quoteItem;
        $this->quoteIdMaskFactory       = $quoteIdMaskFactory;
        $this->quoteIdMaskResourceModel = $quoteIdMaskResourceModel;
        $this->storeManager             = $storeManager;
        $this->cartRepositoryInterface  = $cartRepositoryInterface;
        $this->order                    = $order;
        $this->invoiceService           = $invoiceService;
        $this->invoiceSender            = $invoiceSender;
        $this->transaction              = $transaction;
        $this->orderSender              = $orderSender;
        $this->checkoutSession          = $checkoutSession;
        $this->collectionFactory        = $collectionFactory;
        $this->stateNameMap             = $stateNameMap;
        $this->cartConverter            = $cartConverter;
        $this->customerConsent          = $customerConsent;
        $this->orderStatus              = static::STATE_PROCESSING;
        $this->authorizeCommand         = new AuthorizeCommand();
        $this->captureCommand           = new CaptureCommand();
        $this->getCustomer              = $getCustomer;
        $this->_objectManager           = \Magento\Framework\App\ObjectManager::getInstance();
        $this->createEmptyCartForCustomer = $createEmptyCartForCustomer;
        $this->createEmptyCartForGuest  = $createEmptyCartForGuest;
        $this->quoteManagement          = $quoteManagement;
        $this->scopeConfig              = $scopeConfig;
        $this->orderRepository          = $orderRepository;
    }

    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null)
    {
        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/razorpayexpress_debug_order.log');
        $logger = new \Zend\Log\Logger();
        $logger->addWriter($writer);

        $logger->info('graphQL: ConvertCartToOrder - Creating Order for cart');

        if (empty($args['input']['cart_id'])) {
            $logger->info('graphQL: Input Exception: Required parameter "cart_id" is missing');
            throw new GraphQlInputException(__('Required parameter "cart_id" is missing'));
        }

        if (empty($args['input']['razorpay_payment_id'])) {
            $logger->info('graphQL: Input Exception: Required parameter "razorpay_payment_id" is missing');
            throw new GraphQlInputException(__('Required parameter "razorpay_payment_id" is missing'));
        }

        if (empty($args['input']['razorpay_order_id'])) {
            $logger->info('graphQL: Input Exception: Required parameter "razorpay_order_id" is missing');
            throw new GraphQlInputException(__('Required parameter "razorpay_order_id" is missing'));
        }

        if (empty($args['input']['razorpay_signature'])) {
            $logger->info('graphQL: Input Exception: Required parameter "razorpay_signature" is missing');
            throw new GraphQlInputException(__('Required parameter "razorpay_signature" is missing'));
        }

        $maskedId          = $args['input']['cart_id'];
        $rzpOrderId        = $args['input']['razorpay_order_id'];
        $rzpPaymentId      = $args['input']['razorpay_payment_id'];
        $razorpay_signature = $args['input']['razorpay_signature'];

        $logger->info('graphQL: cart_id=' . $maskedId . ' rzp_order_id=' . $rzpOrderId . ' rzp_payment_id=' . $rzpPaymentId);

        $attributes = [
            'razorpay_payment_id' => $rzpPaymentId,
            'razorpay_order_id'   => $rzpOrderId,
            'razorpay_signature'  => $razorpay_signature,
        ];

        try {
            $rzpOrderData   = $this->rzp->order->fetch($rzpOrderId);
            $rzpPaymentData = $this->rzp->payment->fetch($rzpPaymentId);

            $logger->info('rzpOrderData: ' . print_r($rzpOrderData, true));
            $logger->info('rzpPaymentData: ' . print_r($rzpPaymentData, true));

            $cartMaskId = isset($rzpPaymentData->notes->cart_mask_id)
                ? $rzpPaymentData->notes->cart_mask_id
                : $maskedId;

            $logger->info('before validate signature: cartMaskId=' . $cartMaskId);

            $this->validateSignature($attributes, $cartMaskId);

            $cartId = isset($rzpPaymentData->notes->cart_id) ? $rzpPaymentData->notes->cart_id : null;

            $logger->info('graphQL: cartId=' . $cartId);

            $email         = $rzpOrderData->customer_details->email ?? null;
            $rzpOrderAmount = $rzpOrderData->amount ?? null;

            $logger->info('rzpOrderAmount=' . $rzpOrderAmount . ' email=' . $email);

            $quote   = $this->cartRepositoryInterface->get($cartId);
            $quoteId = $quote->getId();

            $quoteAmount = (int) (number_format($quote->getGrandTotal() * 100, 0, '.', ''));

            $logger->info('quoteId=' . $quoteId . ' quoteAmount=' . $quoteAmount);

            $paymentId  = $rzpPaymentData->id;
            $rzpOrderId = $rzpPaymentData->order_id;

            if ($quoteAmount !== $rzpOrderAmount) {
                $logger->info('Amount mismatch: rzpOrderAmount=' . $rzpOrderAmount . ' quoteAmount=' . $quoteAmount);

                if ($this->config->isSkipOrderEnabled() === true) {
                    $cart_amount       = 'Rs ' . (number_format($quoteAmount / 100, 0, '.', ''));
                    $razor_pay_amount  = 'Rs ' . (number_format($rzpOrderAmount / 100, 0, '.', ''));

                    return [
                        'success' => false,
                        'message' => __("Cart order amount = %1 doesn't match with amount paid = %2", $cart_amount, $razor_pay_amount),
                    ];
                }
            }

            if ($quote->getCustomerId()) {
                $logger->info('graphQL: cart already assigned to customer=' . $quote->getCustomerId());
            } else {
                if (false === $context->getExtensionAttributes()->getIsCustomer()) {
                    $logger->info('graphQL: guest customer');
                } else {
                    $logger->info('graphQL: assigning customer to cart');
                    $customer = $this->getCustomer->execute($context);
                    $quote->setStoreId($quote->getStoreId());
                    $quote->assignCustomer($customer);
                }
            }

            $quote->setIsActive(true)->save();

            $this->updateQuote($quote, $rzpOrderData, $rzpPaymentData);

            if ($rzpPaymentData->method == 'cod') {
                $logger->info('set cash on delivery for razorpay order');

                $shippingAddress = $quote->getShippingAddress();
                $shippingAddress->setCollectShippingRates(true)
                    ->collectShippingRates()
                    ->setShippingMethod('freeshipping_freeshipping');

                $quote->setPaymentMethod('cashondelivery');
                $quote->setInventoryProcessed(false);
                $quote->getPayment()->importData(['method' => 'cashondelivery']);
                $quote->save();
                $quote->collectTotals();
            }

            $logger->info('before place order for quote=' . $quoteId);

            $result = $this->placeMagentoOrder($cartId, $rzpPaymentData, $rzpOrderData, $args['input'], $logger, $quoteId);

            $logger->info('graphQL: result=' . print_r($result, true));

            if (isset($result['success']) && $result['success'] == true) {
                $customerId = (int) $quote->getCustomerId();
                $quote->setIsActive(false)->save();

                $predefinedMaskedQuoteId = null;

                $maskedQuoteId = (0 === $customerId || null === $customerId)
                    ? $this->createEmptyCartForGuest->execute($predefinedMaskedQuoteId)
                    : $this->createEmptyCartForCustomer->execute($customerId, $predefinedMaskedQuoteId);

                $logger->info('graphQL: created empty cart for customer=' . $customerId . ' old masked id=' . $cartMaskId . ' new masked id=' . $maskedQuoteId);
            }

            return $result;

        } catch (\Razorpay\Api\Errors\Error $e) {
            $this->logger->critical('Validate: Razorpay Error: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Razorpay Order not generated. Something went wrong',
            ];
        } catch (\Exception $e) {
            $this->logger->critical('Validate: Exception: ' . $e->getMessage());

            $logger->info('graphQL: error=' . $e->getMessage());

            if (strpos($e->getMessage(), static::INVENTORY_OUT_OF_STOCK) !== false) {
                if ($rzpPaymentData->method != 'cod') {
                    $refundData = [
                        'amount'  => $rzpPaymentData['amount'],
                        'receipt' => $rzpOrderData['receipt'],
                        'notes'   => [
                            'reason'             => $e->getMessage(),
                            'order_id'           => $rzpOrderData['receipt'],
                            'refund_from_magic'  => true,
                            'source'             => 'Magento',
                        ],
                    ];

                    try {
                        $this->rzp->payment->fetch($rzpPaymentId)->refund($refundData);
                    } catch (\Exception $refundEx) {
                        $this->logger->critical('Razorpay refund failed: ' . $refundEx->getMessage());
                    }
                }

                return [
                    'success' => false,
                    'message' => 'Razorpay Order not generated. Something went wrong',
                ];
            }

            return [
                'success' => false,
                'message' => __('An error occurred on the server. Please try again. ' . $e->getMessage()),
            ];
        }
    }

    public function placeMagentoOrder($cartId, $rzpPaymentData, $rzpOrderData, $attributes, $logger, $quoteId)
    {
        $logger->info('cartId=' . $cartId . ' start place order');

        $merchantOrderId = isset($rzpPaymentData->notes->merchant_order_id)
            ? $rzpPaymentData->notes->merchant_order_id
            : $rzpOrderData->notes->merchant_order_id;

        $email      = isset($rzpPaymentData->notes) ? $rzpPaymentData->email : null;
        $contact    = isset($rzpPaymentData->notes) ? $rzpPaymentData->contact : null;
        $totalPaid  = $rzpPaymentData->amount;

        $isPartialCodOrder = $this->isPartialCod($rzpOrderData->status, $rzpPaymentData->status);

        $logger->info('cartId=' . $cartId . ' merchantOrderId=' . $merchantOrderId . ' email=' . $email . ' contact=' . $contact . ' totalPaid=' . $totalPaid . ' quoteId=' . $quoteId . ' rzp_order_id=' . $attributes['razorpay_order_id']);

        $orderLink = $this->_objectManager->get('Razorpay\Magento\Model\OrderLink')
            ->getCollection()
            ->addFilter('rzp_order_id', $attributes['razorpay_order_id'])
            ->addFilter('quote_id', $quoteId)
            ->getFirstItem();

        $rzpOrderId   = $rzpOrderData->id;
        $rzpPaymentId = $rzpPaymentData->id;

        $quote    = $this->cartRepositoryInterface->get($cartId);
        $attempts = 0;

        while ($attempts < self::MAX_ATTEMPTS) {
            $attempts++;
            try {
                $order = $this->order->loadByIncrementId($merchantOrderId);

                if (!$order->getId()) {
                    $orderId = $this->cartManagement->placeOrder($cartId);
                    $order   = $this->orderRepository->get($orderId);
                    $logger->info('new order created orderId=' . $orderId);
                } else {
                    $orderId = $order->getId();
                    $logger->info('existing order found orderId=' . $orderId);
                }
                break;
            } catch (\Exception $e) {
                $this->logger->critical('Magento order placement failed for ' . $rzpOrderId . ' attempt=' . $attempts . ' error=' . $e->getMessage());

                if ($attempts == self::MAX_ATTEMPTS) {
                    $this->logger->critical('All attempts failed for rzp_order_id=' . $rzpOrderId . ' rzp_payment_id=' . $rzpPaymentId . ' cart_id=' . $cartId);
                    $logger->info('All attempts failed for rzp_order_id=' . $rzpOrderId);
                    throw new \Exception('Magento order creation failed: ' . $e->getMessage());
                }
                continue;
            }
        }

        $discountValues = [];
        $address = $quote->getIsVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();
        $totalDiscounts = $address->getExtensionAttributes()->getDiscounts();

        if ($totalDiscounts && is_array($totalDiscounts)) {
            foreach ($totalDiscounts as $value) {
                $discount    = [];
                $amount      = [];
                $discount['label'] = $value->getRuleLabel() ?: __('Discount');
                $discountData      = $value->getDiscountData();
                $amount['value']   = $discountData->getAmount();
                $amount['currency'] = $quote->getQuoteCurrencyCode();
                $discount['amount'] = $amount;
                $discountValues[]   = $discount;
            }
            $order->setDiscountDescriptionSplit(json_encode($discountValues));
        }

        $order->setEmailSent(true);
        $order->setSendEmail(true);

        if ($order) {
            if ($rzpPaymentData->status === 'failed') {
                $this->logger->critical('Razorpay payment failed for order=' . $rzpOrderId);
                throw new \Exception('Razorpay payment is failed for the order id ' . $rzpOrderId);
            }

            if ($order->getStatus() === 'pending') {
                if ($rzpPaymentData->status === 'pending' && $rzpPaymentData->method === 'cod') {
                    $order->setState(static::STATE_PENDING_PAYMENT)->setStatus(static::STATE_PENDING_PAYMENT);
                } else {
                    $order->setState(static::STATE_PROCESSING)->setStatus(static::STATE_PROCESSING);
                }
                $logger->info('Order status updated for order=' . $order->getIncrementId() . ' status=' . $order->getStatus());
            }

            if (($rzpOrderData->amount !== $rzpOrderData->amount_paid) && $rzpPaymentData->method != 'cod' && $isPartialCodOrder != 1) {
                $discountAmount   = $order->getDiscountAmount();
                $codFee           = $rzpOrderData->cod_fee;
                $totalPaid        = $rzpPaymentData->amount;
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
                    $offerDiscount    = ($offerDiff / 100);
                    $newDiscountAmount = abs($discountAmount) + $offerDiscount;
                    $this->updateDiscountAmount($orderId, $newDiscountAmount, $offerDiscount, $totalPaid);
                }
            }

            if ($isPartialCodOrder) {
                $order->setState(static::STATE_PROCESSING)->setStatus(static::STATE_PROCESSING);

                $amountDue  = (int) ($rzpOrderData->amount_due ?? 0);
                $amountPaid = (int) ($rzpOrderData->amount_paid ?? 0);

                $this->updateOrderDue($orderId, $amountPaid, $amountDue);

                $prepaidAmount = $amountPaid / 100;
                $codAmount     = $amountDue / 100;

                $order->setData('razorpay_prepaid_amount', $prepaidAmount);
                $order->setData('razorpay_cod_amount', $codAmount);

                $isPartialCodEnabled = $this->scopeConfig->isSetFlag(
                    'payment/partially_paid/active',
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                    $order->getStoreId()
                );

                if ($isPartialCodEnabled) {
                    try {
                        $payment = $order->getPayment();
                        if ($payment && $payment->getMethod() !== 'partially_paid' && $payment->getMethod() === 'razorpay') {
                            $payment->setMethod('partially_paid');
                            $payment->setData('gateway_partner', 'Razorpay');
                            $payment->setData('initial_amount_paid', (string) round((float) $prepaidAmount, 4));
                            $payment->setData('remaining_amount', (string) round((float) $codAmount, 4));

                            $additionalInfo                  = $payment->getAdditionalInformation();
                            $additionalInfo['method_title']  = 'Partial COD';
                            $payment->setAdditionalInformation($additionalInfo);
                        }
                    } catch (\Exception $e) {
                        $this->logger->critical('Partial COD: failed to update payment method: ' . $e->getMessage());
                    }
                }

                $order->addStatusHistoryComment(
                    __('Partial COD payment - Prepaid: ₹%1, COD Amount Due: ₹%2', number_format($prepaidAmount, 2), number_format($codAmount, 2))
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
                        $this->captureCommand->execute($payment, $order->getGrandTotal(), $order),
                        ""
                    );
                } else {
                    $payment->addTransactionCommentsToOrder(
                        "$rzpPaymentId",
                        $this->authorizeCommand->execute(
                            $payment,
                            $isPartialCodOrder == 1 ? $amountPaid / 100 : $order->getGrandTotal(),
                            $order
                        ),
                        ""
                    );
                }
            } else {
                $order->addStatusHistoryComment('Razorpay Payment Id ' . $rzpPaymentId)->setStatus($order->getStatus())->setIsCustomerNotified(true);
            }

            $transaction = $payment->addTransaction(\Magento\Sales\Model\Order\Payment\Transaction::TYPE_AUTH, null, true, "");
            $transaction->setIsClosed(true);
            $transaction->save();

            $rzpOrderAmount = $rzpOrderData->amount ?? null;

            if (empty($rzpOrderData->customer_details->shipping_address) === false) {
                $order->setCustomerEmail($email);

                $name                = $rzpOrderData->customer_details->shipping_address->name;
                $firstNonWhitespacePos = strpos($name, trim($name)[0]);
                $trimmedName         = substr($name, $firstNonWhitespacePos);
                $nameParts           = explode(' ', $trimmedName);
                $firstName           = $nameParts[0];
                $lastName            = implode(' ', array_slice($nameParts, 1));

                $order->setCustomerFirstname($firstName);
                $order->setCustomerLastname(empty($lastName) === false ? $lastName : '.');
                $order->setCustomerName($nameParts);
            }

            $orderLink->setRzpPaymentId($rzpPaymentId)
                ->setIncrementOrderId($order->getIncrementId())
                ->setOrderPlaced(1)
                ->setAmountPaid($totalPaid)
                ->setRzpOrderAmount($rzpOrderAmount)
                ->setEmail($email)
                ->setContact($contact);

            $orderLink->setRzpUpdateOrderCronStatus(OrderCronStatus::PAYMENT_AUTHORIZED_COMPLETED);

            if ($order->canInvoice() && $this->config->canAutoGenerateInvoice()
                && $rzpOrderData->status === 'paid' && $isPartialCodOrder != 1
            ) {
                $invoice = $this->invoiceService->prepareInvoice($order);
                $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                $invoice->setTransactionId($rzpPaymentId);
                $invoice->register();
                $invoice->save();

                $transactionSave = $this->transaction
                    ->addObject($invoice)
                    ->addObject($invoice->getOrder());
                $transactionSave->save();

                $order->addStatusHistoryComment(
                    __('Notified customer about invoice #%1.', $invoice->getId())
                )->setIsCustomerNotified(true);

                $orderLink->setRzpUpdateOrderCronStatus(OrderCronStatus::INVOICE_GENERATED);

            } elseif ($isPartialCodOrder === true && $order->canInvoice() && $this->config->canAutoGenerateInvoice()) {
                $order->addStatusHistoryComment(
                    __('Partial COD payment received for order id %1.', $rzpOrderId)
                )->setStatus($order->getStatus())->setIsCustomerNotified(false);
            }

            $order->addStatusHistoryComment(
                __('Razorpay order id %1.', $rzpOrderId)
            )->setStatus($order->getStatus())->setIsCustomerNotified(true);

            $order->addStatusHistoryComment(
                __('Razorpay magic order.')
            )->setStatus($order->getStatus())->setIsCustomerNotified(true);

            $order->addStatusHistoryComment(
                __('Razorpay magic order placed through callback.')
            )->setStatus($order->getStatus())->setIsCustomerNotified(false);

            $gstin = $rzpOrderData->notes->gstin ?? '';
            if (empty($gstin) === false) {
                $order->addStatusHistoryComment(
                    __('Customer GSTIN number %1.', $gstin)
                )->setStatus($order->getStatus())->setIsCustomerNotified(true);
            }

            $shippingRZPAddress = $rzpOrderData->customer_details->shipping_address;
            $line1 = isset($shippingRZPAddress->line1) ? $shippingRZPAddress->line1 : '.';
            $line2 = isset($shippingRZPAddress->line2) ? $shippingRZPAddress->line2 : '.';
            $shippingStreetRzp = $line1 . ', ' . $line2;

            if (strlen($shippingStreetRzp) > 255) {
                $order->addStatusHistoryComment(
                    'Customer Complete Shipping Address - ' . $shippingRZPAddress->name . ', ' . $shippingStreetRzp . ', ' . $shippingRZPAddress->city . ', ' . strtoupper($shippingRZPAddress->country) . ' - ' . $shippingRZPAddress->zipcode
                )->setStatus($order->getStatus())->setIsCustomerNotified(false);
            }

            $billingRZPAddress = $rzpOrderData->customer_details->billing_address;
            $line1 = isset($billingRZPAddress->line1) ? $billingRZPAddress->line1 : '.';
            $line2 = isset($billingRZPAddress->line2) ? $billingRZPAddress->line2 : '.';
            $billingStreetRzp = $line1 . ', ' . $line2;

            if (strlen($billingStreetRzp) > 255) {
                $order->addStatusHistoryComment(
                    'Customer Complete Billing Address - ' . $billingRZPAddress->name . ', ' . $billingStreetRzp . ', ' . $billingRZPAddress->city . ', ' . strtoupper($billingRZPAddress->country) . ' - ' . $billingRZPAddress->zipcode
                )->setStatus($order->getStatus())->setIsCustomerNotified(false);
            }

            try {
                $this->checkoutSession->setRazorpayMailSentOnSuccess(true);
                $this->orderSender->send($order, true);
                $this->checkoutSession->unsRazorpayMailSentOnSuccess();
            } catch (\Magento\Framework\Exception\MailException $e) {
                $this->logger->critical('graphQL: Razorpay MailException: ' . $e->getMessage());
                throw new GraphQlInputException(__('Razorpay Error: %1.', $e->getMessage()));
            } catch (\Exception $e) {
                $this->logger->critical('graphQL: Exception sending order email: ' . $e->getMessage());
                throw new GraphQlInputException(__('Error: %1.', $e->getMessage()));
            }

            $order->setMagicCheckoutOrder(1)->save();
            $orderLink->save();

            $quoteId = isset($rzpPaymentData->notes->cart_mask_id)
                ? $rzpPaymentData->notes->cart_mask_id
                : $rzpOrderData->notes->cart_mask_id;

            $telephone = $order->getIsVirtual()
                ? $order->getBillingAddress()->getTelephone()
                : $order->getShippingAddress()->getTelephone();

            return [
                'success'            => true,
                'rzp_order_id'       => $attributes['razorpay_order_id'],
                'order_quote_id'     => $quoteId,
                'amount'             => number_format((float) $order->getGrandTotal(), 2, '.', ''),
                'currency'           => $order->getOrderCurrencyCode(),
                'message'            => 'Order created successfully',
                'order_id'           => $order->getId(),
                'order_increment_id' => $order->getIncrementId(),
                'phone_number'       => $telephone,
            ];
        }

        return [
            'success' => false,
            'message' => 'Order placement failed',
        ];
    }

    protected function updateQuote($quote, $rzpOrderData, $rzpPaymentData)
    {
        $carrierCode = $rzpOrderData->notes->carrier_code ?? 'freeshipping';
        $methodCode  = $rzpOrderData->notes->method_code ?? 'freeshipping';
        $email       = $rzpOrderData->customer_details->email ?? '';

        $quote->setCustomerEmail($email);

        $shippingCountry = $rzpOrderData->customer_details->shipping_address->country;
        $shippingState   = $rzpOrderData->customer_details->shipping_address->state;
        $billingCountry  = $rzpOrderData->customer_details->billing_address->country;
        $billingState    = $rzpOrderData->customer_details->billing_address->state;

        $shippingRegionCode = $this->getRegionCode($shippingCountry, $shippingState);
        $billingRegionCode  = $this->getRegionCode($billingCountry, $billingState);

        $shipping = $this->getAddress($rzpOrderData->customer_details->shipping_address, $shippingRegionCode, $email);
        $billing  = $this->getAddress($rzpOrderData->customer_details->billing_address, $billingRegionCode, $email);

        $quote->setCustomerFirstname($billing['address']['firstname']);
        $quote->setCustomerLastname($billing['address']['lastname']);

        $quote->getBillingAddress()->addData($billing['address']);
        $quote->getShippingAddress()->addData($shipping['address']);

        $shippingMethod = 'NA';
        if (empty($carrierCode) === false && empty($methodCode) === false) {
            $shippingMethod = $carrierCode . '_' . $methodCode;
        }

        $shippingAddress = $quote->getShippingAddress();
        $shippingAddress->setCollectShippingRates(true)
            ->collectShippingRates()
            ->setShippingMethod($shippingMethod);

        if (isset($rzpOrderData->promotions[0]->code) == true) {
            $quote->setCouponCode($rzpOrderData->promotions[0]->code);
        }

        if ($rzpPaymentData->method === 'cod') {
            $quote->getPayment()->importData(['method' => static::COD]);
        }

        $paymentMethod = $rzpPaymentData->method === 'cod' ? static::COD : static::RAZORPAY;
        $quote->setPaymentMethod($paymentMethod);
        $quote->setInventoryProcessed(false);
        $quote->setTotalsCollectedFlag(false)->collectTotals();
        $this->cartRepositoryInterface->save($quote);
    }

    protected function getRegionCode($country, $state)
    {
        $magentoStateName = $this->stateNameMap->getMagentoStateName($country, $state);

        $regionCode = $this->collectionFactory->create()
            ->addRegionNameFilter($magentoStateName)
            ->getFirstItem()
            ->toArray();

        return $regionCode['code'] ?? 'NA';
    }

    protected function getAddress($rzpAddress, $regionCode, $email)
    {
        $name = $rzpAddress->name;
        $firstNonWhitespacePos = strpos($name, trim($name)[0]);
        $trimmedName           = substr($name, $firstNonWhitespacePos);
        $nameParts             = explode(' ', $trimmedName);
        $firstName             = $nameParts[0];
        $lastName              = implode(' ', array_slice($nameParts, 1));

        $line1 = isset($rzpAddress->line1) ? $rzpAddress->line1 : '.';
        $line2 = isset($rzpAddress->line2) ? $rzpAddress->line2 : '.';
        $streetRzp = $line1 . ', ' . $line2;
        $street    = substr($streetRzp, 0, 255);

        return [
            'email'   => $email,
            'address' => [
                'firstname'           => $firstName,
                'lastname'            => empty($lastName) === false ? $lastName : '.',
                'street'              => $street,
                'city'                => $rzpAddress->city,
                'country_id'          => strtoupper($rzpAddress->country),
                'region'              => $regionCode,
                'postcode'            => $rzpAddress->zipcode,
                'telephone'           => str_replace('+91', '', $rzpAddress->contact),
                'save_in_address_book' => 0,
            ],
        ];
    }

    protected function validateSignature($request, $cartMaskId)
    {
        if (empty($request['error']) === false) {
            $this->logger->critical('Validate: Payment Failed or error from gateway for ' . $request['razorpay_order_id']);
            throw new \Exception('Payment Failed or error from gateway for ' . $request['razorpay_order_id']);
        }

        $this->logger->info('razorpay_payment_id=' . $request['razorpay_payment_id']);
        $this->logger->info('razorpay_order_id=' . $request['razorpay_order_id']);
        $this->logger->info('razorpay_signature=' . $request['razorpay_signature']);

        $attributes = [
            'razorpay_payment_id' => $request['razorpay_payment_id'],
            'razorpay_order_id'   => $request['razorpay_order_id'],
            'razorpay_signature'  => $request['razorpay_signature'],
        ];

        $this->rzp->utility->verifyPaymentSignature($attributes);
    }

    protected function isPartialCod($orderStatus, $paymentStatus)
    {
        return $orderStatus === 'attempted' && $paymentStatus === 'captured';
    }

    protected function updateOrderDue($orderId, $amountPaid, $amountDue)
    {
        $order = $this->order->load($orderId);
        $order->setTotalPaid($amountPaid / 100);
        $order->setTotalDue($amountDue / 100);
        $order->save();
    }

    public function updateDiscountAmount($orderId, $newDiscountAmount, $offerAmount, $totalPaid)
    {
        try {
            $order = $this->order->load($orderId);
            $order->setDiscountAmount($newDiscountAmount);
            $order->setBaseDiscountAmount($newDiscountAmount);
            $order->setBaseGrandTotal($order->getBaseGrandTotal() - $offerAmount);
            $order->setGrandTotal($order->getGrandTotal() - $offerAmount);
            $order->setTotalPaid($totalPaid / 100);
            $order->addStatusHistoryComment(
                __('Razorpay offer applied ₹%1.', $offerAmount)
            )->setStatus($order->getStatus())->setIsCustomerNotified(true);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
