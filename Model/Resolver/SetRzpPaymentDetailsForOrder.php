<?php

declare(strict_types=1);

namespace Razorpay\Magento\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Razorpay\Magento\Model\PaymentMethod;
use Razorpay\Magento\Model\Config;
use Magento\Sales\Model\Order\Payment\State\CaptureCommand;
use Magento\Sales\Model\Order\Payment\State\AuthorizeCommand;

/**
 * Mutation resolver for setting payment method for shopping cart
 */
class SetRzpPaymentDetailsForOrder implements ResolverInterface
{
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
     * @var \Magento\Checkout\Model\Session
     */
    protected $checkoutSession;

    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\InvoiceSender
     */
    protected $invoiceSender;

    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\OrderSender
     */
    protected $orderSender;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var STATUS_PROCESSING
     */

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    protected const STATUS_PROCESSING = 'processing';

    /**
     * @param PaymentMethod $paymentMethod
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param \Razorpay\Magento\Model\Config $config
     * @param \Magento\Sales\Model\Service\InvoiceService $invoiceService
     * @param \Magento\Framework\DB\Transaction $transaction
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender
     * @param \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        PaymentMethod $paymentMethod,
        \Magento\Sales\Api\Data\OrderInterface $order,
        \Razorpay\Magento\Model\Config $config,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Framework\DB\Transaction $transaction,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
        \Psr\Log\LoggerInterface $logger
    )
    {
        $this->rzp             = $paymentMethod->rzp;
        $this->order           = $order;
        $this->config          = $config;
        $this->invoiceService  = $invoiceService;
        $this->transaction     = $transaction;
        $this->scopeConfig     = $scopeConfig;
        $this->checkoutSession = $checkoutSession;
        $this->invoiceSender   = $invoiceSender;
        $this->orderSender     = $orderSender;
        $this->logger          = $logger;
    }

    /**
     * @inheritdoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null)
    {
        $this->logger->info('graphQL: Set Razorpay Payment Details for Order Started');

        if (empty($args['input']['order_id']))
        {
            $this->logger->critical('graphQL: Input Exception: Required parameter "order_id" is missing.');

            throw new GraphQlInputException(__('Required parameter "order_id" is missing.'));
        }

        $order_id = $args['input']['order_id'];

        if (empty($args['input']['rzp_payment_id']))
        {
            $this->logger->critical('graphQL: Input Exception: Required parameter "rzp_payment_id" is missing.');

            throw new GraphQlInputException(__('Required parameter "rzp_payment_id" is missing.'));
        }

        $rzp_payment_id = $args['input']['rzp_payment_id'];

        if (empty($args['input']['rzp_signature']))
        {
            $this->logger->critical('graphQL: Input Exception: Required parameter "rzp_signature" is missing.');

            throw new GraphQlInputException(__('Required parameter "rzp_signature" is missing.'));
        }

        $rzp_signature = $args['input']['rzp_signature'];

        $this->logger->info('graphQL: Order Data'
            . ' order_id:' . $order_id . ','
            . ' rzp_payment_id:' . $rzp_payment_id . ','
            . ' rzp_signature:' . $rzp_signature);

        $rzp_order_id = '';
        try
        {
            $order = $this->order->load($order_id, $this->order::INCREMENT_ID);
            if ($order)
            {
                $rzp_order_id = $order->getRzpOrderId();
                if(null !== $rzp_order_id)
                {
                    $this->logger->info('graphQL: Razorpay'
                    . ' Order ID:'  . $rzp_order_id);
                } else
                {
                    $this->logger->critical('graphQL: ' .
                    'Unable to Razorpay Order ID ' .
                    'for Order ID:' . $order_id);

                    throw new GraphQlInputException(__('Error: %1.',
                    'Unable to Razorpay Order ID ' .
                    'for Order ID:' . $order_id));
                }
            }
        } catch (\Exception $e)
        {
            $this->logger->critical('graphQL: '
            . ' Error: ' . $e->getMessage());

            throw new GraphQlInputException(__('Error: %1.', $e->getMessage()));
        }

        $this->logger->info('graphQL: Verify Signature'
            . ' razorpay_payment_id:' . $rzp_payment_id . ','
            . ' razorpay_order_id:' . $rzp_order_id . ','
            . ' razorpay_signature:' . $rzp_signature);

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

            $this->logger->info('graphQL: payment_action:' . $payment_action);

            //fetch order from API
            $rzp_order_data = $this->rzp->order->fetch($rzp_order_id);
            $receipt = isset($rzp_order_data->receipt) ? $rzp_order_data->receipt : null;

            $this->logger->info('graphQL: Razorpay Order receipt:' . $receipt);

            if ($receipt !== $order_id)
            {
                $this->logger->critical('graphQL: Not a valid Razorpay orderID');

                throw new GraphQlInputException(__('Not a valid Razorpay orderID'));
            }
            $rzpOrderAmount = $rzp_order_data->amount;

            if ($order)
            {
                $amountPaid = number_format($rzpOrderAmount / 100, 2, ".", "");
                if ($order->getStatus() === 'pending')
                {
                    $order->setState(static::STATUS_PROCESSING)->setStatus(static::STATUS_PROCESSING);

                    $this->logger->info('graphQL: Order Status Updated to ' . static::STATUS_PROCESSING);
                }

                $payment = $order->getPayment();

                $payment->setLastTransId($rzp_payment_id)
                        ->setTransactionId($rzp_payment_id)
                        ->setIsTransactionClosed(true)
                        ->setShouldCloseParentTransaction(true);

                $payment->setParentTransactionId($payment->getTransactionId());

                if ($this->config->getPaymentAction()  === \Razorpay\Magento\Model\PaymentMethod::ACTION_AUTHORIZE_CAPTURE)
                {
                    $payment->addTransactionCommentsToOrder(
                        "$rzp_payment_id",
                        (new CaptureCommand())->execute(
                            $payment,
                            $order->getGrandTotal(),
                            $order
                        ),
                        ""
                    );
                } else
                {
                    $payment->addTransactionCommentsToOrder(
                        "$rzp_payment_id",
                        (new AuthorizeCommand())->execute(
                            $payment,
                            $order->getGrandTotal(),
                            $order
                        ),
                        ""
                    );
                }

                $transaction = $payment->addTransaction(\Magento\Sales\Model\Order\Payment\Transaction::TYPE_AUTH, null, true, "");

                $transaction->setIsClosed(true);

                $transaction->save();

                if ($order->canInvoice() && $this->config->canAutoGenerateInvoice()
                    && $rzp_order_data->status === 'paid')
                {
                    $invoice = $this->invoiceService->prepareInvoice($order);
                    $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                    $invoice->setTransactionId($rzp_payment_id);
                    $invoice->register();
                    $invoice->save();

                    $this->logger->info('graphQL: Created Invoice for '
                    . 'order_id ' . $order_id . ', '
                    . 'rzp_payment_id ' . $rzp_payment_id);

                    $transactionSave = $this->transaction
                        ->addObject($invoice)
                        ->addObject($invoice->getOrder());
                    $transactionSave->save();

                    $this->invoiceSender->send($invoice);

                    $order->addStatusHistoryComment(
                        __('Notified customer about invoice #%1.', $invoice->getId())
                    )->setIsCustomerNotified(true);
                    try {
                        $this->checkoutSession->setRazorpayMailSentOnSuccess(true);
                        $this->orderSender->send($order);
                        $this->checkoutSession->unsRazorpayMailSentOnSuccess();
                    } catch (\Magento\Framework\Exception\MailException $e) {
                        $this->logger->critical('graphQL: '
                        . 'Razorpay Error:' . $e->getMessage());

                        throw new GraphQlInputException(__('Razorpay Error: %1.', $e->getMessage()));
                    } catch (\Exception $e) {
                        $this->logger->critical('graphQL: '
                        . 'Error:' . $e->getMessage());

                        throw new GraphQlInputException(__('Error: %1.', $e->getMessage()));
                    }
                }
                $order->save();
            }
        } catch (\Razorpay\Api\Errors\Error $e)
        {
            $this->logger->critical('graphQL: '
            . 'Razorpay Error:' . $e->getMessage());

            throw new GraphQlInputException(__('Razorpay Error: %1.', $e->getMessage()));
        } catch (\Exception $e)
        {
            $this->logger->critical('graphQL: '
            . 'Error:' . $e->getMessage());

            throw new GraphQlInputException(__('Error: %1.', $e->getMessage()));
        }

        return [
            'order' => [
                'order_id' => $receipt
            ]
        ];
    }
}
