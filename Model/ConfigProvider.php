<?php

namespace Razorpay\Magento\Model;

use Magento\Payment\Helper\Data as PaymentHelper;
use Razorpay\Magento\Model\PaymentMethod;
use Magento\Checkout\Model\ConfigProviderInterface;

class ConfigProvider implements ConfigProviderInterface
{
    /**
     * @var string[]
     */
    protected $methodCode = PaymentMethod::METHOD_CODE;

    /**
     * @var \Razorpay\Magento\Model\Config
     */
    protected $config;

    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $checkoutSession;

    /**
     * @var \Magento\Customer\Model\Session
     */
    protected $customerSession;

    /**
     * Url Builder
     *
     * @var \Magento\Framework\Url
     */
    protected $urlBuilder;

    /**
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Framework\Url $urlBuilder
     */
    public function __construct(
        \Magento\Framework\View\Asset\Repository $assetRepo,
        \Magento\Framework\App\RequestInterface $request,
        \Magento\Framework\Url $urlBuilder,
        \Psr\Log\LoggerInterface $logger,
        PaymentHelper $paymentHelper,
        \Razorpay\Magento\Model\Config $config,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Customer\Model\Session $customerSession
    ) {
        $this->assetRepo = $assetRepo;
        $this->request = $request;
        $this->urlBuilder = $urlBuilder;
        $this->logger = $logger;
        $this->methodCode = PaymentMethod::METHOD_CODE;
        $this->method = $paymentHelper->getMethodInstance(PaymentMethod::METHOD_CODE);
        $this->config = $config;
        $this->checkoutSession = $checkoutSession;
        $this->customerSession = $customerSession;
    }

    /**
     * @return array|void
     */
    public function getConfig()
    {
        if (!$this->config->isActive()) {
            return [];
        }

        $config = [
            'payment' => [
                'razorpay' => [
                    'merchant_name' => $this->config->getMerchantNameOverride(),
                    'key_id'    => $this->config->getKeyId()
                ],
            ],
        ];

        return $config;
    }
}
