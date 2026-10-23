<?php

namespace Razorpay\Magento\Controller\OneClick;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Pricing\Helper\Data;
use Razorpay\Magento\Controller\OneClick\StateMap;
use Razorpay\Magento\Model\PaymentMethod;
use Razorpay\Magento\Model\Config;
use Razorpay\Api\Api;
use Magento\Framework\App\Request\Http;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory;
use Magento\Directory\Model\ResourceModel\Region\Collection;
use Razorpay\Magento\Model\CartConverter;

class AbandonedQuote extends Action
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
     * @var Data
     */
    protected $priceHelper;

    protected $config;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    protected $rzp;

    protected $cartRepositoryInterface;

    protected $checkoutSession;
    protected $stateNameMap;
    protected $cartConverter;

    const COD = 'cashondelivery';
    const RAZORPAY = 'razorpay';

    /**
     * CompleteOrder constructor.
     * @param Http $request
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param Data $priceHelper
     * @param PaymentMethod $paymentMethod
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        Context                                               $context,
        Http                                                  $request,
        JsonFactory                                           $jsonFactory,
        PaymentMethod                                         $paymentMethod,
        \Razorpay\Magento\Model\Config                        $config,
        \Psr\Log\LoggerInterface                              $logger,
        \Magento\Quote\Api\CartRepositoryInterface            $cartRepositoryInterface,
        \Magento\Checkout\Model\Session                       $checkoutSession,
        CollectionFactory                                     $collectionFactory,
        StateMap                                              $stateNameMap,
        CartConverter                                         $cartConverter
    )
    {
        parent::__construct($context);
        $this->request = $request;
        $this->resultJsonFactory = $jsonFactory;
        $this->config = $config;
        $this->rzp = $paymentMethod->setAndGetRzpApiInstance();
        $this->logger = $logger;
        $this->cartRepositoryInterface = $cartRepositoryInterface;
        $this->checkoutSession = $checkoutSession;
        $this->collectionFactory = $collectionFactory;
        $this->stateNameMap = $stateNameMap;
        $this->cartConverter = $cartConverter;
    }

    public function execute()
    {
        $params = $this->request->getParams();

        $resultJson = $this->resultJsonFactory->create();

        $rzpOrderId = $params['rzp_order_id'];

        try {
            $rzpOrderData = $this->rzp->order->fetch($rzpOrderId);

            $cartMaskId = isset($rzpOrderData->notes) ? $rzpOrderData->notes->cart_mask_id : null;

            $cartId = isset($rzpOrderData->notes) ? $rzpOrderData->notes->cart_id : null;
            $email = $rzpOrderData->customer_details->email ?? null;

            $quote = $this->cartRepositoryInterface->get($cartId);

            $this->updateQuote($quote, $rzpOrderData);

            $quoteId = $rzpOrderData->notes->cart_mask_id;

            // Set customer to quote
            $customerCartId = $this->cartConverter->convertGuestCartToCustomer($cartId);
            $this->logger->info('graphQL: customerCartId ' . $customerCartId);

            return $resultJson->setData([
                'status' => 'success',
                'message' => 'Successfully updated the quote',
            ])->setHttpResponseCode(200);

        } catch (\Razorpay\Api\Errors\Error $e) {
            $this->logger->critical("Validate: Razorpay Error message:" . $e->getMessage());

            $code = $e->getCode();
            $this->messageManager->addError(__('Payment Failed.'));

            return $resultJson->setData([
                'status' => 'error',
                'code' => $code,
                'message' => __('An error occurred on the server. Please try again after sometime.'),
            ])->setHttpResponseCode(500);
        } catch (\Exception $e) {
            $this->logger->critical("Validate: Exception Error message:" . $e->getMessage());
            $this->messageManager->addError(__('Payment Failed.'));

            $code = $e->getCode();

            return $resultJson->setData([
                'status' => 'error',
                'code' => $code,
                'message' => __('An error occurred on the server. Please try again.'),
            ])->setHttpResponseCode(500);
        }
    }

    protected function updateQuote($quote, $rzpOrderData)
    {
        $carrierCode = $rzpOrderData->notes->carrier_code ?? 'freeshipping';
        $methodCode = $rzpOrderData->notes->method_code ?? 'freeshipping';

        $email = $rzpOrderData->customer_details->email ?? '';

        $quote->setCustomerEmail($email);

        if(empty($rzpOrderData->customer_details->shipping_address) === false) {

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

            $shippingMethod = 'NA';
            if (empty($carrierCode) === false && empty($methodCode) === false) {
                $shippingMethod = $carrierCode . "_" . $methodCode;
            }

            $shippingAddress = $quote->getShippingAddress();
            $shippingAddress->setCollectShippingRates(true)
                ->collectShippingRates()
                ->setShippingMethod($shippingMethod);

        }
        $paymentMethod = static::RAZORPAY;

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
            'email' => $email, //buyer email id
            'address' => [
                'firstname' => $name[0], //address Details
                'lastname' => empty($name[1]) === false ? $name[1] : '.',
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
}