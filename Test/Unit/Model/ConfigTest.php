<?php
namespace Razorpay\Magento\Test\Unit\Model;

use Razorpay\Magento\Model\Config as Config;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{

    /**
     * @var ScopeConfigInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    private $scopeConfig;

    private $configParams = array(
                'access_key' => 'rzp_test_y2knCeNilinxFI',
                'secret_key' => "abc123Def456gHi789jKLmpQ987rstu6vWxyz"
            );

    protected function setUp()
    {
        $this->scopeConfig = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);

        $this->config = new Config($this->scopeConfig);
    }


    public function testGetKeyId()
    {
    	$this->scopeConfig->expects(static::once())
            ->method('getValue')
            ->with(
                'payment/razorpay/key_id',
                 \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                null
            )->willReturn('rzp_test_y2knCeNilinxFI');
            
    	$this->assertEquals($this->configParams['access_key'], $this->config->getConfigData(Config::KEY_PUBLIC_KEY));

    }
}