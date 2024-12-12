<?php

namespace Razorpay\Magento\Model;

use Magento\Quote\Model\QuoteFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Customer\Model\Session;
use Razorpay\Magento\Model\QuoteBuilder\ItemBuilderFactory;
use Razorpay\Magento\Model\QuoteBuilder\ItemBuilder;
use Magento\Checkout\Model\Session as CheckoutSession;

class QuoteBuilder
{
    /**
     * @var QuoteFactory
     */
    protected $quoteFactory;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var Session
     */
    protected $session;

    /**
     * @var ItemBuilderFactory
     */
    protected $itemBuilderFactory;
    protected $checkoutSession;

    /**
     * QuoteBuilder constructor.
     * @param QuoteFactory $quoteFactory
     * @param StoreManagerInterface $storeManager
     * @param Session $session
     * @param ItemBuilderFactory $itemBuilderFactory
     */
    public function __construct(
        QuoteFactory          $quoteFactory,
        StoreManagerInterface $storeManager,
        Session               $session,
        CheckoutSession       $checkoutSession,
        ItemBuilderFactory    $itemBuilderFactory
    )
    {
        $this->quoteFactory = $quoteFactory;
        $this->storeManager = $storeManager;
        $this->session = $session;
        $this->itemBuilderFactory = $itemBuilderFactory;
        $this->checkoutSession = $checkoutSession;
    }

    /**
     * @return \Magento\Quote\Model\Quote
     */
    public function createQuote()
    {
        /** @var \Magento\Quote\Model\Quote $quote */
        $quote = $this->quoteFactory->create();
        $quote->setStoreId($this->storeManager->getStore()->getId());

        // $quote->setCustomer($this->session->getCustomerDataObject());
        $quote->setCustomerIsGuest(1);

        /** @var ItemBuilder $itemBuilder */
        $itemBuilder = $this->itemBuilderFactory->create(['quote' => $quote]);
        $itemBuilder->addItems();

        // if (!$quote->isVirtual()) {
        //     $quote->setShippingAddress($this->addressBuilder->getShippingAddress());
        //     $this->shippingMethodBuilder->setShippingMethod($quote);
        // }

        $quote->setTotalsCollectedFlag(false)->collectTotals()->save();

        return $quote;
    }

    public function createOrUpdateQuote()
    {
        /** @var \Magento\Quote\Model\Quote $quote */
        $storeId = $this->storeManager->getStore()->getId();

        $quote = $this->checkoutSession->getQuote();

        // Check if a cart already exists for the customer
        if ($quote->getId()) {

            // Existing quote found, load it
            $quote->load($quote->getId());

        } else {
            $quote = $this->quoteFactory->create();

            // Guest user flow
            $quote->setStoreId($storeId);
            $quote->setCustomerIsGuest(1);
        }

        /** @var ItemBuilder $itemBuilder */
        $itemBuilder = $this->itemBuilderFactory->create(['quote' => $quote]);
        $itemBuilder->addItems();

        $quote->setIsActive(1);
        $quote->setTotalsCollectedFlag(false)->collectTotals()->save();

        $this->session->setQuoteId($quote->getId());
        $this->checkoutSession->setQuoteId($quote->getId());

        return $quote;
    }

}