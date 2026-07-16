<?php

namespace Razorpay\Magento\Test\Unit\Controller\Payment;

use Magento\Catalog\Model\Session as CatalogSession;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Directory\Model\Country;
use Magento\Directory\Model\CountryFactory;
use Magento\Framework\App\Action\Context;
use Magento\Sales\Model\Order as SalesOrder;
use Magento\Sales\Model\Order\Address as OrderAddress;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Razorpay\Magento\Controller\Payment\Order;
use Razorpay\Magento\Model\CheckoutFactory;
use Razorpay\Magento\Model\Config;
use Razorpay\Magento\Model\TrackPluginInstrumentation;

/**
 * Order::execute() itself requires a fully bootstrapped checkout session with
 * a real "last real order" and reads $_SERVER directly, so these tests focus
 * on the two things that carry real financial risk in this controller: the
 * paise-conversion/line-item money math (private, invoked via reflection)
 * and the "payment already made" guard at the top of execute().
 */
class OrderTest extends TestCase
{
    private CheckoutFactory $checkoutFactory;
    private Config $config;
    private CatalogSession $catalogSession;
    private StoreManagerInterface $storeManager;
    private LoggerInterface $logger;
    private TrackPluginInstrumentation $trackPluginInstrumentation;
    private CountryFactory $countryFactory;

    protected function setUp(): void
    {
        $this->checkoutFactory = $this->createMock(CheckoutFactory::class);
        $this->config = $this->createMock(Config::class);
        $this->catalogSession = $this->createMock(CatalogSession::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->trackPluginInstrumentation = $this->createMock(TrackPluginInstrumentation::class);
        $this->countryFactory = $this->createMock(CountryFactory::class);

        $store = $this->getMockBuilder(Store::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBaseUrl'])
            ->getMock();
        $store->method('getBaseUrl')->willReturn('https://example.com/');

        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willReturn($store);
    }

    private function buildController(): Order
    {
        $customerSession = $this->createMock(CustomerSession::class);
        $checkoutSession = $this->createMock(CheckoutSession::class);

        $context = $this->createMock(Context::class);
        $context->method('getObjectManager')->willReturn(
            $this->createMock(\Magento\Framework\ObjectManagerInterface::class)
        );
        $context->method('getRequest')->willReturn($this->createMock(\Magento\Framework\App\RequestInterface::class));
        $context->method('getResponse')->willReturn($this->createMock(\Magento\Framework\App\ResponseInterface::class));
        $context->method('getMessageManager')->willReturn($this->createMock(\Magento\Framework\Message\ManagerInterface::class));
        $context->method('getRedirect')->willReturn($this->createMock(\Magento\Framework\App\Response\RedirectInterface::class));
        $context->method('getActionFlag')->willReturn($this->createMock(\Magento\Framework\App\ActionFlag::class));
        $context->method('getUrl')->willReturn($this->createMock(\Magento\Framework\UrlInterface::class));
        $context->method('getView')->willReturn($this->createMock(\Magento\Framework\App\ViewInterface::class));
        $context->method('getEventManager')->willReturn($this->createMock(\Magento\Framework\Event\ManagerInterface::class));
        $context->method('getResultRedirectFactory')->willReturn(
            $this->createMock(\Magento\Framework\Controller\Result\RedirectFactory::class)
        );
        $context->method('getResultFactory')->willReturn($this->createMock(\Magento\Framework\Controller\ResultFactory::class));

        return new Order(
            $context,
            $customerSession,
            $checkoutSession,
            $this->checkoutFactory,
            $this->config,
            $this->catalogSession,
            $this->storeManager,
            $this->logger,
            $this->trackPluginInstrumentation,
            $this->countryFactory
        );
    }

    private function invokePrivate(Order $controller, string $method, array $args)
    {
        $reflection = new \ReflectionMethod(Order::class, $method);
        return $reflection->invokeArgs($controller, $args);
    }

    public function testToPaiseWhenGivenDecimalAmountThenRoundsToNearestPaisa(): void
    {
        $controller = $this->buildController();

        $this->assertSame(50000, $this->invokePrivate($controller, 'toPaise', [500.00]));
        $this->assertSame(50050, $this->invokePrivate($controller, 'toPaise', [500.499]));
        $this->assertSame(50050, $this->invokePrivate($controller, 'toPaise', [500.495]));
        $this->assertSame(0, $this->invokePrivate($controller, 'toPaise', [null]));
    }

    public function testBuildLineItemsWhenDiscountDoesNotDivideEvenlyThenPerItemDiscountIsFlooredNotRounded(): void
    {
        // qty=3, discount=100.00 (10000 paise) -> 3333 paise per item, floor().
        // The 1 paise remainder is intentionally absorbed elsewhere, not lost
        // silently -- this test locks in that floor (not round/ceil) behavior.
        $product = $this->createMock(\Magento\Catalog\Model\Product::class);
        $product->method('getImage')->willReturn(false);

        $item = $this->createMock(OrderItem::class);
        $item->method('getProduct')->willReturn($product);
        $item->method('getSku')->willReturn('SKU-1');
        $item->method('getProductId')->willReturn(42);
        $item->method('getPrice')->willReturn(1000.00);
        $item->method('getQtyOrdered')->willReturn(3.0);
        $item->method('getDiscountAmount')->willReturn(100.00);
        $item->method('getTaxAmount')->willReturn(30.00);
        $item->method('getName')->willReturn('Test Product');

        $order = $this->createMock(SalesOrder::class);
        $order->method('getAllVisibleItems')->willReturn([$item]);

        $controller = $this->buildController();
        $lineItems = $this->invokePrivate($controller, 'buildLineItems', [$order]);

        $this->assertCount(1, $lineItems);
        $this->assertSame(100000, $lineItems[0]['price']);
        $this->assertSame(100000 - 3333, $lineItems[0]['offer_price']);
        $this->assertSame(1000, $lineItems[0]['tax_amount']);
    }

    public function testBuildLineItemsWhenQuantityIsZeroThenDiscountAndTaxAreZeroNotDivisionByZero(): void
    {
        $item = $this->createMock(OrderItem::class);
        $item->method('getProduct')->willReturn(null);
        $item->method('getSku')->willReturn('SKU-2');
        $item->method('getProductId')->willReturn(43);
        $item->method('getPrice')->willReturn(500.00);
        $item->method('getQtyOrdered')->willReturn(0.0);
        $item->method('getDiscountAmount')->willReturn(50.00);
        $item->method('getTaxAmount')->willReturn(10.00);
        $item->method('getName')->willReturn('Zero Qty Product');

        $order = $this->createMock(SalesOrder::class);
        $order->method('getAllVisibleItems')->willReturn([$item]);

        $controller = $this->buildController();
        $lineItems = $this->invokePrivate($controller, 'buildLineItems', [$order]);

        $this->assertSame(0, $lineItems[0]['tax_amount']);
        $this->assertSame(50000, $lineItems[0]['offer_price']);
    }

    public function testBuildLineItemsWhenNameExceedsMaxLengthThenNameIsTruncated(): void
    {
        $longName = str_repeat('A', 200);

        $item = $this->createMock(OrderItem::class);
        $item->method('getProduct')->willReturn(null);
        $item->method('getSku')->willReturn('SKU-3');
        $item->method('getProductId')->willReturn(44);
        $item->method('getPrice')->willReturn(100.00);
        $item->method('getQtyOrdered')->willReturn(1.0);
        $item->method('getDiscountAmount')->willReturn(0.0);
        $item->method('getTaxAmount')->willReturn(0.0);
        $item->method('getName')->willReturn($longName);

        $order = $this->createMock(SalesOrder::class);
        $order->method('getAllVisibleItems')->willReturn([$item]);

        $controller = $this->buildController();
        $lineItems = $this->invokePrivate($controller, 'buildLineItems', [$order]);

        $this->assertSame(125, strlen($lineItems[0]['name']));
    }

    public function testBuildCustomerDetailsWhenCustomerNameEmptyThenFallsBackToBillingAddressName(): void
    {
        $billingAddress = $this->createMock(OrderAddress::class);
        $billingAddress->method('getName')->willReturn('Billing Name');
        $billingAddress->method('getTelephone')->willReturn('9999999999');
        $billingAddress->method('getCountryId')->willReturn('');

        $order = $this->createMock(SalesOrder::class);
        $order->method('getBillingAddress')->willReturn($billingAddress);
        $order->method('getShippingAddress')->willReturn(null);
        $order->method('getCustomerFirstname')->willReturn('');
        $order->method('getCustomerLastname')->willReturn('');
        $order->method('getCustomerEmail')->willReturn('test@example.com');
        $order->method('getCustomerId')->willReturn(null);

        $controller = $this->buildController();
        $details = $this->invokePrivate($controller, 'buildCustomerDetails', [$order]);

        $this->assertSame('Billing Name', $details['name']);
        $this->assertFalse($details['insights']['has_account']);
    }

    public function testBuildAddressWhenCountryCodeIso2ThenConvertedToIso3(): void
    {
        $country = $this->createMock(Country::class);
        $country->method('getData')->with('iso3_code')->willReturn('IND');
        $this->countryFactory->method('create')->willReturn($country);
        $country->method('loadByCode')->willReturnSelf();

        $address = $this->createMock(OrderAddress::class);
        $address->method('getStreetLine')->willReturn('123 Main St');
        $address->method('getCity')->willReturn('Bengaluru');
        $address->method('getRegion')->willReturn('Karnataka');
        $address->method('getPostcode')->willReturn('560001');
        $address->method('getCountryId')->willReturn('IN');
        $address->method('getName')->willReturn('Test Customer');
        $address->method('getTelephone')->willReturn('9999999999');

        $controller = $this->buildController();
        $result = $this->invokePrivate($controller, 'buildAddress', [$address]);

        $this->assertSame('IND', $result['country']);
    }

    public function testBuildAddressWhenAddressEmptyThenReturnsEmptyArray(): void
    {
        $controller = $this->buildController();
        $result = $this->invokePrivate($controller, 'buildAddress', [null]);

        $this->assertSame([], $result);
    }

    public function testExecuteWhenOrderAlreadyProcessedThenReturns400WithoutCreatingRazorpayOrder(): void
    {
        // If a customer double-submits or refreshes after payment already
        // started, the order's state will no longer be 'new' -- execute() must
        // short-circuit with a 400 instead of creating a second Razorpay order.
        $mazeOrder = $this->createMock(SalesOrder::class);
        $mazeOrder->method('getEntityId')->willReturn(999);
        $mazeOrder->method('getIncrementId')->willReturn('000000999');
        $mazeOrder->method('getGrandTotal')->willReturn(500.0);

        $checkoutSession = $this->createMock(CheckoutSession::class);
        $checkoutSession->method('getLastRealOrder')->willReturn($mazeOrder);

        $customerSession = $this->createMock(CustomerSession::class);

        $context = $this->createMock(Context::class);
        $objectManagerMock = $this->createMock(\Magento\Framework\ObjectManagerInterface::class);

        $orderModel = $this->createMock(SalesOrder::class);
        $orderModel->method('load')->willReturnSelf();
        $orderModel->method('getState')->willReturn('processing');
        $orderModel->expects($this->never())->method('setState');

        $productMetadata = $this->createMock(\Magento\Framework\App\ProductMetadataInterface::class);
        $productMetadata->method('getVersion')->willReturn('2.4.7');

        $moduleList = $this->createMock(\Magento\Framework\Module\ModuleList::class);
        $moduleList->method('getOne')->willReturn(['setup_version' => '4.2.2']);

        $objectManagerMock->method('get')->willReturnCallback(
            function ($class) use ($orderModel, $productMetadata, $moduleList) {
                if ($class === 'Magento\Sales\Model\Order') {
                    return $orderModel;
                }
                if ($class === 'Magento\Framework\App\ProductMetadataInterface') {
                    return $productMetadata;
                }
                if ($class === 'Magento\Framework\Module\ModuleList') {
                    return $moduleList;
                }
                return null;
            }
        );

        $context->method('getObjectManager')->willReturn($objectManagerMock);
        $request = $this->createMock(\Magento\Framework\App\Request\Http::class);
        $request->method('getContent')->willReturn('{}');
        $request->method('getServer')->willReturn('');
        $context->method('getRequest')->willReturn($request);
        $context->method('getResponse')->willReturn($this->createMock(\Magento\Framework\App\ResponseInterface::class));
        $context->method('getMessageManager')->willReturn($this->createMock(\Magento\Framework\Message\ManagerInterface::class));
        $context->method('getRedirect')->willReturn($this->createMock(\Magento\Framework\App\Response\RedirectInterface::class));
        $context->method('getActionFlag')->willReturn($this->createMock(\Magento\Framework\App\ActionFlag::class));
        $context->method('getUrl')->willReturn($this->createMock(\Magento\Framework\UrlInterface::class));
        $context->method('getView')->willReturn($this->createMock(\Magento\Framework\App\ViewInterface::class));
        $context->method('getEventManager')->willReturn($this->createMock(\Magento\Framework\Event\ManagerInterface::class));
        $context->method('getResultRedirectFactory')->willReturn(
            $this->createMock(\Magento\Framework\Controller\Result\RedirectFactory::class)
        );

        $resultJson = $this->getMockBuilder(\Magento\Framework\Controller\Result\Json::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setData', 'setHttpResponseCode'])
            ->getMock();
        $resultJson->method('setData')->willReturnSelf();
        $resultJson->method('setHttpResponseCode')->willReturnSelf();

        $resultFactory = $this->getMockBuilder(\Magento\Framework\Controller\ResultFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $resultFactory->method('create')->willReturn($resultJson);
        $context->method('getResultFactory')->willReturn($resultFactory);

        $this->config->method('getConfigData')->willReturn(null);

        $controller = new Order(
            $context,
            $customerSession,
            $checkoutSession,
            $this->checkoutFactory,
            $this->config,
            $this->catalogSession,
            $this->storeManager,
            $this->logger,
            $this->trackPluginInstrumentation,
            $this->countryFactory
        );

        $resultJson->expects($this->once())
            ->method('setData')
            ->with($this->callback(function (array $data) {
                return $data['message'] === 'Payment already made for order :000000999';
            }))
            ->willReturnSelf();
        $resultJson->expects($this->once())->method('setHttpResponseCode')->with(400)->willReturnSelf();

        $controller->execute();
    }
}
