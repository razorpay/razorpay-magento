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
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param GetCartForUser $getCartForUser
     * @param \Magento\Quote\Api\CartManagementInterface $cartManagement
     * @param PaymentMethod $paymentMethod
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        GetCartForUser $getCartForUser,
        \Magento\Quote\Api\CartManagementInterface $cartManagement,
        PaymentMethod $paymentMethod,
        \Magento\Sales\Api\Data\OrderInterface $order
    )
    {
        $this->scopeConfig    = $scopeConfig;
        $this->getCartForUser = $getCartForUser;
        $this->cartManagement = $cartManagement;
        $this->rzp            = $paymentMethod->rzp;
        $this->_objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->order          = $order;
    }

    /**
     * @inheritdoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null)
    {
        if (empty($args['order_id']))
        {
            throw new GraphQlInputException(__('Required parameter "order_id" is missing'));
        }
        try
        {
            $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
            $storeId = (int) $context->getExtensionAttributes()->getStore()->getId();
            $receipt_id        = $args['order_id'];
            $collection = $this->_objectManager->get(\Magento\Sales\Model\Order::class)
            ->getCollection()
            ->addFieldToSelect('*')
            ->addFilter('increment_id', $receipt_id)
            ->getFirstItem();
            $salesOrder = $collection->getData();
            $amount          = (int) (number_format($salesOrder['grand_total'] * 100, 0, ".", ""));
            $payment_action  = $this->scopeConfig->getValue('payment/razorpay/rzp_payment_action', $storeScope);
            $payment_capture = 1;
            if ($payment_action === 'authorize')
            {
                $payment_capture = 0;
            }
            $razorpay_order = $this->rzp->order->create([
                'amount'          => $amount,
                'receipt'         => $receipt_id,
                'currency'        => $salesOrder['order_currency_code'],
                'payment_capture' => $payment_capture,
                'app_offer'       => (($salesOrder['grand_total'] - $salesOrder['base_discount_amount']) > 0) ? 1 : 0,
            ]);
            if (null !== $razorpay_order && !empty($razorpay_order->id))
            {
                if (isset($salesOrder['entity_id']) && empty($salesOrder['entity_id']) === false)
                {
                    $order = $this->order->load($salesOrder['entity_id']);
                    if ($order)
                    {
                        $order->setRzpOrderId($razorpay_order->id);
                    }
                    $order->save();
                }
                $responseContent = [
                    'success'        => true,
                    'rzp_order_id'   => $razorpay_order->id,
                    'order_id'       => $receipt_id,
                    'amount'         => number_format((float) $salesOrder['grand_total'], 2, ".", ""),
                    'currency'       => $salesOrder['order_currency_code'],
                    'message'        => 'Razorpay Order created successfully'
                ];
                return $responseContent;
            } else
            {
                return [
                    'success' => false,
                    'message' => "Razorpay Order not generated. Something went wrong",
                ];
            }
        } catch (\Razorpay\Api\Errors\Error $e)
        {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        } catch (\Exception $e)
        {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
