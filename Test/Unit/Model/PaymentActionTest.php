<?php
/**
 * Copyright © Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Razorpay\Magento\Test\Unit\Model;

use \Magento\Framework\Option\ArrayInterface;
use Razorpay\Magento\Model\PaymentAction;
use PHPUnit\Framework\TestCase;

class PaymentActionTest extends TestCase
{
    public function testToOptionArray()
    {
        $sourceModel = new PaymentAction();
        static::assertEquals(
            [
                [
                    'value' => \Razorpay\Magento\Model\PaymentMethod::ACTION_AUTHORIZE,
                    'label' => __('Authorize Only'),
                ],
                [
                    'value' => \Razorpay\Magento\Model\PaymentMethod::ACTION_AUTHORIZE_CAPTURE,
                    'label' => __('Authorize and Capture')
                ]
            ],
            $sourceModel->toOptionArray()
        );
    }
}