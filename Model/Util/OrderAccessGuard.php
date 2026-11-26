<?php

declare(strict_types=1);

namespace Razorpay\Magento\Model\Util;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Razorpay\Magento\Model\Config;
use Razorpay\Magento\Model\PaymentMethod;

/**
 * Single ownership guard for every route that accepts an order identifier
 * from the request.
 *
 * Order increment ids are sequential, so any handler that resolves an order
 * from a request parameter must prove the caller owns it. A caller owns an
 * order when at least one of these holds:
 *
 *   - it presents a valid signed callback token issued for that order
 *   - it is the order recorded as its own session's last real order
 *   - it presents the masked cart id of the order's quote (guest capability)
 *   - it is the logged-in customer the order belongs to
 */
class OrderAccessGuard
{
    /**
     * Lifetime of a callback token. Only needs to cover the redirect back
     * from the Razorpay hosted page.
     */
    const TOKEN_TTL = 3600;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @var MaskedQuoteIdToQuoteIdInterface
     */
    private $maskedQuoteIdToQuoteId;

    public function __construct(
        Config $config,
        CheckoutSession $checkoutSession,
        MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId
    ) {
        $this->config                 = $config;
        $this->checkoutSession        = $checkoutSession;
        $this->maskedQuoteIdToQuoteId = $maskedQuoteIdToQuoteId;
    }

    /**
     * Issue a short-lived signed token binding an order to its callback URL.
     *
     * The hosted return can arrive cross-site without the session cookie, so
     * the token is what carries proof of ownership across that boundary.
     *
     * @param OrderInterface $order
     * @return string
     */
    public function issueCallbackToken(OrderInterface $order): string
    {
        $expiry  = time() + self::TOKEN_TTL;
        $payload = $this->tokenPayload($order, $expiry);

        return $expiry . '.' . $this->sign($payload);
    }

    /**
     * The token is bound to both identities of the order, so it cannot be
     * replayed against an order that reuses the same increment id in another
     * store or after a database restore.
     *
     * @param OrderInterface $order
     * @param string|int $expiry
     * @return string
     */
    private function tokenPayload(OrderInterface $order, $expiry): string
    {
        return (int) $order->getEntityId() . '|' . $order->getIncrementId() . '|' . $expiry;
    }

    /**
     * Constant-time verification of a callback token.
     *
     * @param OrderInterface $order
     * @param string|null $token
     * @return bool
     */
    public function hasValidToken(OrderInterface $order, ?string $token): bool
    {
        if (empty($token) === true || substr_count($token, '.') !== 1) {
            return false;
        }

        list($expiry, $signature) = explode('.', $token, 2);

        if (ctype_digit($expiry) === false || (int) $expiry < time()) {
            return false;
        }

        return hash_equals($this->sign($this->tokenPayload($order, $expiry)), $signature);
    }

    /**
     * Whether the caller owns the order, by any accepted proof.
     *
     * @param OrderInterface $order
     * @param int|null $maskedCartId resolved from the request, if supplied
     * @param string|null $token
     * @return bool
     */
    public function isOwner(
        OrderInterface $order,
        ?int $customerId,
        ?string $maskedCartId = null,
        ?string $token = null
    ): bool {
        if ($this->hasValidToken($order, $token) === true) {
            return true;
        }

        if ($this->isSessionOrder($order) === true) {
            return true;
        }

        if (empty($maskedCartId) === false && $this->maskedCartMatches($maskedCartId, $order) === true) {
            return true;
        }

        $orderCustomerId = (int) $order->getCustomerId();

        // Guest orders (customer_id 0/null) have no customer to match, so they
        // are only reachable through the session, masked cart id or token above.
        if ($orderCustomerId > 0 && $customerId !== null && (int) $customerId === $orderCustomerId) {
            return true;
        }

        return false;
    }

    /**
     * Throwing wrapper around isOwner() for GraphQL resolvers, so the caller
     * gets a GraphQL authorization error rather than a swallowed message.
     *
     * @param OrderInterface $order
     * @param int|null $customerId
     * @param string|null $maskedCartId
     * @param string|null $token
     * @return void
     * @throws GraphQlAuthorizationException
     */
    public function assertOwnerForGraphQl(
        OrderInterface $order,
        ?int $customerId,
        ?string $maskedCartId = null,
        ?string $token = null
    ): void {
        if ($this->isOwner($order, $customerId, $maskedCartId, $token) === false) {
            throw new GraphQlAuthorizationException(
                __('You are not authorized to access this order.')
            );
        }
    }

    /**
     * Throwing wrapper around isOwner(), for controllers.
     *
     * @param OrderInterface $order
     * @param int|null $customerId
     * @param string|null $maskedCartId
     * @param string|null $token
     * @return void
     * @throws LocalizedException
     */
    public function assertOwner(
        OrderInterface $order,
        ?int $customerId,
        ?string $maskedCartId = null,
        ?string $token = null
    ): void {
        if ($this->isOwner($order, $customerId, $maskedCartId, $token) === false) {
            throw new LocalizedException(
                __('You are not authorized to access this order.')
            );
        }
    }

    /**
     * Every order-changing route requires the order to still be open and to
     * have been placed with this payment method.
     *
     * @param OrderInterface $order
     * @return void
     * @throws LocalizedException
     */
    public function assertPayable(OrderInterface $order): void
    {
        if ($order->getState() !== Order::STATE_NEW) {
            throw new LocalizedException(
                __('This order is no longer open for payment.')
            );
        }

        $payment = $order->getPayment();

        if ($payment !== null && $payment->getMethod() !== PaymentMethod::METHOD_CODE) {
            throw new LocalizedException(
                __('This order was not placed with Razorpay.')
            );
        }

        // A paid or invoiced order must never be re-opened for payment, even
        // when its state still reads new.
        $hasInvoices = method_exists($order, 'hasInvoices') ? $order->hasInvoices() : false;

        if ($hasInvoices === true || (float) $order->getTotalPaid() > 0) {
            throw new LocalizedException(
                __('This order is already paid.')
            );
        }
    }

    /**
     * An order may only be paid through the store it was placed on.
     *
     * @param OrderInterface $order
     * @param int $storeId
     * @return void
     * @throws LocalizedException
     */
    public function assertStore(OrderInterface $order, int $storeId): void
    {
        $orderStoreId = (int) $order->getStoreId();

        if ($orderStoreId > 0 && $storeId > 0 && $orderStoreId !== $storeId) {
            throw new LocalizedException(
                __('This order belongs to a different store.')
            );
        }
    }

    /**
     * @param OrderInterface $order
     * @return bool
     */
    private function isSessionOrder(OrderInterface $order): bool
    {
        $sessionOrder = $this->checkoutSession->getLastRealOrder();

        if ($sessionOrder === null || $sessionOrder->getEntityId() === null) {
            return false;
        }

        return (int) $sessionOrder->getEntityId() === (int) $order->getEntityId();
    }

    /**
     * @param string $maskedCartId
     * @param OrderInterface $order
     * @return bool
     */
    private function maskedCartMatches(string $maskedCartId, OrderInterface $order): bool
    {
        try {
            $quoteId = (int) $this->maskedQuoteIdToQuoteId->execute($maskedCartId);
        } catch (\Exception $e) {
            return false;
        }

        return $quoteId > 0 && $quoteId === (int) $order->getQuoteId();
    }

    /**
     * @param string $payload
     * @return string
     */
    private function sign(string $payload): string
    {
        return hash_hmac(
            'sha256',
            $payload,
            (string) $this->config->getConfigData(Config::KEY_PRIVATE_KEY)
        );
    }
}
