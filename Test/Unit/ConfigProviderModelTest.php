<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @covers Razorpay\Magento\Model\ConfigProvider
 */
class ConfigProviderModelTest extends TestCase
{
    public function setup():void
    {
        $this->assetRepo = \Mockery::mock(
            \Magento\Framework\View\Asset\Repository::class
        );

        $this->request = \Mockery::mock(
            \Magento\Framework\App\RequestInterface::class
        );

        $this->urlBuilder = \Mockery::mock(
            \Magento\Framework\Url::class
        );

        $this->logger = \Mockery::mock(
            \Psr\Log\LoggerInterface::class
        );

        $this->paymentHelper = \Mockery::mock(
            \Magento\Payment\Helper\Data::class
        );

        $this->config = \Mockery::mock(
            \Razorpay\Magento\Model\Config::class
        );

        $this->checkoutSession = \Mockery::mock(
            \Magento\Checkout\Model\Session::class
        );

        $this->customerSession = \Mockery::mock(
            \Magento\Customer\Model\Session::class
        );

        $this->methodInterface = \Mockery::mock(
            Magento\Payment\Model\MethodInterface::class
        );
       
        $this->paymentHelper->shouldReceive('getMethodInstance')->andReturn($this->methodInterface);

        $this->configProviderModel = \Mockery::mock(Razorpay\Magento\Model\ConfigProvider::class,
            [
                $this->assetRepo,
                $this->request,
                $this->urlBuilder,
                $this->logger,
                $this->paymentHelper,
                $this->config,
                $this->checkoutSession,
                $this->customerSession
            ])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
    }

    public function testGetConfigIsActiveFalse()
    {
        $this->config->shouldReceive('isActive')->andReturn(false);

        $response = $this->configProviderModel->getConfig();

        $this->assertSame([], $response);
    }
    
    public function testGetConfigActiveTrue()
    {
        $this->config->shouldReceive('isActive')->andReturn(true);
        $this->config->shouldReceive('getMerchantNameOverride')->andReturn('My Ecommerce Site');
        $this->config->shouldReceive('getKeyId')->andReturn('sample_key_id');

        $expected = [
            'payment' => [
                'razorpay' => [
                    'merchant_name' => 'My Ecommerce Site',
                    'key_id'        => 'sample_key_id'
                ]
            ]
        ];

        $response = $this->configProviderModel->getConfig();

        $this->assertSame($expected, $response);
    }
}
