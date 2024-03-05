<?php

namespace Razorpay\Magento\Controller\OneClick;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartManagementInterface;
use Razorpay\Magento\Model\QuoteBuilderFactory;
use Razorpay\Magento\Model\QuoteBuilder;
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
use Magento\Checkout\Model\Session;
use Magento\Sales\Model\OrderFactory;

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

    protected $maskedQuoteIdInterface;

    private QuoteIdMaskFactory $quoteIdMaskFactory;

    private QuoteIdMaskResourceModel $quoteIdMaskResourceModel;

    protected $storeManager;

    protected $cart;

    protected $checkoutSession;

    protected $resourceConnection;

    protected $orderFactory;

    /**
     * PlaceOrder constructor.
     * @param Http $request
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param CartManagementInterface $cartManagement
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
        PaymentMethod $paymentMethod,
        ScopeConfigInterface $config,
        \Psr\Log\LoggerInterface $logger,
        QuoteBuilderFactory $quoteBuilderFactory,
        ProductRepositoryInterface $productRepository,
        QuoteIdToMaskedQuoteIdInterface $maskedQuoteIdInterface,
        QuoteIdMaskFactory $quoteIdMaskFactory,
        QuoteIdMaskResourceModel $quoteIdMaskResourceModel,
        StoreManagerInterface $storeManager,
        \Magento\Checkout\Model\Cart $cart,
        Session $checkoutSession,
        \Magento\Framework\App\ResourceConnection $resourceConnection,
        OrderFactory $orderFactory,
    ) {
        parent::__construct($context);
        $this->request = $request;
        $this->resultJsonFactory = $jsonFactory;
        $this->cartManagement = $cartManagement;
        $this->config = $config;
        $this->rzp    = $paymentMethod->setAndGetRzpApiInstance();
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
        $this->orderFactory = $orderFactory;
    }

    public function execute()
    {
        $params = $this->request->getParams();

        if(isset($params['page']) && $params['page'] == 'cart')
        {
            $cartItems = $this->cart->getQuote()->getAllVisibleItems();
            $quoteId = $this->cart->getQuote()->getId();
            $totals = $this->cart->getQuote()->getTotals();

            $quote = $this->checkoutSession->getQuote();
            $customerId = $quote->getCustomerId();

            // Set customer as guest to update the quote during checkout journey.
            if($customerId)
            {
                $this->logger->info('graphQL: customer: ' . json_encode($customerId));

                $connection = $this->resourceConnection->getConnection();
                $tableName = $this->resourceConnection->getTableName('quote');

                $connection->update($tableName, ['customer_id' => null, 'customer_is_guest' => 1], ['entity_id = ?' => $quoteId]);
            }
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
            }

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

        $paymentAction  = $this->config->getValue('payment/razorpay/rzp_payment_action', $storeScope);
        $paymentCapture = 1;
        if ($paymentAction === 'authorize')
        {
            $paymentCapture = 0;
        }

        $orderNumber = $this->getLastOrderId($quote);

        $razorpay_order = $this->rzp->order->create([
            'amount'          => $totalAmount,
            'receipt'         => (string)$quote->getReservedOrderId() ?? 'order pending',
            'currency'        => $this->storeManager->getStore()->getBaseCurrencyCode(),
            'payment_capture' => $paymentCapture,
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

    public function getLastOrderId($quote)
    {
        try {
            $lastOrder = $this->orderFactory->create()->getCollection()
                ->setOrder('entity_id', 'DESC')
                ->setPageSize(1)
                ->getFirstItem();

            $quote->setReservedOrderId((string)$lastOrder->getIncrementId()+1);
            $quote->save();
            return $lastIncrementId = (string)$lastOrder->getIncrementId()+1;

        } catch (\Exception $e) {
            // Handle exception if needed
            return 'order pending';
        }
    }
}