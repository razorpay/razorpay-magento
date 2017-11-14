<?php

namespace Razorpay\Magento\Model;

use Razorpay\Magento\Model\PaymentMethod;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\UrlInterface as UrlInterface;

class ConfigProvider implements ConfigProviderInterface
{
    /**
     * @var string
     */
    protected $methodCode = Razorpay::CODE;

    protected $method;

    protected $urlBuilder;

    protected $config;

    public function __construct(PaymentHelper $paymentHelper, UrlInterface $urlBuilder, Config $config)
    {
        $this->method = $paymentHelper->getMethodInstance($this->methodCode);

        $this->urlBuilder = $urlBuilder;

        $this->config = $config;
    }

    /**
     * @return array|void
     */
    public function getConfig()
    {
        $config = [];

        if ($this->method->isAvailable())
        {
            $config = [
                'payment' => [
                    'razorpay' => [
                        'merchant_name' => $this->config->getMerchantNameOverride(),
                        'key_id'    => $this->config->getKeyId()
                    ],
                ],
            ];
        }

        return $config;
    }
}
