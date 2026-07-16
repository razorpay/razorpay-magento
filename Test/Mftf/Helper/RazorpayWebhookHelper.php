<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Razorpay\Magento\Test\Mftf\Helper;

use Magento\FunctionalTestingFramework\Helper\Helper;

/**
 * Simulates a real Razorpay webhook POST (payment.authorized / order.paid),
 * including a genuine HMAC-SHA256 X-Razorpay-Signature header computed the
 * same way Razorpay\Api\Utility::verifySignature() validates it -- so this
 * exercises Controller/Payment/Webhook::execute()'s real signature
 * verification path end-to-end, the same as a live Razorpay webhook would.
 */
class RazorpayWebhookHelper extends Helper
{
    /**
     * @param string $baseUrl Storefront base URL, e.g. https://magento.test/
     * @param string $webhookSecret Must match the payment/razorpay/webhook_secret
     *        value configured via RazorpaySetWebhookSecretActionGroup for this test.
     * @param string $event e.g. "payment.authorized" or "order.paid"
     * @param string $razorpayOrderId The rzp_order_id stored on the OrderLink row.
     * @param string $razorpayPaymentId
     * @param int $amountInPaise
     * @param string $merchantOrderId The Magento order increment_id.
     *
     * @return void
     */
    public function postSignedWebhook(
        string $baseUrl,
        string $webhookSecret,
        string $event,
        string $razorpayOrderId,
        string $razorpayPaymentId,
        string $amountInPaise,
        string $merchantOrderId
    ): void {
        $amount = (int) $amountInPaise;

        $payload = [
            'event' => $event,
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => $razorpayPaymentId,
                        'order_id' => $razorpayOrderId,
                        'amount' => $amount,
                        'notes' => [
                            'merchant_order_id' => $merchantOrderId,
                        ],
                    ],
                ],
                'order' => [
                    'entity' => [
                        'amount_paid' => $amount,
                    ],
                ],
            ],
        ];

        $body = json_encode($payload);
        $signature = hash_hmac('sha256', $body, $webhookSecret);

        $webhookUrl = rtrim($baseUrl, '/') . '/razorpay/payment/webhook';

        $ch = curl_init($webhookUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Razorpay-Signature: ' . $signature,
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->fail("Webhook POST to $webhookUrl failed: $curlError");
        }

        if ($httpCode >= 400) {
            $this->fail("Webhook POST to $webhookUrl returned HTTP $httpCode. Response: $response");
        }
    }
}
