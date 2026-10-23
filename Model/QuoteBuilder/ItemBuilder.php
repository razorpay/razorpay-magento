<?php

namespace Razorpay\Magento\Model\QuoteBuilder;

use Magento\Framework\App\Request\Http;
use Magento\Quote\Model\Quote;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Checkout\Model\Cart\RequestInfoFilterInterface;
use Magento\Framework\Exception\NoSuchEntityException;

class ItemBuilder
{
    /**
     * @var Http
     */
    protected $request;

    /**
     * @var Quote
     */
    protected $quote;

    /**
     * @var ResolverInterface
     */
    protected $resolver;

    /**
     * @var ProductRepositoryInterface
     */
    protected $productRepository;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var RequestInfoFilterInterface
     */
    protected $requestInfoFilter;

    /**
     * ItemBuilder constructor.
     * @param Http $request
     * @param Quote $quote
     * @param ResolverInterface $resolver
     * @param ProductRepositoryInterface $productRepository
     * @param StoreManagerInterface $storeManager
     * @param RequestInfoFilterInterface $requestInfoFilter
     */
    public function __construct(
        Http $request,
        Quote $quote,
        ResolverInterface $resolver,
        ProductRepositoryInterface $productRepository,
        StoreManagerInterface $storeManager,
        RequestInfoFilterInterface $requestInfoFilter
    ) {
        $this->request = $request;
        $this->quote = $quote;
        $this->resolver = $resolver;
        $this->productRepository = $productRepository;
        $this->storeManager = $storeManager;
        $this->requestInfoFilter = $requestInfoFilter;
    }

    /**
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function addItems()
    {
        $params = $this->request->getParams();

        if (isset($params['qty'])) {
            $params['qty'] = $params['qty'];
        }

        $product = $this->initProduct();

        if (!$product) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('We found an invalid request for adding product to quote.')
            );
        }
        
        $requestInfo = $this->getProductRequest($params);

        $this->quote->addProduct($product, $requestInfo);
    }

    /**
     * @return bool|\Magento\Catalog\Api\Data\ProductInterface
     */
    protected function initProduct()
    {
        $productId = (int)$this->request->getParam('product');
        if ($productId) {
            $storeId = $this->storeManager->getStore()->getId();
            try {
                return $this->productRepository->getById($productId, false, $storeId);
            } catch (NoSuchEntityException $e) {
                return false;
            }
        }
        return false;
    }

    /**
     * @param $params
     * @return \Magento\Framework\DataObject
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function getProductRequest($params)
    {
        if ($params instanceof \Magento\Framework\DataObject) {
            $request = $params;
        } elseif (is_numeric($params)) {
            $request = new \Magento\Framework\DataObject(['qty' => $params]);
        } elseif (is_array($params)) {
            $request = new \Magento\Framework\DataObject($params);
        } else {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('We found an invalid request for adding product to quote.')
            );
        }
        $this->requestInfoFilter->filter($request);

        return $request;
    }
}