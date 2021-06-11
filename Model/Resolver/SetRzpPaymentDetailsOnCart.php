<?php

declare(strict_types=1);

namespace Razorpay\Magento\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\QuoteGraphQl\Model\Cart\CheckCartCheckoutAllowance;
use Magento\QuoteGraphQl\Model\Cart\GetCartForUser;
use Magento\QuoteGraphQl\Model\Cart\SetPaymentMethodOnCart as SetPaymentMethodOnCartModel;
use Razorpay\Magento\Model\PaymentMethod;

/**
 * Mutation resolver for setting payment method for shopping cart
 */
class SetRzpPaymentDetailsOnCart implements ResolverInterface
{
    /**
     * @var GetCartForUser
     */
    private $getCartForUser;

    /**
     * @var SetPaymentMethodOnCartModel
     */
    private $setPaymentMethodOnCart;

    /**
     * @var CheckCartCheckoutAllowance
     */
    private $checkCartCheckoutAllowance;

    protected $_objectManager;



    /**
     * @param GetCartForUser $getCartForUser
     * @param SetPaymentMethodOnCartModel $setPaymentMethodOnCart
     * @param CheckCartCheckoutAllowance $checkCartCheckoutAllowance
     */
    public function __construct(
        GetCartForUser $getCartForUser,
        SetPaymentMethodOnCartModel $setPaymentMethodOnCart,
        CheckCartCheckoutAllowance $checkCartCheckoutAllowance,
        PaymentMethod $paymentMethod
    ) {
        $this->getCartForUser = $getCartForUser;
        $this->setPaymentMethodOnCart = $setPaymentMethodOnCart;
        $this->checkCartCheckoutAllowance = $checkCartCheckoutAllowance;

        $this->rzp = $paymentMethod->rzp;

        $this->_objectManager   = \Magento\Framework\App\ObjectManager::getInstance();
    }

    /**
     * @inheritdoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null)
    {
        if (empty($args['input']['cart_id']))
        {
            throw new GraphQlInputException(__('Required parameter "cart_id" is missing.'));
        }

        $maskedCartId = $args['input']['cart_id'];

        if (empty($args['input']['rzp_payment_id']))
        {
            throw new GraphQlInputException(__('Required parameter "rzp_payment_id" is missing.'));
        }

        $rzp_payment_id = $args['input']['rzp_payment_id'];

        if (empty($args['input']['rzp_order_id']))
        {
            throw new GraphQlInputException(__('Required parameter "rzp_order_id" is missing.'));
        }

        $rzp_order_id = $args['input']['rzp_order_id'];

        if (empty($args['input']['rzp_signature']))
        {
            throw new GraphQlInputException(__('Required parameter "rzp_signature" is missing.'));
        }

        $rzp_signature = $args['input']['rzp_signature'];

        $storeId = (int)$context->getExtensionAttributes()->getStore()->getId();
        $cart = $this->getCartForUser->execute($maskedCartId, $context->getUserId(), $storeId);


        try
        {
            //fetch order from API
            $rzp_order_data = $this->rzp->order->fetch($rzp_order_id);

            if($rzp_order_data->receipt !== $cart->getId())
            {
                throw new GraphQlInputException(__('Not a valid Razorpay orderID'));
            }

        }
        catch(\Razorpay\Api\Errors\Error $e)
        {
           throw new GraphQlInputException(__('Razorpay Error: %1.', $e->getMessage()));
        }

        try
        {
            //save to razorpay orderLink
            $orderLinkCollection = $this->_objectManager->get('Razorpay\Magento\Model\OrderLink')
                                                   ->getCollection()
                                                   ->addFilter('quote_id', $cart->getId())
                                                   ->getFirstItem();

            $orderLinkData = $orderLinkCollection->getData();


            if (empty($orderLinkData['entity_id']) === false)
            {
                $orderLinkCollection->setRzpPaymentId($rzp_payment_id)
                                    ->setRzpOrderId($rzp_order_id)
                                    ->setRzpSignature($rzp_signature)
                                    ->save();
            }
            else
            {
                $orderLnik = $this->_objectManager->create('Razorpay\Magento\Model\OrderLink');
                $orderLnik->setQuoteId($cart->getId())
                          ->setRzpPaymentId($rzp_payment_id)
                          ->setRzpOrderId($rzp_order_id)
                          ->setRzpSignature($rzp_signature)
                          ->save();
            }

        }
        catch (\Exception $e)
        {
            throw new GraphQlInputException(__('Razorpay Error: %1.', $e->getMessage()));
        }

        return [
            'cart' => [
                'model' => $cart,
            ],
        ];
    }
}
