<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

/**
 * @covers Razorpay\Magento\Model\PaymentMethod
 */
class PaymentMethodTest extends TestCase {
    public function setUp():void
    {

        $this->context = \Mockery::mock(
            \Magento\Framework\Model\Context::class
        )->makePartial()
         ->shouldAllowMockingProtectedMethods();

        $this->registry = $this->createMock(
            \Magento\Framework\Registry::class
        );

        $this->extensionFactory = $this->createMock(
            \Magento\Framework\Api\ExtensionAttributesFactory::class
        );

        $this->customAttributeFactory = $this->createMock(
            \Magento\Framework\Api\AttributeValueFactory::class
        );

        $this->paymentData = $this->createMock(
            \Magento\Payment\Helper\Data::class
        );

        $this->scopeConfig = \Mockery::mock(
            \Magento\Framework\App\Config\ScopeConfigInterface::class
        );

        $this->logger = \Mockery::mock(
            \Magento\Payment\Model\Method\Logger::class
        );

        $this->config = \Mockery::mock(
            \Razorpay\Magento\Model\Config::class
        );

        $this->requestInterface = \Mockery::mock(
            \Magento\Framework\App\RequestInterface::class
        );

        $this->salesTransactionCollectionFactory = $this->createMock(
            Magento\Sales\Model\ResourceModel\Order\Payment\Transaction\CollectionFactory::class
        );

        $this->productMetaData = \Mockery::mock(
            \Magento\Framework\App\ProductMetadataInterface::class
        );

        $this->regionFactory = $this->createMock(
            \Magento\Directory\Model\RegionFactory::class
        );

        $this->orderRepository = \Mockery::mock(
            \Magento\Sales\Api\OrderRepositoryInterface::class
        );

        $this->order = \Mockery::mock(
            \Razorpay\Magento\Controller\Payment\Order::class
        );

        $this->orderInterface = \Mockery::mock(
            \Magento\Sales\Api\Data\OrderInterface::class
        );

        $this->orderModel = \Mockery::mock(
            \Magento\Sales\Model\Order::class
        );

        $this->trackPluginInstrumentation = \Mockery::mock(
            Razorpay\Magento\Model\TrackPluginInstrumentation::class
        );

        $this->resource = $this->createMock(
            \Magento\Framework\Model\ResourceModel\AbstractResource::class
        );

        $this->resourceCollection = $this->createMock(
            \Magento\Framework\Data\Collection\AbstractDb::class
        );

        $this->billingAddressMock = \Mockery::mock(
            Magento\Quote\Model\Quote\Address::class
        );

        $this->apiError = \Mockery::mock(
            \Razorpay\Api\Errors\Error::class, ['Test Api error message', 0, 0]
        );

        $this->exceptionError = \Mockery::mock(
            \Exception::class, ['Test Api error message']
        );

        $this->paymentApi = \Mockery::mock(
            Razorpay\Api\Payment::class
        );

        $this->data = [];

        $this->productMetaDataInfo['channel'] = 'Magento';
        $this->productMetaDataInfo['edition'] = 'Community';
        $this->productMetaDataInfo['version'] = '2.4.2-p2';

        $this->productMetaData->shouldReceive('getEdition')
                              ->andReturn($this->productMetaDataInfo['edition']);
        $this->productMetaData->shouldReceive('getVersion')
                              ->andReturn($this->productMetaDataInfo['version']);

        $this->config->shouldReceive('getConfigData')
                     ->with('key_id')
                     ->andReturn('sample_key_id');

        $this->config->shouldReceive('getConfigData')
                     ->with('key_id',null)
                     ->andReturn('sample_key_id');

        $this->config->shouldReceive('getConfigData')
                     ->with('order_place_redirect_url',null)
                     ->andReturn('');

        $this->config->shouldReceive('getConfigData')
                     ->with('key_secret')
                     ->andReturn('sample_key_secret');

        $this->paymentMethodModel = \Mockery::mock(Razorpay\Magento\Model\PaymentMethod::class,
                                                [
                                                    $this->context,
                                                    $this->registry,
                                                    $this->extensionFactory,
                                                    $this->customAttributeFactory,
                                                    $this->paymentData,
                                                    $this->scopeConfig,
                                                    $this->logger,
                                                    $this->config,
                                                    $this->requestInterface,
                                                    $this->salesTransactionCollectionFactory,
                                                    $this->productMetaData,
                                                    $this->regionFactory,
                                                    $this->orderRepository,
                                                    $this->order,
                                                    $this->trackPluginInstrumentation,
                                                    $this->resource,
                                                    $this->resourceCollection,
                                                    $this->data
                                                ])->makePartial()
                                                  ->shouldAllowMockingProtectedMethods();

        $this->api = $this->getMockBuilder(Razorpay\Api\Api::class, ['sample_key_id', 'sample_key_secret'])
                          ->disableOriginalConstructor()
                          ->disableOriginalClone()
                          ->disableArgumentCloning()
                          ->disallowMockingUnknownTypes()
                          ->getMock();

        $this->api->method('__get')->willReturnCallback(function ($propertyName)
        {
            switch($propertyName)
            {
                case 'payment':
                return $this->paymentApi;
            }
        });

       $this->setProperty($this->paymentMethodModel, 'logger', $this->logger);
    }

    function setProperty($object, $propertyName, $value)
    {
        $reflection = new \ReflectionClass($object);
    
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);

        return $property->getValue($object);
    }

    public function testValidateOrder()
    {
        $this->infoInstance = \Mockery::mock(
            \Magento\Sales\Model\Order\Payment::class
        );

        $this->paymentMethodModel->shouldReceive('getInfoInstance')
                                 ->andReturn($this->infoInstance);

        $this->infoInstance->shouldReceive('getOrder')
                           ->andReturn($this->orderRepository);

        $this->orderRepository->shouldReceive('getBillingAddress')
                              ->andReturn($this->billingAddressMock);

        $this->billingAddressMock->shouldReceive('getCountryId')
                                 ->andReturn('IN');

        $this->config->shouldReceive('canUseForCountry')
                     ->with('IN')
                     ->andReturn(true);

        $response = $this->paymentMethodModel->validate();

        $this->assertSame($this->paymentMethodModel, $response);
    }

    public function testValidateQuote()
    {
        $this->infoInstance = \Mockery::mock(
            Magento\Quote\Model\Quote::class
        );

        $this->quote = \Mockery::mock(
            Magento\Quote\Model\Quote::class
        );

        $this->paymentMethodModel->shouldReceive('getInfoInstance')
                                 ->andReturn($this->infoInstance);

        $this->infoInstance->shouldReceive('getQuote')
                           ->andReturn($this->quote);

        $this->quote->shouldReceive('getBillingAddress')
                    ->andReturn($this->billingAddressMock);

        $this->billingAddressMock->shouldReceive('getCountryId')
                                 ->andReturn('IN');

        $this->config->shouldReceive('canUseForCountry')
                     ->with('IN')
                     ->andReturn(true);

        $response = $this->paymentMethodModel->validate();

        $this->assertSame($this->paymentMethodModel, $response);
    }

    public function testValidateException()
    {
        $this->infoInstance = \Mockery::mock(
            Magento\Quote\Model\Quote::class
        );

        $this->quote = \Mockery::mock(
            Magento\Quote\Model\Quote::class
        );

        $this->paymentMethodModel->shouldReceive('getInfoInstance')
                                 ->andReturn($this->infoInstance);

        $this->infoInstance->shouldReceive('getQuote')
                           ->andReturn($this->quote);

        $this->quote->shouldReceive('getBillingAddress')
                    ->andReturn($this->billingAddressMock);

        $this->billingAddressMock->shouldReceive('getCountryId')
                                 ->andReturn('IN');

        $this->config->shouldReceive('canUseForCountry')
                     ->with('IN')
                     ->andReturn(false);

        $this->expectException(Magento\Framework\Exception\LocalizedException::class);

        $this->paymentMethodModel->validate();
    }

    public function testSetAndGetRzpApiInstance()
    {
        $response = $this->paymentMethodModel->setAndGetRzpApiInstance();

        $this->assertInstanceOf(Razorpay\Api\Api::class, $response);
    }

    public function testGetChannel()
    {
        $response = $this->paymentMethodModel->getChannel();

        $this->assertSame('Magento Community 2.4.2-p2', $response);
    }

    public function testGetConfigDataWithKeyId()
    {
        $response = $this->paymentMethodModel->getConfigData('key_id');

        $this->assertSame('sample_key_id', $response);
    }

    public function testGetConfigDataWithRedirectURL()
    {
        $this->paymentMethodModel->shouldReceive('getOrderPlaceRedirectUrl')
                                 ->andReturn('');

        $response = $this->paymentMethodModel->getConfigData('order_place_redirect_url');

        $this->assertSame('', $response);
    }

    public function testGetPostData()
    {
        $postdata = [];

        $this->paymentMethodModel->shouldReceive('fileGetContents')
                                 ->andReturn($postdata);

        $response = $this->paymentMethodModel->getPostData();

        $this->assertSame($postdata, $response);
    }

    public function testCapture()
    {
        $paymentInfoInterface = $this->createMock(
            Magento\Payment\Model\InfoInterface::class
        );

        $response = $this->paymentMethodModel->capture($paymentInfoInterface, 1000);

        $this->assertSame($this->paymentMethodModel, $response);
    }

    public function testRefund()
    {
        $paymentInfoInterface = \Mockery::mock(
            Magento\Payment\Model\InfoInterface::class
        );

        $refundId  = 'pay_K6Ewbc4tbvw6jB-refund';
        $paymentId = substr($refundId, 0, -7);

        $this->paymentApi->shouldReceive('fetch')
                         ->with($paymentId)
                         ->andReturn($this->paymentApi);
        $this->paymentApi->shouldReceive('refund')
                         ->andReturn((object)['id'=>$refundId]);

        $this->config->shouldReceive('getMerchantNameOverride')
                     ->andReturn('My Ecommerce Site');

        $this->logger->shouldReceive('info');

        $this->trackPluginInstrumentation->shouldReceive('rzpTrackSegment')
                                         ->andReturn(['status' => 'success']);
        $this->trackPluginInstrumentation->shouldReceive('rzpTrackDataLake')
                                         ->andReturn(['status' => 'success']);

        $this->orderModel->shouldReceive('getIncrementId')
                         ->andReturn('000000001');

        $paymentInfoInterface->shouldReceive('getOrder')
                             ->andReturn($this->orderModel);
        $paymentInfoInterface->shouldReceive('getTransactionId')
                             ->andReturn($refundId);

        $paymentInfoInterface->shouldReceive('setAmountPaid')
                             ->with(1000)
                             ->andReturn($paymentInfoInterface);
        $paymentInfoInterface->shouldReceive('setLastTransId')
                             ->with($refundId)
                             ->andReturn($paymentInfoInterface);
        $paymentInfoInterface->shouldReceive('setTransactionId')
                             ->with($refundId)
                             ->andReturn($paymentInfoInterface);
        $paymentInfoInterface->shouldReceive('setIsTransactionClosed')
                             ->with(true)
                             ->andReturn($paymentInfoInterface);
        $paymentInfoInterface->shouldReceive('setShouldCloseParentTransaction')
                             ->with(true)
                             ->andReturn($paymentInfoInterface);

        $this->requestInterface->shouldReceive('getPost');

        $this->paymentMethodModel->shouldReceive('setAndGetRzpApiInstance')->andReturn($this->api);

        $response = $this->paymentMethodModel->refund($paymentInfoInterface, 1000);

        $this->assertSame($this->paymentMethodModel, $response);
    }

    public function testRefundApiException()
    {
        $paymentInfoInterface = \Mockery::mock(
            Magento\Payment\Model\InfoInterface::class
        );

        $refundId  = 'pay_K6Ewbc4tbvw6jB-refund';
        $paymentId = substr($refundId, 0, -7);

        $this->paymentApi->shouldReceive('fetch')
                         ->with($paymentId)
                         ->andReturn($this->paymentApi);
        $this->paymentApi->shouldReceive('refund')
                         ->andThrow($this->apiError);

        $this->config->shouldReceive('getMerchantNameOverride')
                     ->andReturn('My Ecommerce Site');

        $this->logger->shouldReceive('info');
        $this->logger->shouldReceive('critical');

        $this->trackPluginInstrumentation->shouldReceive('rzpTrackSegment')
                                         ->andReturn(['status' => 'success']);
        $this->trackPluginInstrumentation->shouldReceive('rzpTrackDataLake')
                                         ->andReturn(['status' => 'success']);

        $this->orderModel->shouldReceive('getIncrementId')
                         ->andReturn('000000001');

        $paymentInfoInterface->shouldReceive('getOrder')
                             ->andReturn($this->orderModel);
        $paymentInfoInterface->shouldReceive('getTransactionId')
                             ->andReturn($refundId);

        $paymentInfoInterface->shouldReceive('setAmountPaid')
                             ->with(1000)
                             ->andReturn($paymentInfoInterface);
        $paymentInfoInterface->shouldReceive('setLastTransId')
                             ->with($refundId)
                             ->andReturn($paymentInfoInterface);
        $paymentInfoInterface->shouldReceive('setTransactionId')
                             ->with($refundId)->andReturn($paymentInfoInterface);
        $paymentInfoInterface->shouldReceive('setIsTransactionClosed')
                             ->with(true)
                             ->andReturn($paymentInfoInterface);
        $paymentInfoInterface->shouldReceive('setShouldCloseParentTransaction')
                             ->with(true)
                             ->andReturn($paymentInfoInterface);

        $this->requestInterface->shouldReceive('getPost');

        $this->paymentMethodModel->shouldReceive('setAndGetRzpApiInstance')
                                 ->andReturn($this->api);

        $this->expectException(Magento\Framework\Exception\LocalizedException::class);

        $this->paymentMethodModel->refund($paymentInfoInterface, 1000);
    }

    public function testRefundErrorException()
    {
        $paymentInfoInterface = \Mockery::mock(
            Magento\Payment\Model\InfoInterface::class
        );

        $refundId  = 'pay_K6Ewbc4tbvw6jB-refund';
        $paymentId = substr($refundId, 0, -7);

        $this->paymentApi->shouldReceive('fetch')
                         ->with($paymentId)
                         ->andReturn($this->paymentApi);
        $this->paymentApi->shouldReceive('refund')
                         ->andReturn(['id'=>$refundId]);

        $this->config->shouldReceive('getMerchantNameOverride')
                     ->andReturn('My Ecommerce Site');

        $this->logger->shouldReceive('info');
        $this->logger->shouldReceive('critical');

        $this->trackPluginInstrumentation->shouldReceive('rzpTrackSegment')
                                         ->andReturn(['status' => 'success']);
        $this->trackPluginInstrumentation->shouldReceive('rzpTrackDataLake')
                                         ->andReturn(['status' => 'success']);

        $this->orderModel->shouldReceive('getIncrementId')
                         ->andThrow($this->exceptionError);

        $paymentInfoInterface->shouldReceive('getOrder')
                             ->andReturn($this->orderModel);

        $paymentInfoInterface->shouldReceive('getTransactionId')->andReturn($refundId);

        $paymentInfoInterface->shouldReceive('setAmountPaid')
                             ->with(1000)
                             ->andReturn($paymentInfoInterface);
        $paymentInfoInterface->shouldReceive('setLastTransId')
                             ->with($refundId)
                             ->andReturn($paymentInfoInterface);
        $paymentInfoInterface->shouldReceive('setTransactionId')
                             ->with($refundId)
                             ->andReturn($paymentInfoInterface);
        $paymentInfoInterface->shouldReceive('setIsTransactionClosed')
                             ->with(true)
                             ->andReturn($paymentInfoInterface);
        $paymentInfoInterface->shouldReceive('setShouldCloseParentTransaction')
                             ->with(true)
                             ->andReturn($paymentInfoInterface);

        $this->requestInterface->shouldReceive('getPost');

        $this->paymentMethodModel->shouldReceive('setAndGetRzpApiInstance')
                                 ->andReturn($this->api);

        $this->expectException(Magento\Framework\Exception\LocalizedException::class);    

        $this->paymentMethodModel->refund($paymentInfoInterface, 1000);
    }
}
