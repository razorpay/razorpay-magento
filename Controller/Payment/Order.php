<?php

namespace Razorpay\Magento\Controller\Payment;

use Razorpay\Magento\Model\PaymentMethod;
use Magento\Framework\Controller\ResultFactory;

class Order extends \Razorpay\Magento\Controller\BaseController
{
    protected $quote;

    protected $checkoutSession;

    protected $_currency = PaymentMethod::CURRENCY;
    protected $cache;
    protected $orderRepository;
    protected $logger;
    /**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Razorpay\Model\CheckoutFactory $checkoutFactory
     * @param \Magento\Razorpay\Model\Config\Payment $razorpayConfig
     * @param \Magento\Framework\App\CacheInterface $cache
     * @param \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Catalog\Model\Session $catalogSession
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Razorpay\Magento\Model\CheckoutFactory $checkoutFactory,
        \Razorpay\Magento\Model\Config $config,
        \Magento\Framework\App\CacheInterface $cache,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Catalog\Model\Session $catalogSession
    ) {
        parent::__construct(
            $context,
            $customerSession,
            $checkoutSession,
            $config
        );

        $this->checkoutFactory = $checkoutFactory;
        $this->catalogSession = $catalogSession;
        $this->config = $config;
        $this->cache = $cache;
        $this->orderRepository = $orderRepository;
        $this->logger          = $logger;
        $this->objectManagement   = \Magento\Framework\App\ObjectManager::getInstance();
    }

    public function execute()
    {
        $receipt_id = $this->getQuote()->getId();

        if (isset($_POST['order_check']))
        {
            if (empty($this->cache->load("quote_processing_".$receipt_id)) === false)
            {
                $responseContent = [
                'success'   => true,
                'order_id'  => false,
                'parameters' => []
                ];

                # fetch the related sales order and verify the payment ID with rzp payment id
                # To avoid duplicate order entry for same quote
                $collection = $this->_objectManager->get('Magento\Sales\Model\Order')
                                                   ->getCollection()
                                                   ->addFieldToSelect('entity_id')
                                                   ->addFilter('quote_id', $receipt_id)
                                                   ->getFirstItem();

                $salesOrder = $collection->getData();

                if (empty($salesOrder['entity_id']) === false)
                {
                    $this->logger->warning("Razorpay inside order already processed with webhook quoteID:" . $receipt_id
                                    ." and OrderID:".$salesOrder['entity_id']);

                    $this->checkoutSession
                            ->setLastQuoteId($this->getQuote()->getId())
                            ->setLastSuccessQuoteId($this->getQuote()->getId())
                            ->clearHelperData();

                    $order = $this->orderRepository->get($salesOrder['entity_id']);

                    if ($order) {
                        $this->checkoutSession->setLastOrderId($order->getId())
                                           ->setLastRealOrderId($order->getIncrementId())
                                           ->setLastOrderStatus($order->getStatus());
                    }

                    $responseContent['order_id'] = true;
                }
            }
            else
            {
                //set the chache to stop webhook processing
                $this->cache->save("started", "quote_Front_processing_$receipt_id", ["razorpay"], 30);

                $this->logger->warning("Razorpay front-end order processing started quoteID:" . $receipt_id);

                $responseContent = [
                'success'   => false,
                'parameters' => []
                ];
            }

            $response = $this->resultFactory->create(ResultFactory::TYPE_JSON);
            $response->setData($responseContent);
            $response->setHttpResponseCode(200);

            return $response;
        }

        $amount = (int) (round($this->getQuote()->getGrandTotal(), 2) * 100);

        $payment_action = $this->config->getPaymentAction();

        $maze_version = $this->_objectManager->get('Magento\Framework\App\ProductMetadataInterface')->getVersion();
        $module_version =  $this->_objectManager->get('Magento\Framework\Module\ModuleList')->getOne('Razorpay_Magento')['setup_version'];

        if ($payment_action === 'authorize') 
        {
                $payment_capture = 0;
        }
        else
        {
                $payment_capture = 1;
        }

        $code = 400;

        try
        {
            $order = $this->rzp->order->create([
                'amount' => $amount,
                'receipt' => $receipt_id,
                'currency' => $this->getQuote()->getQuoteCurrencyCode(),
                'payment_capture' => $payment_capture
            ]);

            $responseContent = [
                'message'   => 'Unable to create your order. Please contact support.',
                'parameters' => []
            ];

            if (null !== $order && !empty($order->id))
            {
                $responseContent = [
                    'success'           => true,
                    'rzp_order'         => $order->id,
                    'order_id'          => $receipt_id,
                    'amount'            => $order->amount,
                    'quote_currency'    => $this->getQuote()->getQuoteCurrencyCode(),
                    'quote_amount'      => round($this->getQuote()->getGrandTotal(), 2),
                    'maze_version'      => $maze_version,
                    'module_version'    => $module_version,
                ];

                $code = 200;

                $this->catalogSession->setRazorpayOrderID($order->id);
            }
        }
        catch(\Razorpay\Api\Errors\Error $e)
        {
            $responseContent = [
                'message'   => $e->getMessage(),
                'parameters' => []
            ];
        }
        catch(\Exception $e)
        {
            $responseContent = [
                'message'   => $e->getMessage(),
                'parameters' => []
            ];
        }

        //set the chache for race with webhook
        $this->cache->save("started", "quote_Front_processing_$receipt_id", ["razorpay"], 30);

        $response = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $response->setData($responseContent);
        $response->setHttpResponseCode($code);

        return $response;
    }

    public function getOrderID()
    {
        return $this->catalogSession->getRazorpayOrderID();
    }
}
