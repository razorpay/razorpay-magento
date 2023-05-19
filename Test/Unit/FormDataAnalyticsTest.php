<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Magento\Framework\Controller\Result\Json;

class FormDataAnalyticsTest extends TestCase
{
    public function setup(): void
    {
        $this->context = \Mockery::mock(
            \Magento\Framework\App\Action\Context::class
        )->makePartial()->shouldAllowMockingProtectedMethods();

        $this->trackPluginInstrumentation = \Mockery::mock(
            \Razorpay\Magento\Model\TrackPluginInstrumentation::class
        )->makePartial()->shouldAllowMockingProtectedMethods();

        $this->customerSession = $this->createMock(
            \Magento\Customer\Model\Session::class
        );

        $this->checkoutSession = \Mockery::mock(
            \Magento\Checkout\Model\Session::class
        );

        $this->config = \Mockery::mock(
            \Razorpay\Magento\Model\Config::class
        );

        $this->logger = $this->createMock(
            \Psr\Log\LoggerInterface::class
        );

        $this->requestInterface = \Mockery::mock(
            \Magento\Framework\App\RequestInterface::class
        );
        
        $this->resultFactoryMock = \Mockery::mock(
            Magento\Framework\Controller\ResultFactory::class
        )->makePartial()->shouldAllowMockingProtectedMethods();

        $this->serializerJson = new \Magento\Framework\Serialize\Serializer\Json;

        $this->translateInline = \Mockery::mock(
            \Magento\Framework\Translate\InlineInterface::class
        );

        $this->json = new Json($this->translateInline, $this->serializerJson);

        $this->context->shouldReceive('getResultFactory')->andReturn($this->resultFactoryMock);
        
        $this->config->shouldReceive('getConfigData')->with('key_id')->andReturn('key_id');
        $this->config->shouldReceive('getConfigData')->with('key_secret')->andReturn('key_secret');
        
        $this->trackPluginInstrumentation->shouldReceive('rzpTrackSegment')->andReturn(['status' => 'success']);
        $this->trackPluginInstrumentation->shouldReceive('rzpTrackDataLake')->andReturn(['status' => 'success']);
        
        $this->resultFactoryMock->shouldReceive('create')->with('json')->andReturn($this->json);

        $this->formDataAnalytics = \Mockery::mock(Razorpay\Magento\Controller\Payment\FormDataAnalytics::class, 
                                                    [
                                                        $this->context,
                                                        $this->trackPluginInstrumentation,
                                                        $this->customerSession,
                                                        $this->checkoutSession,
                                                        $this->config,
                                                        $this->logger
                                                    ]
                                                )->makePartial()->shouldAllowMockingProtectedMethods();
        
        $this->event = 'Form Field Focused';
        
        $this->eventProperties = [
            'store_name'        => 'Magento',
            'focus'             => 'yes',
            'field_name'        => 'title',
            'field_type'        => 'text',
            'platform'          => 'Magento',
            'platform_version'  => '2.4.5-p1',
            'plugin'            => 'Razorpay',
            'plugin_version'    => '4.0.2',
            'mode'              => 'test',
            'ip_address'        => ''
        ];

        $this->eventData = [
            'event'         => $this->event,
            'properties'    => $this->eventProperties
        ];
    }

    function getProperty($object, $propertyName)
    {
        $reflection = new \ReflectionClass($object);
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);
        return $property->getValue($object);
    }

    public function testExecuteSuccess()
    {
        $this->formDataAnalytics->shouldReceive('getPostData')->andReturn($this->eventData);
        
        $expectedResponse = '{"segment":{"status":"success"},"datalake":{"status":"success"}}';
        $response = $this->formDataAnalytics->execute();
        
        $this->assertSame($expectedResponse, $this->getProperty($response, 'json'));
    }

    public function testExecuteEventException()
    {
        $this->formDataAnalytics->shouldReceive('getPostData')->andReturn([
            'properties' => $this->eventProperties
        ]);

        $this->formDataAnalytics->execute();
    }

    public function testExecuteEventPropertiesException()
    {
        $this->formDataAnalytics->shouldReceive('getPostData')->andReturn([
            'event' => $this->event
        ]);

        $this->formDataAnalytics->execute();
    }

    public function testExecuteApiException()
    {
        $this->apiError = \Mockery::mock(
            Razorpay\Api\Errors\Error::class,['Test Api error message', 0, 0]
        );
        $this->formDataAnalytics->shouldReceive('getPostData')->andThrow($this->apiError);

        $this->formDataAnalytics->execute();
    }

    public function testGetPostDataNotEmpty()
    {
        $this->formDataAnalytics->shouldReceive('getRequest')->andReturn($this->requestInterface);
        $this->requestInterface->shouldReceive('getPostValue')->andReturn($this->eventData);
        
        $this->assertSame($this->eventData, $this->formDataAnalytics->getPostData());
    }

    public function testGetPostDataEmpty()
    {
        $this->formDataAnalytics->shouldReceive('getRequest')->andReturn($this->requestInterface);
        $this->requestInterface->shouldReceive('getPostValue')->andReturn([]);
        
        $this->assertSame('{}', $this->formDataAnalytics->getPostData());
    }
}
