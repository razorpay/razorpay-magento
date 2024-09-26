<?php

namespace Razorpay\Magento\Controller\OneClick;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Model\QuoteFactory;
use Razorpay\Magento\Model\QuoteBuilderFactory;
use Razorpay\Magento\Model\QuoteBuilder;
use Magento\Framework\Pricing\Helper\Data;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Razorpay\Magento\Model\PaymentMethod;
use Razorpay\Magento\Model\Config as RazorpayConfig;
use Razorpay\Api\Api;
use Magento\Framework\App\Request\Http;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\QuoteIdToMaskedQuoteIdInterface;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Quote\Model\ResourceModel\Quote\QuoteIdMask as QuoteIdMaskResourceModel;
use Magento\Checkout\Model\Session;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\SalesSequence\Model\Manager as SequenceManager;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableProduct;
use Magento\GroupedProduct\Model\Product\Type\Grouped as GroupedProduct;

class PlaceOrder extends Action
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
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    protected $rzp;

    /**
     * @var QuoteBuilderFactory
     */
    protected $quoteBuilderFactory;

    protected $maskedQuoteIdInterface;

    private QuoteIdMaskFactory $quoteIdMaskFactory;

    private QuoteIdMaskResourceModel $quoteIdMaskResourceModel;

    protected $storeManager;

    protected $cart;

    protected $checkoutSession;

    protected $resourceConnection;

    protected $sequenceManager;

    protected $quoteFactory;

    protected $config;

    protected $configurableProduct;
    protected $groupedProduct;
    protected $customerSession;

    const QUOTE_LINKED_RAZORPAY_ORDER_ID = "quote_linked_razorpay_order_id";

    /**
     * PlaceOrder constructor.
     * @param Http $request
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param CartManagementInterface $cartManagement
     * @param RazorpayConfig $config
     * @param PaymentMethod $paymentMethod
     * @param \Psr\Log\LoggerInterface $logger
     * @param QuoteBuilderFactory $quoteBuilderFactory
     * @param ProductRepositoryInterface $productRepository
     */
    public function __construct(
        Context                                   $context,
        Http                                      $request,
        JsonFactory                               $jsonFactory,
        CartManagementInterface                   $cartManagement,
        PaymentMethod                             $paymentMethod,
        RazorpayConfig                            $config,
        \Psr\Log\LoggerInterface                  $logger,
        QuoteBuilderFactory                       $quoteBuilderFactory,
        ProductRepositoryInterface                $productRepository,
        QuoteIdToMaskedQuoteIdInterface           $maskedQuoteIdInterface,
        QuoteIdMaskFactory                        $quoteIdMaskFactory,
        QuoteIdMaskResourceModel                  $quoteIdMaskResourceModel,
        StoreManagerInterface                     $storeManager,
        \Magento\Checkout\Model\Cart              $cart,
        Session                                   $checkoutSession,
        \Magento\Framework\App\ResourceConnection $resourceConnection,
        SequenceManager                           $sequenceManager,
        QuoteFactory                              $quoteFactory,
        ConfigurableProduct                       $configurableProduct,
        GroupedProduct                            $groupedProduct,
        CustomerSession                           $customerSession
    )
    {
        parent::__construct($context);
        $this->request = $request;
        $this->resultJsonFactory = $jsonFactory;
        $this->cartManagement = $cartManagement;
        $this->config = $config;
        $this->rzp = $paymentMethod->setAndGetRzpApiInstance();
        $this->logger = $logger;
        $this->quoteBuilderFactory = $quoteBuilderFactory;
        $this->productRepository = $productRepository;
        $this->maskedQuoteIdInterface = $maskedQuoteIdInterface;
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->quoteIdMaskResourceModel = $quoteIdMaskResourceModel;
        $this->storeManager = $storeManager;
        $this->cart = $cart;
        $this->checkoutSession = $checkoutSession;
        $this->resourceConnection = $resourceConnection;
        $this->sequenceManager = $sequenceManager;
        $this->quoteFactory = $quoteFactory;
        $this->configurableProduct = $configurableProduct;
        $this->groupedProduct = $groupedProduct;
        $this->customerSession = $customerSession;
    }

    public function execute()
    {
        $params = $this->request->getParams();

        if (isset($params['page']) && $params['page'] == 'cart') {
            $cartItems = $this->cart->getQuote()->getAllVisibleItems();
            $quoteId = $this->cart->getQuote()->getId();
            $totals = $this->cart->getQuote()->getTotals();

            $quote = $this->checkoutSession->getQuote();
            $customerId = $quote->getCustomerId();

            // Set customer as guest to update the quote during checkout journey.
            if ($customerId) {
                $this->logger->info('graphQL: customer: ' . json_encode($customerId));

                $connection = $this->resourceConnection->getConnection();
                $tableName = $this->resourceConnection->getTableName('quote');

                $quote->setCustomerId(null);

                $quote->save();

                $connection->update($tableName, ['customer_id' => null, 'customer_is_guest' => 1], ['entity_id = ?' => $quoteId]);
            }
        } else {
            /** @var QuoteBuilder $quoteBuilder */
            $quoteBuilder = $this->quoteBuilderFactory->create();
            $quote = $quoteBuilder->createQuote();
            $quoteId = $quote->getId();
            $quote = $this->quoteFactory->create()->load($quoteId);
            $totals = $quote->getTotals();
            $quote->collectTotals();
            $cartItems = $quote->getAllVisibleItems();
        }

        $resultJson = $this->resultJsonFactory->create();

        try {
            $giftCardDiscountAmount = 0;
            //Check if the customer has applied GiftCards
            if($quote->getMageworxGiftcardsAmount()) {
                $giftCardDiscountAmount = abs($quote->getMageworxGiftcardsAmount());
            }
            $maskedId = $this->maskedQuoteIdInterface->execute($quoteId);

            if ($maskedId === '') {
                $quoteIdMask = $this->quoteIdMaskFactory->create();
                $quoteIdMask->setQuoteId($quoteId);
                $this->quoteIdMaskResourceModel->save($quoteIdMask);
                $maskedId = $this->maskedQuoteIdInterface->execute($quoteId);
            }
            $this->storeManager->getStore()->getBaseCurrencyCode();

            $totalAmount = 0;
            $lineItems = [];
            $item = [];

            foreach ($cartItems as $quoteItem) {
                $category = [];

                $store = $this->storeManager->getStore();
                $productId = $quoteItem->getProductId();
                $product = $this->productRepository->getById($productId);
                $parentProductId = null;

                // Check if the product is configurable
                if ($product->getTypeId() == \Magento\ConfigurableProduct\Model\Product\Type\Configurable::TYPE_CODE) {
                    $parentProductId = $this->configurableProduct->getParentIdsByChild($productId);
                } elseif ($product->getTypeId() == \Magento\GroupedProduct\Model\Product\Type\Grouped::TYPE_CODE) {
                    $parentProductId = $this->groupedProduct->getParentIdsByChild($productId);
                }

                if ($parentProductId) {
                    // Load the parent product by its ID with images
                    $parentProduct = $this->productRepository->getById($parentProductId[0], false, $quote->getStoreId(), true);

                    // Get the parent product image URL
                    $productImageUrl = $parentProduct->getImageUrl();
                } else {
                    $productImageUrl = $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA) . 'catalog/product' . $product->getImage();
                }

                $imagewidth=200;
                $imageheight=200;
                $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                $imageHelper  = $objectManager->get('\Magento\Catalog\Helper\Image');
                $productImageUrl = $imageHelper->init($product, 'product_page_image_small')->setImageFile($product->getFile())->resize($imagewidth, $imageheight)->getUrl();

                $productUrl = $product->getProductUrl();

                $offerPrice = $quoteItem->getPrice() * 100;

                // Check if the item has applied discounts
                if ($quoteItem->getDiscountAmount()) {
                    // Get the discount amount applied to the item
                    $discountAmount = abs($quoteItem->getDiscountAmount());

                    $offerPrice = ($quoteItem->getPrice() - $discountAmount) * 100;
                }

                $categoriesIds = $product->getCategoryIds(); /*will return category ids array*/
                foreach($categoriesIds as $categoryId){

                    $cat = $objectManager->create('Magento\Catalog\Model\Category')->load($categoryId);
                    $catName = $cat->getName();
                    $category['category'][] = $catName;
                }
                $item = array_merge($item, $category);

                $storeName = [
                    'affiliation' => $this->storeManager->getStore()->getName(),
                ];
                $item = array_merge($item, $storeName);

                $lineItem = [
                    'type' => 'e-commerce',
                    'sku' => $quoteItem->getSku(),
                    'variant_id' => $quoteItem->getProductId(),
                    'price' => $quoteItem->getPrice() * 100,
                    'offer_price' => $offerPrice,
                    'tax_amount' => 0,
                    'quantity' => $quoteItem->getQty(),
                    'name' => $quoteItem->getName(),
                    'description' => $quoteItem->getName(),
                    'image_url' => $productImageUrl,
                    'product_url' => $productUrl,
                ];

                $lineItems[] = $lineItem;

                $item = array_merge($item, $lineItem);

                $items[] = $item;
            }
            $totalAmount = $quote->getSubtotalWithDiscount() * 100;

        } catch (LocalizedException $e) {
            return $resultJson->setData([
                'status' => 'error',
                'message' => __($e->getMessage()),
            ]);
        } catch (\Exception $e) {
            return $resultJson->setData([
                'status' => 'error',
                'message' => __('An error occurred on the server. Please try again.'),
            ]);
        }

        $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;

        $paymentAction = $this->config->getPaymentAction();
        $paymentCapture = 1;
        if ($paymentAction === 'authorize') {
            $paymentCapture = 0;
        }
        $rzpKey = $this->config->getKeyId();
        $merchantName = $this->config->getMerchantNameOverride();
        $allowCouponApplication = $this->config->getMerchantCouponApplication();

        $this->getLastOrderId($quote);

        $orderNotes = [
            'cart_mask_id' => $maskedId,
            'cart_id' => $quoteId,
            'merchant_order_id' => (string)$quote->getReservedOrderId() ?? 'order pending'
        ];
        $customerEmail = $this->getCustomerEmailFromQuote();

        if($customerEmail !== false)
        {
            $customerEmailNotes = [
                'website_logged_in_email' => $customerEmail
            ];
            $orderNotes = array_merge($orderNotes, $customerEmailNotes);
        }
        if($giftCardDiscountAmount > 0)
        {
            $totalAmount = $totalAmount - $giftCardDiscountAmount;
            $giftCardDiscount = [
                'gift_card_discount'=> $giftCardDiscountAmount,
            ];
            $orderNotes = array_merge($orderNotes, $giftCardDiscount);
        }
        $promotions = [
            'type' => 'gift_card',
            'code' => 'gift_card_number',
            'value' => $giftCardDiscountAmount,
            'description' => 'applied',
        ];

        $razorpay_order = $this->rzp->order->create([
            'amount' => $totalAmount,
            'receipt' => (string)$quote->getReservedOrderId() ?? 'order pending',
            'currency' => $this->storeManager->getStore()->getBaseCurrencyCode(),
            'payment_capture' => $paymentCapture,
            'app_offer' => 0,
            'notes' => $orderNotes,
            'line_items_total' => $totalAmount,
            'line_items' => $lineItems,
            'promotions' => $promotions,
        ]);

        if (null !== $razorpay_order && !empty($razorpay_order->id)) {
            $this->logger->info('graphQL: Razorpay Order ID: ' . $razorpay_order->id);
            $catalogRzpKey = static::QUOTE_LINKED_RAZORPAY_ORDER_ID.'_'.$maskedId;
            $this->logger->info('graphQL: Razorpay Order ID stored catalogKey: ' . $catalogRzpKey);

            $this->checkoutSession->setData($catalogRzpKey, $razorpay_order->id);

            $result = [
                'status' => 'success',
                'rzp_key_id' => $rzpKey,
                'allow_coupon_application' => $allowCouponApplication == 1 ? true : false,
                'rzp_order_id' => $razorpay_order->id,
                'items' => $items,
                'message' => 'Razorpay Order created successfully',
                'gift_card_discount' => $giftCardDiscount,
            ];

            $orderLink = $this->_objectManager->get('Razorpay\Magento\Model\OrderLink')
                ->getCollection()
                ->addFilter('order_id', $quote->getReservedOrderId())
                ->getFirstItem();

            $orderLink->setRzpOrderId($razorpay_order->id)
                ->setOrderId($quote->getReservedOrderId())
                ->save();
        } else {
            $this->logger->critical('graphQL: Razorpay Order not generated. Something went wrong');

            $result = [
                'status' => 'error',
                'message' => "Razorpay Order not generated. Something went wrong",
            ];
        }

        return $resultJson->setData($result);

    }

    public function getCustomerEmailFromQuote()
    {
        // Check if customer is logged in
        if ($this->customerSession->isLoggedIn()) {
            // Get active quote associated with customer session
            $customerEmail = $this->customerSession->getCustomer()->getEmail();

            // Check if customer email is available
            if ($customerEmail) {
                return $customerEmail;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    public function getLastOrderId($quote)
    {
        try {
            $sequence = $this->sequenceManager->getSequence(
                \Magento\Sales\Model\Order::ENTITY,
                $quote->getStoreId()
            );

            // Generate a reserved order ID using the sequence
            $reservedOrderId = $sequence->getNextValue();

            // Check if the order and quote are available
            if ($reservedOrderId) {
                // Save the order ID in the quote for future reference
                $quote->setReservedOrderId($reservedOrderId);
            }

            $quote->save();

            return $reservedOrderId;
        } catch (\Exception $e) {
            // Handle exception if needed
            return 'order pending';
        }
    }
}