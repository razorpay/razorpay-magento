<?php

declare(strict_types=1);

include_once __DIR__ . '/../../../Razorpay/src/Errors/Error.php';

use PHPUnit\Framework\TestCase;
use Magento\Framework\Module\ModuleListInterface;

class TrackPluginInstrumentationModelTest extends TestCase
{
    public function setup():void
    {
        $this->config = \Mockery::mock(
            \Razorpay\Magento\Model\Config::class
        );

        $this->moduleList = \Mockery::mock(
            Magento\Framework\Module\ModuleListInterface::class
        );

        $this->logger = $this->createMock(
            \Psr\Log\LoggerInterface::class
        );

        $this->requestApi = \Mockery::mock(
            Razorpay\Api\Request::class
        );

        $this->apiError = \Mockery::mock(
            \Razorpay\Api\Errors\Error::class, ['Test Api error message', 0, 0]
        );

        $this->config->shouldReceive('getConfigData')->with('key_id')->andReturn('key_id');
        $this->config->shouldReceive('getConfigData')->with('key_secret')->andReturn('key_secret');

        $this->moduleList->shouldReceive('getOne')->andReturn([
            'setup_version' => '4.0.4'
        ]);

        $this->trackPluginInstrumentationModel = \Mockery::mock(Razorpay\Magento\Model\TrackPluginInstrumentation::class,
                                                [
                                                    $this->config,
                                                    $this->moduleList,
                                                    $this->logger
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
                case 'request':
                return $this->requestApi;
            }
        });

        $this->event = 'Form Field Focused';

        $this->eventProperties = [
            'store_name'        => 'Magento',
            'focus'             => 'yes',
            'field_name'        => 'title',
            'field_type'        => 'text',
        ];
    }

    public function testRzpTrackSegmentEmptyEvent()
    {
        $expected = [
            'status'    => 'error',
            'message'   => 'Empty field passed for event name payload to Segment'
        ];

        $response = $this->trackPluginInstrumentationModel->rzpTrackSegment('', $this->eventProperties);

        $this->assertSame($expected, $response);
    }

    public function testRzpTrackSegmentEmptyProperties()
    {
        $expected = [
            'status'    => 'error',
            'message'   => 'Empty field passed for event properties payload to Segment'
        ];

        $response = $this->trackPluginInstrumentationModel->rzpTrackSegment($this->event, []);

        $this->assertSame($expected, $response);
    }

    public function testRzpTrackSegmentSuccess()
    {
        $expected = [
            'status'    => 'success',
            'message'   => 'Pushed to segment'
        ];

        $this->requestApi->shouldReceive('request')->andReturn([
            'status'    => 'success',
            'message'   => 'Pushed to segment'
        ]);

        $this->trackPluginInstrumentationModel->shouldReceive('setAndGetRzpApiInstance')->andReturn($this->api);

        $response = $this->trackPluginInstrumentationModel->rzpTrackSegment($this->event, $this->eventProperties);

        $this->assertSame($expected, $response);
    }

    public function testRzpTrackSegmentRazorpayApiException()
    {
        $expected = [
            'status'    => 'error',
            'message'   => 'Test Api error message'
        ];

        $this->requestApi->shouldReceive('request')->andThrow($this->apiError);

        $this->trackPluginInstrumentationModel->shouldReceive('setAndGetRzpApiInstance')->andReturn($this->api);

        $response = $this->trackPluginInstrumentationModel->rzpTrackSegment($this->event, $this->eventProperties);

        $this->assertSame($expected, $response);
    }

    public function testRzpTrackDatalakeEmptyEvent()
    {
        $expected = [
            'status'    => 'error',
            'message'   => 'Empty field passed for event name payload to Datalake'
        ];

        $response = $this->trackPluginInstrumentationModel->rzpTrackDataLake('', $this->eventProperties);

        $this->assertSame($expected, $response);
    }

    public function testRzpTrackDatalakeEmptyProperties()
    {
        $expected = [
            'status'    => 'error',
            'message'   => 'Empty field passed for event properties payload to Datalake'
        ];

        $response = $this->trackPluginInstrumentationModel->rzpTrackDataLake($this->event, []);

        $this->assertSame($expected, $response);
    }

    public function testRzpTrackDatalakeSuccess()
    {
        $expected = [
            'status'    => 'success'
        ];

        $response = $this->trackPluginInstrumentationModel->rzpTrackDataLake($this->event, $this->eventProperties);

        $this->assertSame($expected, $response);
    }

    public function testGetDefaultPropertiesHTTPHostNotSet()
    {
        $expected = [
            'platform'          => 'Magento',
            'platform_version'  => null,
            'plugin'            => 'Razorpay',
            'plugin_version'    => '4.0.4',
            'mode'              => 'test',
            'ip_address'        => ''
        ];

        $response = $this->trackPluginInstrumentationModel->getDefaultProperties();

        $this->assertSame($expected, $response);
    }

    public function testGetDefaultPropertiesHTTPHostSet()
    {
        $_SERVER['HTTP_HOST'] = 'Test Referrer URL';

        $expected = [
            'platform'          => 'Magento',
            'platform_version'  => null,
            'plugin'            => 'Razorpay',
            'plugin_version'    => '4.0.4',
            'mode'              => 'test',
            'ip_address'        => 'Test Referrer URL'
        ];

        $response = $this->trackPluginInstrumentationModel->getDefaultProperties();

        $this->assertSame($expected, $response);
    }

    public function testSetAndGetRzpApiInstance()
    {
        $response = $this->trackPluginInstrumentationModel->setAndGetRzpApiInstance();
        
        $this->assertInstanceOf(Razorpay\Api\Api::class, $response);
    }
}
