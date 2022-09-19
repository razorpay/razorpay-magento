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
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param GetCartForUser $getCartForUser
     * @param \Magento\Quote\Api\CartManagementInterface $cartManagement
     * @param PaymentMethod $paymentMethod
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        GetCartForUser $getCartForUser,
        \Magento\Quote\Api\CartManagementInterface $cartManagement,
        PaymentMethod $paymentMethod,
        \Magento\Sales\Api\Data\OrderInterface $order,
        \Psr\Log\LoggerInterface $logger
    )
    {
        $this->scopeConfig    = $scopeConfig;
        $this->getCartForUser = $getCartForUser;
        $this->cartManagement = $cartManagement;
        $this->rzp            = $paymentMethod->rzp;
        $this->_objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->order          = $order;
        $this->logger         = $logger;
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
        try
        {
            $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
            $order_id   = $args['order_id'];

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
                . ' Payment Capture:' . $payment_capture);

            $razorpay_order = $this->rzp->order->create([
                'amount'          => $amount,
                'receipt'         => $order_id,
                'currency'        => $order_currency_code,
                'payment_capture' => $payment_capture,
                'app_offer'       => (($order_grand_total - $order_base_discount_amount) > 0) ? 1 : 0,
            ]);

            if (null !== $razorpay_order && !empty($razorpay_order->id))
            {
                $this->logger->info('graphQL: Razorpay Order ID: ' . $razorpay_order->id);

                if ($order)
                {
                    $order->setRzpOrderId($razorpay_order->id);
                }
                $order->save();

                $responseContent = [
                    'success'        => true,
                    'rzp_order_id'   => $razorpay_order->id,
                    'order_id'       => $order_id,
                    'amount'         => number_format((float) $order_grand_total, 2, ".", ""),
                    'currency'       => $order_currency_code,
                    'message'        => 'Razorpay Order created successfully'
                ];

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
