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
use Razorpay\Magento\Model\Config;

/**
 * Mutation resolver for setting payment method for shopping cart
 */
class SetRzpPaymentDetailsForOrder implements ResolverInterface
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

    /**
     * @var \Magento\Framework\App\ObjectManager
     */
    protected $_objectManager;

    /**
     * @var \Magento\Sales\Api\Data\OrderInterface
     */
    protected $order;

    /**
     * @var \Razorpay\Magento\Model\Config
     */
    protected $config;

    /**
     * @var \Magento\Sales\Model\Service\InvoiceService
     */
    protected $invoiceService;

    /**
     * @var \Magento\Framework\DB\Transaction
     */
    protected $transaction;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var STATUS_PROCESSING
     */
    protected const STATUS_PROCESSING = 'processing';

    /**
     * @param GetCartForUser $getCartForUser
     * @param SetPaymentMethodOnCartModel $setPaymentMethodOnCart
     * @param CheckCartCheckoutAllowance $checkCartCheckoutAllowance
     * @param PaymentMethod $paymentMethod
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param \Razorpay\Magento\Model\Config $config
     * @param \Magento\Sales\Model\Service\InvoiceService $invoiceService
     * @param \Magento\Framework\DB\Transaction $transaction
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        GetCartForUser $getCartForUser,
        SetPaymentMethodOnCartModel $setPaymentMethodOnCart,
        CheckCartCheckoutAllowance $checkCartCheckoutAllowance,
        PaymentMethod $paymentMethod,
        \Magento\Sales\Api\Data\OrderInterface $order,
        \Razorpay\Magento\Model\Config $config,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Framework\DB\Transaction $transaction,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    )
    {
        $this->getCartForUser             = $getCartForUser;
        $this->setPaymentMethodOnCart     = $setPaymentMethodOnCart;
        $this->checkCartCheckoutAllowance = $checkCartCheckoutAllowance;
        $this->rzp                        = $paymentMethod->rzp;
        $this->order                      = $order;
        $this->config                     = $config;
        $this->invoiceService             = $invoiceService;
        $this->transaction                = $transaction;
        $this->scopeConfig                = $scopeConfig;
        $this->_objectManager             = \Magento\Framework\App\ObjectManager::getInstance();
    }

    /**
     * @inheritdoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null)
    {
        if (empty($args['input']['order_id']))
        {
            throw new GraphQlInputException(__('Required parameter "order_id" is missing.'));
        }

        $order_id = $args['input']['order_id'];
        if (empty($args['input']['rzp_payment_id']))
        {
            throw new GraphQlInputException(__('Required parameter "rzp_payment_id" is missing.'));
        }

        $rzp_payment_id = $args['input']['rzp_payment_id'];

        if (empty($args['input']['rzp_signature']))
        {
            throw new GraphQlInputException(__('Required parameter "rzp_signature" is missing.'));
        }

        $rzp_signature = $args['input']['rzp_signature'];

        $rzp_order_id = '';
        try
        {
            $collection     = $this->_objectManager->get(\Magento\Sales\Model\Order::class)
            ->getCollection()
            ->addFieldToSelect('*')
            ->addFilter('increment_id', $order_id)
            ->getFirstItem();
            $salesOrder = $collection->getData();
            $order = $this->order->load($salesOrder['entity_id']);
            if ($order)
            {
                $rzp_order_id = $order->getRzpOrderId();
            }
        } catch (\Exception $e)
        {
            throw new GraphQlInputException(__('Error: %1.', $e->getMessage()));
        }
        $attributes = [
            'razorpay_payment_id' => $rzp_payment_id,
            'razorpay_order_id'   => $rzp_order_id,
            'razorpay_signature'  => $rzp_signature
        ];
        $this->rzp->utility->verifyPaymentSignature($attributes);
        try
        {
            $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
            $payment_action  = $this->scopeConfig->getValue('payment/razorpay/rzp_payment_action', $storeScope);
            $payment_capture = 'Captured';
            if ($payment_action === 'authorize')
            {
                $payment_capture = 'Authorized';
            }
            //fetch order from API
            $rzp_order_data = $this->rzp->order->fetch($rzp_order_id);
            $receipt = isset($rzp_order_data->receipt) ? $rzp_order_data->receipt : null;
            if ($receipt !== $order_id)
            {
                throw new GraphQlInputException(__('Not a valid Razorpay orderID'));
            }
            $rzpOrderAmount = $rzp_order_data->amount;
            if (isset($salesOrder['entity_id']) && empty($salesOrder['entity_id']) === false)
            {
                if ($order)
                {
                    $amountPaid = number_format($rzpOrderAmount / 100, 2, ".", "");
                    if ($order->getStatus() === 'pending')
                    {
                        $order->setState(static::STATUS_PROCESSING)->setStatus(static::STATUS_PROCESSING);
                    }

                    $order->addStatusHistoryComment(
                        __(
                            '%1 amount of %2 online. Transaction ID: "' . $rzp_payment_id . '"',
                            $payment_capture,
                            $order->getBaseCurrency()->formatTxt($amountPaid)
                        )
                    );

                    if ($order->canInvoice() && $this->config->canAutoGenerateInvoice()
                        && $rzp_order_data->status === 'paid')
                    {
                        $invoice = $this->invoiceService->prepareInvoice($order);
                        $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                        $invoice->setTransactionId($rzp_payment_id);
                        $invoice->register();
                        $invoice->save();

                        $transactionSave = $this->transaction
                          ->addObject($invoice)
                          ->addObject($invoice->getOrder());
                        $transactionSave->save();

                        $order->addStatusHistoryComment(
                            __('Notified customer about invoice #%1.', $invoice->getId())
                        )->setIsCustomerNotified(true);
                    }
                    $order->save();
                }
            }
        } catch (\Razorpay\Api\Errors\Error $e)
        {
            throw new GraphQlInputException(__('Razorpay Error: %1.', $e->getMessage()));
        } catch (\Exception $e)
        {
            throw new GraphQlInputException(__('Error: %1.', $e->getMessage()));
        }

        return [
            'order' => [
                'order_id' => $receipt
            ]
        ];
    }
}
