<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

/**
 * @covers Razorpay\Magento\Model\Config
 */
class ConfigModelTest extends TestCase {
    const KEY_ALLOW_SPECIFIC              = 'allowspecific';
    const KEY_SPECIFIC_COUNTRY            = 'specificcountry';
    const KEY_ACTIVE                      = 'active';
    const KEY_PUBLIC_KEY                  = 'key_id';
    const KEY_PRIVATE_KEY                 = 'key_secret';
    const KEY_MERCHANT_NAME_OVERRIDE      = 'merchant_name_override';
    const KEY_PAYMENT_ACTION              = 'rzp_payment_action';
    const KEY_AUTO_INVOICE                = 'auto_invoice';
    const KEY_NEW_ORDER_STATUS            = 'order_status';
    const ENABLE_WEBHOOK                  = 'enable_webhook';
    const WEBHOOK_SECRET                  = 'webhook_secret';
    const ENABLE_PENDING_ORDERS_CRON      = 'enable_pending_orders_cron';
    const PENDING_ORDER_TIMEOUT           = 'pending_orders_timeout';
    const ENABLE_RESET_CART_CRON          = 'enable_reset_cart_cron';
    const RESET_CART_ORDERS_TIMEOUT       = 'reset_cart_orders_timeout';
    const ENABLE_CUSTOM_PAID_ORDER_STATUS = 'enable_custom_paid_order_status';
    const CUSTOM_PAID_ORDER_STATUS        = 'custom_paid_order_status';

	public function setUp():void
	{
		$this->scopeConfigInterface = \Mockery::mock(
		    \Magento\Framework\App\Config\ScopeConfigInterface::class
		);

		$this->writerInterface = \Mockery::mock(
		    \Magento\Framework\App\Config\Storage\WriterInterface::class
		);

		$this->configModel = \Mockery::mock(Razorpay\Magento\Model\Config::class,
											[
											    $this->scopeConfigInterface,
											    $this->writerInterface
											])
											->makePartial()
											->shouldAllowMockingProtectedMethods();

		$this->configModel->shouldReceive('getConfigData')
						  ->with(self::KEY_ALLOW_SPECIFIC)
						  ->andReturn(1);

		$this->configModel->shouldReceive('getConfigData')
		                  ->with(self::KEY_ACTIVE,null)
		                  ->andReturn(true);

		$this->configModel->shouldReceive('getConfigData')
		                  ->with(self::KEY_PUBLIC_KEY)
		                  ->andReturn('sample_key_id');

		$this->configModel->shouldReceive('getConfigData')
		                  ->with(self::KEY_PRIVATE_KEY)
		                  ->andReturn('sample_key_secret');

		$this->configModel->shouldReceive('getConfigData')
		                  ->with(self::KEY_MERCHANT_NAME_OVERRIDE)
		                  ->andReturn('My Ecommerce Site');

		$this->configModel->shouldReceive('getConfigData')
		                  ->with(self::KEY_PAYMENT_ACTION)
		                  ->andReturn('authorize_capture');

		$this->configModel->shouldReceive('getConfigData')
		                  ->with(self::KEY_AUTO_INVOICE,null)
		                  ->andReturn(true);

		$this->configModel->shouldReceive('getConfigData')
						  ->with(self::KEY_NEW_ORDER_STATUS)
						  ->andReturn('pending');

		$this->configModel->shouldReceive('getConfigData')
		                  ->with(self::ENABLE_WEBHOOK, null)
		                  ->andReturn((true));

		$this->configModel->shouldReceive('getConfigData')
		                  ->with(self::WEBHOOK_SECRET)
		                  ->andReturn('webhook_secret');

		$this->configModel->shouldReceive('getConfigData')
		                  ->with(self::ENABLE_PENDING_ORDERS_CRON, null)
		                  ->andReturn(true);

		$this->configModel->shouldReceive('getConfigData')
		                  ->with(self::PENDING_ORDER_TIMEOUT)
		                  ->andReturn(30);

		$this->configModel->shouldReceive('getConfigData')
		                  ->with(self::ENABLE_RESET_CART_CRON, null)
		                  ->andReturn(true);

		$this->configModel->shouldReceive('getConfigData')
		                  ->with(self::ENABLE_CUSTOM_PAID_ORDER_STATUS, null)
		                  ->andReturn(true);

		$this->configModel->shouldReceive('getConfigData')
		                  ->with(self::RESET_CART_ORDERS_TIMEOUT)
		                  ->andReturn(30);

		$this->configModel->shouldReceive('getConfigData')
		                  ->with(self::CUSTOM_PAID_ORDER_STATUS)
		                  ->andReturn('paymentcompleted');

		$this->scopeConfigInterface->shouldReceive('getValue')
		                           ->with('payment/razorpay/sample_key','store',NULL)
		                           ->andReturn('sample_value');

    }

	function testGetMerchantNameOverride()
	{
		$expectedResult = 'My Ecommerce Site';

		$merchantName = $this->configModel->getMerchantNameOverride();

		$this->assertSame($expectedResult, $merchantName);
	}

	function testKeyId()
	{
		$expectedResult = 'sample_key_id';

		$keyId = $this->configModel->getKeyId();

		$this->assertSame($expectedResult, $keyId);
	}

	function testIsWebhookEnabled()
	{
		$expectedResult = true;

		$webhookEnabled = $this->configModel->isWebhookEnabled();

		$this->assertSame($expectedResult, $webhookEnabled);
	}

	function testGetWebhookSecret()
	{
		$expectedResult = 'webhook_secret';

		$webhookSecret = $this->configModel->getWebhookSecret();

		$this->assertSame($expectedResult, $webhookSecret);
	}

	function testIsCancelPendingOrderCronEnabled()
	{
		$expectedResult = true;

		$cancelPendingOrderCronEnabled = $this->configModel->isCancelPendingOrderCronEnabled();

		$this->assertSame($expectedResult, $cancelPendingOrderCronEnabled);
	}

	function testGetPendingOrderTimeout()
	{
		$expectedResult = 30;

		$pendingOrderTimeout = $this->configModel->getPendingOrderTimeout();

		$this->assertSame($expectedResult, $pendingOrderTimeout);
	}

	function testIsCancelResetCartOrderCronEnabled()
	{
		$expectedResult = true;

		$cancelResetCartOrderCronEnabled = $this->configModel->isCancelResetCartOrderCronEnabled();

		$this->assertSame($expectedResult, $cancelResetCartOrderCronEnabled);
	}

	function testGetResetCartOrderTimeout()
	{
		$expectedResult = 30;

		$resetCartOrderTimeout = $this->configModel->getResetCartOrderTimeout();

		$this->assertSame($expectedResult, $resetCartOrderTimeout);
	}

	function testIsCustomPaidOrderStatusEnabled()
	{
		$expectedResult = true;

		$isCustomPaidOrderStatusEnabled = $this->configModel->isCustomPaidOrderStatusEnabled();

		$this->assertSame($expectedResult, $isCustomPaidOrderStatusEnabled);
	}

	function testGetCustomPaidOrderStatus()
	{
		$expectedResult = 'paymentcompleted';

		$customPaidOrderStatus = $this->configModel->getCustomPaidOrderStatus();

		$this->assertSame($expectedResult, $customPaidOrderStatus);
	}

	function testGetPaymentAction()
	{
		$expectedResult = 'authorize_capture';

		$paymentAction = $this->configModel->getPaymentAction();

		$this->assertSame($expectedResult, $paymentAction);
	}

	function testNewOrderStatus()
	{
		$expectedResult = 'pending';

		$orderStatus = $this->configModel->getNewOrderStatus();

		$this->assertSame($expectedResult, $orderStatus);
	}

	function testIsActive()
	{
		$expectedResult = true;

		$isActive = $this->configModel->isActive();

		$this->assertSame($expectedResult, $isActive);
	}

	function testCanAutoGenerateInvoice()
	{
		$expectedResult = true;

		$canAutoGenerateInvoice = $this->configModel->canAutoGenerateInvoice();

		$this->assertSame($expectedResult, $canAutoGenerateInvoice);
	}

	function testCanUseForCountry()
	{
		$this->configModel->shouldReceive('getConfigData')
		                  ->with(self::KEY_SPECIFIC_COUNTRY)
		                  ->andReturn('US');

		$expectedResult = false;

		$canUseForCountry = $this->configModel->canUseForCountry('IN');

		$this->assertSame($expectedResult, $canUseForCountry);
	}

	function testCanUseForCountries()
	{
		$this->configModel->shouldReceive('getConfigData')
		                  ->with(self::KEY_SPECIFIC_COUNTRY)
		                  ->andReturn('IN,BR');

		$expectedResult = true;

		$canUseForCountry = $this->configModel->canUseForCountry('IN');

		$this->assertSame($expectedResult, $canUseForCountry);
	}

	function testGetConfigData()
	{
		$expectedResult = 'sample_value';

		$sampleConfigValue = $this->configModel->getConfigData('sample_key');

		$this->assertSame($expectedResult, $sampleConfigValue);
	}

	function testSetConfigData()
	{
		$this->writerInterface->shouldReceive('save')
		                      ->with('payment/razorpay/sample_key', 'sample_value');

		$inputKey   = 'sample_key';
		$inputvalue = 'sample_value';

		$this->configModel->setConfigData($inputKey, $inputvalue);
	}

	function testSetStoreId()
	{
		$expectedResult = $this->configModel->setStoreId(1);

		$this->assertSame($expectedResult, $this->configModel);
	}
}
