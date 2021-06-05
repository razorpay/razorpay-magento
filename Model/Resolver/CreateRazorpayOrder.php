<?php
declare (strict_types = 1);

namespace Razorpay\Magento\Model\Resolver;

require_once __DIR__ . "/../../../Razorpay/Razorpay.php";

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\QuoteGraphQl\Model\Cart\GetCartForUser;
use Magento\Quote\Api\CartManagementInterface;
use Razorpay\Api\Api;

class CreateRazorpayOrder implements ResolverInterface
{

    protected $scopeConfig;

    protected $cartManagement;

    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        GetCartForUser $getCartForUser,
        \Magento\Quote\Api\CartManagementInterface $cartManagement
    ) {
        $this->scopeConfig    = $scopeConfig;
        $this->getCartForUser = $getCartForUser;
        $this->cartManagement = $cartManagement;
    }

    /**
     * @param GetCartForUser $getCartForUser
     * @inheritdoc
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        array $value = null,
        array $args = null
    ) {
        if (empty($args['cart_id'])) {
            throw new GraphQlInputException(__('Required parameter "cart_id" is missing'));
        }
        try
        {
            $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
            $key        = $this->scopeConfig->getValue('payment/razorpay/key_id', $storeScope);
            $key_secret = $this->scopeConfig->getValue('payment/razorpay/key_secret', $storeScope);
            $this->rzp  = new Api($key, $key_secret);

            $storeId = (int) $context->getExtensionAttributes()->getStore()->getId();

            $maskedCartId = $args['cart_id'];

            $cart            = $this->getCartForUser->execute($maskedCartId, $context->getUserId(), $storeId);
            $receipt_id      = $cart->getId();
            $amount          = (int) (number_format($cart->getGrandTotal() * 100, 0, ".", ""));
            $payment_action  = $this->scopeConfig->getValue('payment/razorpay/payment_action', $storeScope);
            $payment_capture = 1;
            if ($payment_action === 'authorize') {
                $payment_capture = 0;
            }
            $order = $this->rzp->order->create([
                'amount'          => $amount,
                'receipt'         => $receipt_id,
                'currency'        => $cart->getQuoteCurrencyCode(),
                'payment_capture' => $payment_capture,
                'app_offer'       => (($cart->getBaseSubtotal() - $cart->getBaseSubtotalWithDiscount()) > 0) ? 1 : 0,
            ]);

            if (null !== $order && !empty($order->id)) {

                $responseContent = [
                    'success'        => true,
                    'rzp_order'      => $order->id,
                    'order_id'       => $receipt_id,
                    'amount'         => $order->amount,
                    'quote_currency' => $cart->getQuoteCurrencyCode(),
                    'quote_amount'   => number_format((float) $cart->getGrandTotal(), 2, ".", ""),
                    'message'        => '',
                ];
                $this->_objectManager = \Magento\Framework\App\ObjectManager::getInstance();

                //save to razorpay orderLink
                $orderLinkCollection = $this->_objectManager
                    ->get('Razorpay\Magento\Model\OrderLink')
                    ->getCollection()
                    ->addFilter('quote_id', $receipt_id)
                    ->getFirstItem();

                $orderLinkData = $orderLinkCollection->getData();

                if (empty($orderLinkData['entity_id']) === false) {
                    $orderLinkCollection->setRzpOrderId($order->id)
                        ->save();
                } else {
                    $orderLink = $this->_objectManager->create('Razorpay\Magento\Model\OrderLink');
                    $orderLink->setQuoteId($receipt_id)
                        ->setRzpOrderId($order->id)
                        ->save();
                }
                return $responseContent;
            } else {
                return [
                    'success' => false,
                    'message' => "Razorpay Order not generated. Something went wrong",

                ];
            }
        } catch (\Razorpay\Api\Errors\Error $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
