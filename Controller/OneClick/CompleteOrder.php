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

        $this->logger->info('graphQL: Complete Request dataaaa: ' . json_encode($params));

        $rzp_order_id = $params['razorpay_order_id'];
        $rzp_payment_id = $params['razorpay_payment_id'];

        $rzp_order_data = $this->rzp->order->fetch($rzp_order_id);
        $rzp_payment_data = $this->rzp->payment->fetch($rzp_payment_id);
        $receipt = isset($rzp_order_data->receipt) ? $rzp_order_data->receipt : null;
        $cart_id = isset($rzp_order_data->notes) ? $rzp_order_data->notes->cart_id : null;
        $this->logger->info('graphQL: Razorpay Order receipt:' . $cart_id);
        // $cart_id = 227;
// print_r($rzp_order_data->promotions[0]->code);
// return;
        $quote = $this->cartRepositoryInterface->get($cart_id);

        // $quote->setCustomerEmail($rzp_order_data->customer_details->email);
        $quote->setCustomerEmail('chetan.naik@razorpay.com');

        $name = explode(' ', $rzp_order_data->customer_details->shipping_address->name);

        // $quote->getBillingAddress()->setFirstname($name[0]);
        // $quote->getBillingAddress()->setLastname($name[1]);
        // $quote->getBillingAddress()->setStreet($rzp_order_data->customer_details->shipping_address->line1);
        // $quote->getBillingAddress()->setCity($rzp_order_data->customer_details->shipping_address->city);
        // $quote->getBillingAddress()->setTelephone($rzp_order_data->customer_details->shipping_address->contact);
        // $quote->getBillingAddress()->setPostcode($rzp_order_data->customer_details->shipping_address->zipcode);
        // $quote->getBillingAddress()->setCountryId($rzp_order_data->customer_details->shipping_address->country);

        // $quote->getShippingAddress()->setFirstname($name[0]);
        // $quote->getShippingAddress()->setLastname($name[1]);
        // $quote->getShippingAddress()->setStreet($rzp_order_data->customer_details->shipping_address->line1);
        // $quote->getShippingAddress()->setCity($rzp_order_data->customer_details->shipping_address->city);
        // $quote->getShippingAddress()->setTelephone($rzp_order_data->customer_details->shipping_address->contact);
        // $quote->getShippingAddress()->setPostcode($rzp_order_data->customer_details->shipping_address->zipcode);
        // $quote->getShippingAddress()->setCountryId($rzp_order_data->customer_details->shipping_address->country);
        // $quote->setCustomerEmail("chetu@gmail.com");

$tempOrder=[
     // 'email'        => $rzp_order_data->customer_details->email, //buyer email id
     'email'        => 'chetan.naik@razorpay.com',
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
$quote->getShippingAddress()->addData($tempOrder['shipping_address']);

if(isset($rzp_order_data->promotions[0]->code) == true)
{
 $quote->setCouponCode($rzp_order_data->promotions[0]->code);   
}

$shippingAddress=$quote->getShippingAddress();
        $shippingAddress->setCollectShippingRates(true)
                        ->collectShippingRates()
                        ->setShippingMethod('flatrate_flatrate');

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


        // $quote->setShippingMethod('flatrate_flatrate');
        // $quote->setPaymentMethod('Razorpay');
        $quote->save();

        $orderId = $this->cartManagement->placeOrder($cart_id);
        $order = $this->order->load($orderId);
       
        $order->setEmailSent(0);
        $increment_id = $order->getRealOrderId();
        // var_dump($increment_id);

        // $rzpOrderAmount = $rzp_order_data->amount;

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

                // $orderLink->setRzpUpdateOrderCronStatus(OrderCronStatus::PAYMENT_AUTHORIZED_COMPLETED);
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

                    // $orderLink->setRzpUpdateOrderCronStatus(OrderCronStatus::INVOICE_GENERATED);
                    $this->logger->info('Invoice generated for id : '. $order->getIncrementId());
                }
                else if($rzp_order_data->status === 'paid' and
                        ($order->canInvoice() === false or
                        $this->config->canAutoGenerateInvoice() === false))
                {
                    // $orderLink->setRzpUpdateOrderCronStatus(OrderCronStatus::INVOICE_GENERATION_NOT_POSSIBLE);
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
                

                // return $this->_redirect('checkout/onepage/success');

                // if (0 < ($fee = $order->getExtensionAttributes()->getCashOnDeliveryFee())) {
                    // $this->totals->addTotalBefore($totaldata, $this->totalsInterface::KEY_GRAND_TOTAL);
                // }

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
// 
                // $this->_order = $order;

                // $this->totals::_initTotals();

                // if (empty($this->totals->getTotals())) {
                //     var_dump('empty');
                // }

                // $totaldata = new \Magento\Framework\DataObject([
                //         // 'code' => CashOnDeliveryFee::TOTAL_CODE,
                //         'code' => 'cash_on_delivery_fee',
                //         // 'base_value' => 100,
                //         'value' => 100,
                //         'label' => __('Cash on Delivery Fee')
                //     ]);

                // $this->_totals = [];

                // $this->_totals['grand_total'] = new \Magento\Framework\DataObject(
                //     [
                //         'code' => 'grand_total',
                //         'field' => 'grand_total',
                //         'strong' => true,
                //         'value' => $quote->getGrandTotal(),
                //         'label' => __('Grand Total'),
                //     ]
                // );
                // // var_dump($totaldata);
                // $this->addTotalBeforeCOD($totaldata, $this->totalsInterface::KEY_GRAND_TOTAL);
                //                 // $this->totals->addTotal($totaldata);

                $order->save();

                return $this->_redirect('checkout/onepage/success');

                // $resultRedirect = $this->resultRedirectFactory->create();
                // $resultRedirect->setPath('checkout/onepage/success');
                // return $resultRedirect;

                // if (empty($order) === false)
                // {
                //     $this
                //         ->checkoutSession
                //         ->setLastOrderId($order->getId())
                //         ->setLastRealOrderId($order->getIncrementId())
                //         ->setLastOrderStatus($order->getStatus());
                // }
                // $this->checkoutSession->setLastSuccessQuoteId($order->getQouteId());
                // $this->checkoutSession->setLastQuoteId($order->getQuoteId());
                // return $this->_redirect('checkout/onepage/success');

                // $resultRedirect = $this->resultRedirectFactory->create();
                // $resultRedirect->setPath('checkout/onepage/success');
                // return $resultRedirect;

                // $orderLink->setRzpPaymentId($rzp_payment_id);
                // $orderLink->save();
            }

//         $resultJson = $this->resultJsonFactory->create();

//         /** @var QuoteBuilder $quoteBuilder */
//         $quoteBuilder = $this->quoteBuilderFactory->create();

//         try {
//             $quote = $quoteBuilder->createQuote();
//             $this->logger->info('graphQL: Magic Quote data: ' . $quote->getId());
//             $this->logger->info('graphQL: Magic Quote data11: ' . $quote->getParentItemId());
//             // var_dump($quote->getCurrency());
//             // var_dump($quote->getTotals());
//             $totals = $quote->getTotals();
//             // print_r($totals);
//             //  echo $total = $totals['grand_total']->getValue();
//             // // $this->logger->info('graphQL: Magic Quote getCurrency: ' . $quote->getCurrency());
//             // $this->logger->info('graphQL: Magic Quote getTotals: ' . $total);
//             // $this->logger->info('graphQL: Magic Quote isVirtual: ' . $quote->isVirtual());
//             // $this->logger->info('graphQL: Magic Quote getCustomAttributes: ' . $quote->getCustomAttributes());

//             $productId= $this->request->getParam('product');
//             $qty= $this->request->getParam('qty');
//                             // var_dump(get_class_methods($quote));

//             // $maskedQuoteId = $objectManager->get('Magento\Quote\Model\QuoteIdToMaskedQuoteIdInterface');
//             //  $maskedId = $maskedQuoteId->execute($quote->getId());
//             //  echo $maskedId;

//            $maskedId = $this->maskedQuoteIdInterface->execute($quote->getId());

// // var_dump($maskedId);

// if ($maskedId === '') {
//             $quoteIdMask = $this->quoteIdMaskFactory->create();
//             $quoteIdMask->setQuoteId($quote->getId());
//             $this->quoteIdMaskResourceModel->save($quoteIdMask);
//             $maskedId = $this->maskedQuoteIdInterface->execute($quote->getId());
//         }
// // var_dump($maskedId);
// $this->storeManager->getStore()->getBaseCurrencyCode();

//             $totalAmount = 0;
//             $lineItems = [];

//             foreach ($quote->getAllVisibleItems() as $quoteItem) {

//                 // var_dump(get_class_methods($quoteItem));

//                 $this->logger->info('graphQL: Magic Quote sku: ' . $quoteItem->getSku());
//                 $this->logger->info('graphQL: Magic Quote getItemId: ' . $quoteItem->getItemId());
//                 $this->logger->info('graphQL: Magic Quote getQty: ' . $quoteItem->getQty());
//                 $this->logger->info('graphQL: Magic Quote getPrice: ' . $quoteItem->getPrice());
//                 $this->logger->info('graphQL: Magic Quote getOriginalPrice: ' . $quoteItem->getOriginalPrice());
//                 $this->logger->info('graphQL: Magic Quote getName: ' . $quoteItem->getName());

//                 $lineItems[] = [
//                     'type' => 'e-commerce',
//                     'sku' => $quoteItem->getSku(),
//                     'variant_id' => $quoteItem->getItemId(),
//                     'price' => $quoteItem->getPrice()*100,
//                     'offer_price' => $quoteItem->getPrice()*100,
//                     'tax_amount' => 0,
//                     'quantity' => $quoteItem->getQty(),
//                     'name' => $quoteItem->getName(),
//                     'description' => $quoteItem->getName(),
//                     'image_url' => 'http://127.0.0.1/magento/pub/media/catalog/product/cache/78576bdffa9c21516a7ba248c06241e8/m/t/mt07-gray_main_1.jpg',
//                     'product_url' => 'http://127.0.0.1/magento/pub/media/catalog/product/cache/78576bdffa9c21516a7ba248c06241e8/m/t/mt07-gray_main_1.jpg',
//                 ];

//                 $totalAmount += ($quoteItem->getQty() * $quoteItem->getPrice()) * 100;


//                 // echo $quoteItem->getSku();
//                 // echo $quoteItem;
//                 // echo $quoteItem->getItemId();
//             // $this->logger->info('graphQL: Magic Quote ***: ' . json_decode(json_encode($quoteItem), true));

//                 // if ($quoteItem->getSku() == $sku && $quoteItem->getProductType() == Configurable::TYPE_CODE &&
//                 //     !$quoteItem->getParentItemId()) {
//                 //     $item = $quoteItem;
//                 //     break;
//                 // }
//             }

//             // $quote1 = $this->repository->get($quote->getId());

//             // $product = $this->productRepository->getById(149);

//             // $quote->addProduct($product, $qty);
//             // $this->cartModel->getQuote()->addProduct($product, $request);
//             // $this->cartModel->save();

// //             $productId = 149;
// // $product = $obj->create('\Magento\Catalog\Model\Product')->load($productId);

// // $cart = $obj->create('Magento\Checkout\Model\Cart');    
// // $params = array();      
// // $options = array();
// // $params['qty'] = 1;
// // $params['product'] = 149;

// // foreach ($product->getOptions() as $o) 
// // {       
// //     foreach ($o->getValues() as $value) 
// //     {
// //         $options[$value['option_id']] = $value['option_type_id'];

// //     }           
// // }

// // $params['options'] = $options;
// // $cart->addProduct($product, $params);
// // $cart->save();
//             // $this->logger->info('graphQL: Magic product data: ' . $productId);
//             // $this->logger->info('graphQL: Magic qty data: ' . $qty);
//             // $this->logger->info('graphQL: Magic Quote data: ' . $quote1->getItems());


//         } catch (LocalizedException $e) {
//             return $resultJson->setData([
//                 'status' => 'error',
//                 'message' => __($e->getMessage()),
//             ]);
//         } catch (\Exception $e) {
//             return $resultJson->setData([
//                 'status' => 'error',
//                 'message' => __('An error occurred on the server. Please try again.'),
//             ]);
//         }

//         // try {
//         //     $orderId = $this->cartManagement->CompleteOrder($quote->getId());
//         //     $order = $this->orderRepository->get($orderId);

//         //     $result = [
//         //         'status' => 'success',
//         //         'incrementId' => $order->getIncrementId(),
//         //         'url' => $this->_url->getUrl('sales/order/view', ['order_id' => $orderId]),
//         //         'totals' => [
//         //             'subtotal' => $this->priceHelper->currency($order->getSubtotal(), true, false),
//         //             'discount' => [
//         //                 'raw' => $order->getDiscountAmount(),
//         //                 'formatted' => $this->priceHelper->currency($order->getDiscountAmount(), true, false),
//         //             ],
//         //             'shipping' => [
//         //                 'raw' => $order->getShippingAmount(),
//         //                 'formatted' => $this->priceHelper->currency($order->getShippingAmount(), true, false),
//         //             ],
//         //             'tax' => [
//         //                 'raw' => $order->getTaxAmount(),
//         //                 'formatted' => $this->priceHelper->currency($order->getTaxAmount(), true, false),
//         //             ],
//         //             'grandTotal' => $this->priceHelper->currency($order->getGrandTotal(), true, false),
//         //         ],
//         //     ];
//         // } catch (\Exception $e) {
//         //     $quote->setIsActive(false)->save();
//         //     $result = [
//         //         'status' => 'error',
//         //         'message' => __('An error occurred on the server. Please try again.'. $e->getMessage()),
//         //     ];
//         // }

//         // $this->logger->info('graphQL: Magento Order placement: ' . json_encode($result));
//         $this->logger->info('graphQL: Magento Order placement: ');

//         $razorpay_order = $this->rzp->order->create([
//             'amount'          => $totalAmount,
//             'receipt'         => 'order pending',
//             'currency'        => $this->storeManager->getStore()->getBaseCurrencyCode(),
//             'payment_capture' => 1,
//             'app_offer'       => 0,
//             'notes'           => [
//                 'cart_id'      => $maskedId
//             ],
//             'line_items_total' => $totalAmount,
//             'line_items' => $lineItems
//         ]);

//         if (null !== $razorpay_order && !empty($razorpay_order->id))
//         {
//             $this->logger->info('graphQL: Razorpay Order ID: ' . $razorpay_order->id);

//             $result = [
//                 'status'        => 'success',
//                 'rzp_order_id'   => $razorpay_order->id,
//                 'total_amount'   => $totalAmount,
//                 'message'        => 'Razorpay Order created successfully'
//             ];
            
//         } 
//         else
//         {
//             $this->logger->critical('graphQL: Razorpay Order not generated. Something went wrong');

//             $result = [
//                 'status' => 'error',
//                 'message' => "Razorpay Order not generated. Something went wrong",
//             ];
//         }

//         return $resultJson->setData($result);

    }

    public function addTotalBeforeCOD(\Magento\Framework\DataObject $total, $before = null)
    {
        var_dump($before);

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