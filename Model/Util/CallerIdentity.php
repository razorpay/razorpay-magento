<?php

declare(strict_types=1);

namespace Razorpay\Magento\Model\Util;

use Magento\Authorization\Model\UserContextInterface;

/**
 * Resolves the caller's identity from the GraphQL context.
 *
 * Only a customer token may be used as proof of order ownership. Admin and
 * integration tokens produce a user id from a different sequence, so their
 * ids must never be compared with an order's customer id.
 */
class CallerIdentity
{
    /**
     * @param mixed $context GraphQL resolver context
     * @return int|null
     */
    public function customerId($context): ?int
    {
        if ($context === null || method_exists($context, 'getUserId') === false) {
            return null;
        }

        if (method_exists($context, 'getUserType') === true
            && (int) $context->getUserType() !== (int) UserContextInterface::USER_TYPE_CUSTOMER) {
            return null;
        }

        $userId = $context->getUserId();

        if ($userId === null) {
            return null;
        }

        $customerId = (int) $userId;

        return ($customerId > 0) ? $customerId : null;
    }
}
