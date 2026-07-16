<?php

namespace Razorpay\Magento\Test\Unit\Observer;

use Magento\Directory\Model\RegionFactory;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Payment\Model\Method\Logger;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Razorpay\Magento\Controller\Payment\Order as OrderController;
use Razorpay\Magento\Model\Config;
use Razorpay\Magento\Model\PaymentMethod;
use Razorpay\Magento\Model\TrackPluginInstrumentation;
use Razorpay\Magento\Observer\AfterConfigSaveObserver;
use Magento\Sales\Model\ResourceModel\Order\Payment\Transaction\CollectionFactory as TransactionCollectionFactory;

class AfterConfigSaveObserverTest extends TestCase
{
    private Config $config;
    private RequestInterface $request;
    private WriterInterface $configWriter;
    private LoggerInterface $logger;
    private TrackPluginInstrumentation $trackPluginInstrumentation;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->request = $this->createMock(RequestInterface::class);
        $this->configWriter = $this->createMock(WriterInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->trackPluginInstrumentation = $this->createMock(TrackPluginInstrumentation::class);

        $objectManagerMock = $this->createMock(\Magento\Framework\ObjectManagerInterface::class);
        $objectManagerMock->method('get')->willReturn(
            $this->createMock(\Magento\Directory\Helper\Data::class)
        );
        \Magento\Framework\App\ObjectManager::setInstance($objectManagerMock);
    }

    /**
     * @param string $baseUrl Determines the webhook host gethostbyname() will
     *        resolve -- "http://localhost/" reliably resolves to 127.0.0.1
     *        (a private/loopback IP), letting the private-IP guard branch be
     *        tested deterministically without a real DNS lookup or Razorpay
     *        API call for a public domain.
     */
    private function buildObserver(string $baseUrl, object $rzpApiStub): AfterConfigSaveObserver
    {
        $store = $this->getMockBuilder(Store::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBaseUrl'])
            ->getMock();
        $store->method('getBaseUrl')->willReturn($baseUrl);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $paymentMethod = $this->buildPaymentMethodReturning($rzpApiStub);

        return new AfterConfigSaveObserver(
            $this->config,
            $this->request,
            $this->configWriter,
            $storeManager,
            $paymentMethod,
            $this->trackPluginInstrumentation,
            $this->logger
        );
    }

    private function buildPaymentMethodReturning(object $rzpApiStub): PaymentMethod
    {
        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')
            ->willReturn($this->createMock(\Magento\Framework\Event\ManagerInterface::class));
        $context->method('getCacheManager')
            ->willReturn($this->createMock(\Magento\Framework\App\CacheInterface::class));
        $context->method('getAppState')
            ->willReturn($this->createMock(\Magento\Framework\App\State::class));
        $context->method('getActionValidator')
            ->willReturn($this->createMock(\Magento\Framework\Model\ActionValidator\RemoveAction::class));
        $context->method('getLogger')->willReturn($this->createMock(\Psr\Log\LoggerInterface::class));

        $paymentMethod = $this->getMockBuilder(PaymentMethod::class)
            ->setConstructorArgs([
                $context,
                $this->createMock(Registry::class),
                $this->createMock(ExtensionAttributesFactory::class),
                $this->createMock(AttributeValueFactory::class),
                $this->createMock(PaymentHelper::class),
                $this->createMock(ScopeConfigInterface::class),
                $this->createMock(Logger::class),
                $this->config,
                $this->createMock(HttpRequest::class),
                $this->createMock(TransactionCollectionFactory::class),
                $this->createMock(ProductMetadataInterface::class),
                $this->createMock(RegionFactory::class),
                $this->createMock(OrderRepositoryInterface::class),
                $this->createMock(OrderController::class),
                $this->trackPluginInstrumentation,
            ])
            ->onlyMethods(['setAndGetRzpApiInstance'])
            ->getMock();

        $paymentMethod->method('setAndGetRzpApiInstance')->willReturn($rzpApiStub);

        return $paymentMethod;
    }

    private function invokePrivate(AfterConfigSaveObserver $observer, string $method, array $args = [])
    {
        $reflection = new \ReflectionMethod(AfterConfigSaveObserver::class, $method);
        return $reflection->invokeArgs($observer, $args);
    }

    private function fieldsParams(array $overrides = []): array
    {
        return array_merge([
            'active' => ['value' => '1'],
            'key_id' => ['value' => 'rzp_test_key'],
            'key_secret' => ['value' => 'secret'],
            'merchant_name_override' => ['value' => 'My Store'],
        ], $overrides);
    }

    public function testExecuteWhenWebhookUrlResolvesToPrivateIpThenTracksFailureAndDoesNotCallApi(): void
    {
        // Webhook host resolving to a private/loopback IP (localhost, an
        // internal domain, etc.) must not be sent to Razorpay's webhook API --
        // this is a real security guard against SSRF-style misconfiguration.
        $this->request->method('getParam')->with('groups')->willReturn([
            'razorpay' => ['fields' => $this->fieldsParams()],
        ]);
        // enable_webhook must be truthy so isset() on that array key passes
        // and the private-IP guard branch is actually entered.
        $this->config->method('getConfigData')->willReturnCallback(function ($field) {
            return $field === 'enable_webhook' ? '1' : null;
        });

        $rzpApiStub = $this->getMockBuilder(\stdClass::class)->addMethods(['__toString'])->getMock();

        // saveConfigData() fires its own 'Save Config Clicked' tracking call
        // first, so this asserts the specific failure event was among the
        // calls made, rather than constraining every call to match it.
        $trackedEvents = [];
        $this->trackPluginInstrumentation->method('rzpTrackDataLake')
            ->willReturnCallback(function ($event) use (&$trackedEvents) {
                $trackedEvents[] = $event;
                return ['status' => 'success'];
            });
        $this->trackPluginInstrumentation->method('rzpTrackSegment')->willReturn(['status' => 'success']);

        $observer = $this->buildObserver('http://localhost/', $rzpApiStub);

        $observer->execute($this->createMock(Observer::class));

        $this->assertContains('razorpay.std.observer.afterconfigsave.failed', $trackedEvents);
    }

    public function testExecuteWhenEnableWebhookNotSetThenWebhookApiIsNeverCalled(): void
    {
        $this->request->method('getParam')->with('groups')->willReturn([
            'razorpay' => ['fields' => $this->fieldsParams()],
        ]);
        // enable_webhook config value is null/empty -- the isset() check on
        // $razorpayParams['enable_webhook'] still passes since the array key
        // is set to null, so this covers the "config key present but falsy" path.
        $this->config->method('getConfigData')->willReturnCallback(function ($field) {
            return $field === 'enable_webhook' ? '0' : null;
        });
        $this->trackPluginInstrumentation->method('rzpTrackSegment')->willReturn(['status' => 'success']);
        $this->trackPluginInstrumentation->method('rzpTrackDataLake')->willReturn(['status' => 'success']);

        $webhookEntity = $this->getMockBuilder(\stdClass::class)->addMethods(['create', 'edit', 'all'])->getMock();
        $webhookEntity->expects($this->never())->method('create');
        $webhookEntity->expects($this->never())->method('edit');

        $rzpApiStub = new class ($webhookEntity) {
            public $webhook;
            public function __construct($webhookEntity)
            {
                $this->webhook = $webhookEntity;
            }
        };

        $observer = $this->buildObserver('http://localhost/', $rzpApiStub);
        $observer->execute($this->createMock(Observer::class));
    }

    public function testSaveConfigDataWhenKeyIdAndKeySecretPresentThenExcludedFromTrackedPayload(): void
    {
        // key_id/key_secret must never be forwarded to the analytics payload --
        // this is the only place in the observer that filters sensitive
        // credentials before they leave the process.
        $this->trackPluginInstrumentation->expects($this->once())
            ->method('rzpTrackSegment')
            ->with('Save Config Clicked', $this->callback(function (array $eventData) {
                return !array_key_exists('key_id', $eventData['config_settings'] ?? [])
                    && !array_key_exists('key_secret', $eventData['config_settings'] ?? []);
            }))
            ->willReturn(['status' => 'success']);
        $this->trackPluginInstrumentation->method('rzpTrackDataLake')->willReturn(['status' => 'success']);

        $observer = $this->buildObserver('http://localhost/', new \stdClass());

        $observer->saveConfigData($this->fieldsParams());
    }

    public function testGeneratePasswordWhenCalledThenReturnsStringContainingAllCharacterClasses(): void
    {
        $observer = $this->buildObserver('http://localhost/', new \stdClass());

        $password = $this->invokePrivate($observer, 'generatePassword');

        $this->assertMatchesRegularExpression('/[0-9]/', $password);
        $this->assertMatchesRegularExpression('/[a-z]/', $password);
        $this->assertMatchesRegularExpression('/[A-Z]/', $password);
        $this->assertGreaterThanOrEqual(11, strlen($password));
    }
}
