<?php

namespace Razorpay\Magento\Controller\OneClick;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartManagementInterface;
use Razorpay\Magento\Model\QuoteBuilderFactory;
use Razorpay\Magento\Model\QuoteBuilder;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Pricing\Helper\Data;
use Magento\Framework\App\Config\ScopeConfigInterface;
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
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

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

    /**
     * @var QuoteBuilderFactory
     */
    protected $quoteBuilderFactory;

    /**
     * @var QuoteItem
     */
    protected $quoteItem;

    protected $maskedQuoteIdInterface;

    private QuoteIdMaskFactory $quoteIdMaskFactory;

    private QuoteIdMaskResourceModel $quoteIdMaskResourceModel;

    protected $storeManager;

    protected $cart;

    /**
     * PlaceOrder constructor.
     * @param Http $request
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param CartManagementInterface $cartManagement
     * @param OrderRepositoryInterface $orderRepository
     * @param Data $priceHelper
     * @param ScopeConfigInterface $config
     * @param PaymentMethod $paymentMethod
     * @param \Psr\Log\LoggerInterface $logger
     * @param QuoteBuilderFactory $quoteBuilderFactory
     * @param ProductRepositoryInterface $productRepository
     */
    public function __construct(
        Context $context,
        Http $request,
        JsonFactory $jsonFactory,
        CartManagementInterface $cartManagement,
        OrderRepositoryInterface $orderRepository,
        Data $priceHelper,
        PaymentMethod $paymentMethod,
        ScopeConfigInterface $config,
        \Psr\Log\LoggerInterface $logger,
        QuoteBuilderFactory $quoteBuilderFactory,
        ProductRepositoryInterface $productRepository,
        Item $quoteItem,
        QuoteIdToMaskedQuoteIdInterface $maskedQuoteIdInterface,
        QuoteIdMaskFactory $quoteIdMaskFactory,
        QuoteIdMaskResourceModel $quoteIdMaskResourceModel,
        StoreManagerInterface $storeManager,
        \Magento\Checkout\Model\Cart $cart
    ) {
        parent::__construct($context);
        $this->request = $request;
        $this->resultJsonFactory = $jsonFactory;
        $this->cartManagement = $cartManagement;
        $this->orderRepository = $orderRepository;
        $this->priceHelper = $priceHelper;
        $this->config = $config;
        $this->rzp    = $paymentMethod->setAndGetRzpApiInstance();
        $this->logger = $logger;
        $this->quoteBuilderFactory = $quoteBuilderFactory;
        $this->productRepository = $productRepository;
        $this->quoteItem = $quoteItem;
        $this->maskedQuoteIdInterface = $maskedQuoteIdInterface;
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->quoteIdMaskResourceModel = $quoteIdMaskResourceModel;
        $this->storeManager = $storeManager;
        $this->cart = $cart;
    }

    public function execute()
    {
        $params = $this->request->getParams();

        if(isset($params['page']) && $params['page'] == 'cart')
        {
            $cartItems = $this->cart->getQuote()->getAllVisibleItems();
            $quoteId = $this->cart->getQuote()->getId();
            $totals = $this->cart->getQuote()->getTotals();
        }
        else 
        {
            /** @var QuoteBuilder $quoteBuilder */
            $quoteBuilder = $this->quoteBuilderFactory->create();
            $quote = $quoteBuilder->createQuote();
            $quoteId = $quote->getId();
            $totals = $quote->getTotals();
            $cartItems = $quote->getAllVisibleItems();
        }

        // $this->logger->info('graphQL: Request data: ' . json_encode($params));

        $resultJson = $this->resultJsonFactory->create();

        try 
        {
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

            foreach ($cartItems as $quoteItem) {

                // $this->logger->info('graphQL: Magic Quote sku: ' . $quoteItem->getSku());
                // $this->logger->info('graphQL: Magic Quote getItemId: ' . $quoteItem->getItemId());
                // $this->logger->info('graphQL: Magic Quote getParentItem: ' . $quoteItem->getProductId());
                // $this->logger->info('graphQL: Magic Quote getQty: ' . $quoteItem->getQty());
                // $this->logger->info('graphQL: Magic Quote getPrice: ' . $quoteItem->getPrice());
                // $this->logger->info('graphQL: Magic Quote getOriginalPrice: ' . $quoteItem->getOriginalPrice());
                // $this->logger->info('graphQL: Magic Quote getName: ' . $quoteItem->getName());

                $store = $this->storeManager->getStore();
                $productId = $quoteItem->getProductId();
                $product = $this->productRepository->getById($productId);

                $productImageUrl = $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA) . 'catalog/product' .$product->getImage();
                $productUrl = $product->getProductUrl();

                $lineItems[] = [
                    'type' => 'e-commerce',
                    'sku' => $quoteItem->getSku(),
                    'variant_id' => $quoteItem->getProductId(),
                    'price' => $quoteItem->getPrice()*100,
                    'offer_price' => $quoteItem->getPrice()*100,
                    'tax_amount' => 0,
                    'quantity' => $quoteItem->getQty(),
                    'name' => $quoteItem->getName(),
                    'description' => $quoteItem->getName(),
                    'image_url' => $productImageUrl,
                    'product_url' => $productUrl,
                ];

                $totalAmount += ($quoteItem->getQty() * $quoteItem->getPrice()) * 100;

                // $this->logger->info('graphQL: Magic Quote ***: ' . json_decode(json_encode($quoteItem), true));

                // if ($quoteItem->getSku() == $sku && $quoteItem->getProductType() == Configurable::TYPE_CODE &&
                //     !$quoteItem->getParentItemId()) {
                //     $item = $quoteItem;
                //     break;
                // }
            }

            // $this->logger->info('graphQL: Magic product data: ' . $productId);
            // $this->logger->info('graphQL: Magic qty data: ' . $qty);
            // $this->logger->info('graphQL: Magic Quote data: ' . $quote1->getItems());


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

        // $this->logger->info('graphQL: Magento Order placement: ' . json_encode($result));
        $this->logger->info('graphQL: Magento Order placement: ');

        $razorpay_order = $this->rzp->order->create([
            'amount'          => $totalAmount,
            'receipt'         => 'order pending',
            'currency'        => $this->storeManager->getStore()->getBaseCurrencyCode(),
            'payment_capture' => 1,
            'app_offer'       => 0,
            'notes'           => [
                'cart_mask_id' => $maskedId,
                'cart_id'      => $quoteId
            ],
            'line_items_total' => $totalAmount,
            'line_items' => $lineItems
        ]);

        if (null !== $razorpay_order && !empty($razorpay_order->id))
        {
            $this->logger->info('graphQL: Razorpay Order ID: ' . $razorpay_order->id);

            $result = [
                'status'        => 'success',
                'rzp_order_id'   => $razorpay_order->id,
                'total_amount'   => $totalAmount,
                'cart_id'        => $quoteId,
                'quote_id'       => $maskedId,
                'message'        => 'Razorpay Order created successfully'
            ];
            
        } 
        else
        {
            $this->logger->critical('graphQL: Razorpay Order not generated. Something went wrong');

            $result = [
                'status' => 'error',
                'message' => "Razorpay Order not generated. Something went wrong",
            ];
        }

        return $resultJson->setData($result);

    }
}