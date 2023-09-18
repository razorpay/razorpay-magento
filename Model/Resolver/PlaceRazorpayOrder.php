<?php
declare (strict_types = 1);

namespace Razorpay\Magento\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\QuoteGraphQl\Model\Cart\GetCartForUser;
use Magento\Quote\Api\CartManagementInterface;
use Razorpay\Magento\Model\PaymentMethod;
use Razorpay\Magento\Model\Config;

class PlaceRazorpayOrder implements ResolverInterface
{
    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var \Magento\Quote\Api\CartManagementInterface
     */
    protected $cartManagement;

    /**
     * @var \Magento\Framework\App\ObjectManager
     */
    protected $_objectManager;

    /**
     * @var \Magento\Sales\Api\Data\OrderInterface
     */
    protected $order;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var \Magento\QuoteGraphQl\Model\Cart\GetCartForUser
     */
    protected $getCartForUser;

    protected $rzp;

    /**
     * @var \Razorpay\Magento\Model\Config
     */
    protected $config;

    /**
     * @var UPDATE_ORDER_CRON_STATUS
     */
    protected const DEFAULT = 0;
    protected const PAYMENT_AUTHORIZED_COMPLETED = 1;
    protected const ORDER_PAID_AFTER_MANUAL_CAPTURE = 2;
    protected const INVOICE_GENERATED = 3;
    protected const INVOICE_GENERATION_NOT_POSSIBLE = 4;
    protected const PAYMENT_AUTHORIZED_CRON_REPEAT = 5;

    /**
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param GetCartForUser $getCartForUser
     * @param \Magento\Quote\Api\CartManagementInterface $cartManagement
     * @param PaymentMethod $paymentMethod
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Razorpay\Magento\Model\Config $config
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        GetCartForUser $getCartForUser,
        \Magento\Quote\Api\CartManagementInterface $cartManagement,
        PaymentMethod $paymentMethod,
        \Magento\Sales\Api\Data\OrderInterface $order,
        \Psr\Log\LoggerInterface $logger,
        \Razorpay\Magento\Model\Config $config
    )
    {
        $this->scopeConfig    = $scopeConfig;
        $this->getCartForUser = $getCartForUser;
        $this->cartManagement = $cartManagement;
        $this->rzp            = $paymentMethod->setAndGetRzpApiInstance();
        $this->_objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->order          = $order;
        $this->logger         = $logger;
        $this->config          = $config;
    }

    /**
     * @inheritdoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null)
    {
        $this->logger->info('graphQL: Creating Razorpay Order');

        if (empty($args['order_id']))
        {
            $this->logger->critical('graphQL: Input Exception: Required parameter "order_id" is missing');

            throw new GraphQlInputException(__('Required parameter "order_id" is missing'));
        }

        if(empty($args['referrer']))
        {
            $this->logger->critical('graphQL: Input Exception: Required parameter "referrer" is missing');

            throw new GraphQlInputException(__('Required parameter "referrer" is missing'));
        }

        if (!preg_match("/\b(?:(?:https?|ftp):\/\/|www\.)[-a-z0-9+&@#\/%?=~_|!:,.;]*[-a-z0-9+&@#\/%=~_|]/i", $args['referrer'])) {
            $this->logger->critical('graphQL: Input Exception: "referrer" is invalid');

            throw new GraphQlInputException(__('Parameter "referrer" is invalid'));
        }

        try
        {
            $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
            $order_id   = $args['order_id'];
            $referrer   = $args['referrer'];

            $this->logger->info('graphQL: Order ID: ' . $order_id);

            $order = $this->order->load($order_id, $this->order::INCREMENT_ID);

            $order_grand_total          = $order->getGrandTotal();
            $order_currency_code        = $order->getOrderCurrencyCode();
            $order_base_discount_amount = $order->getBaseDiscountAmount();

            if (null === $order_grand_total
                || null === $order_currency_code
                || null === $order_base_discount_amount)
            {
                $this->logger->critical('graphQL: Unable to fetch order data for Order ID: ' . $order_id);

                return [
                    'success' => false,
                    'message' => 'graphQL: Unable to fetch order data for Order ID: ' . $order_id,
                ];
            }

            $amount          = (int) (number_format($order_grand_total * 100, 0, ".", ""));
            $payment_action  = $this->scopeConfig->getValue('payment/razorpay/rzp_payment_action', $storeScope);
            $payment_capture = 1;
            if ($payment_action === 'authorize')
            {
                $payment_capture = 0;
            }

            $this->logger->info('graphQL: Data for Razorpay order , '
                . 'Amount:' . $amount . ', '
                . 'Receipt:' . $order_id . ', '
                . 'Currency:' . $order_currency_code . ', '
                . ' Payment Capture:' . $payment_capture . ','
                . 'Referrer: ' . $referrer);

            $razorpay_order = $this->rzp->order->create([
                'amount'          => $amount,
                'receipt'         => $order_id,
                'currency'        => $order_currency_code,
                'payment_capture' => $payment_capture,
                'app_offer'       => (($order_grand_total - $order_base_discount_amount) > 0) ? 1 : 0,
                'notes'           => [
                    'referrer'      => $referrer
                ],
            ]);

            if (null !== $razorpay_order && !empty($razorpay_order->id))
            {
                $this->logger->info('graphQL: Razorpay Order ID: ' . $razorpay_order->id);
                
                $new_order_status = $this->config->getNewOrderStatus();
                
                $orderModel = $this->_objectManager->get('Magento\Sales\Model\Order')->load($order_id, $this->order::INCREMENT_ID);

                $orderModel->setStatus($new_order_status)->save();

                $responseContent = [
                    'success'        => true,
                    'rzp_order_id'   => $razorpay_order->id,
                    'order_id'       => $order_id,
                    'amount'         => number_format((float) $order_grand_total, 2, ".", ""),
                    'currency'       => $order_currency_code,
                    'message'        => 'Razorpay Order created successfully'
                ];
                
                $orderLink = $this->_objectManager->get('Razorpay\Magento\Model\OrderLink')
                            ->getCollection()
                            ->addFilter('order_id', $orderModel->getEntityId())
                            ->getFirstItem();
        
                $orderLink->setRzpOrderId($razorpay_order->id)
                            ->setOrderId($order->getEntityId())
                            ->setRzpUpdateOrderCronStatus(static::DEFAULT)
                            ->save();
                return $responseContent;
            } else
            {
                $this->logger->critical('graphQL: Razorpay Order not generated. Something went wrong');

                return [
                    'success' => false,
                    'message' => "Razorpay Order not generated. Something went wrong",
                ];
            }
        } catch (\Razorpay\Api\Errors\Error $e)
        {
            $this->logger->critical('graphQL: Razorpay API Error: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        } catch (\Exception $e)
        {
            $this->logger->critical('graphQL: Exception: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
