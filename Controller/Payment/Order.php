<?php

namespace Razorpay\Magento\Controller\Payment;

use Razorpay\Api\Api;
use Razorpay\Magento\Model\PaymentMethod;
use Magento\Framework\Controller\ResultFactory;
use Magento\Store\Model\ScopeInterface;
use Razorpay\Magento\Model\TrackPluginInstrumentation;

class Order extends \Razorpay\Magento\Controller\BaseController
{
    protected $quote;

    protected $checkoutSession;

    protected $_currency = PaymentMethod::CURRENCY;

    protected $logger;

    /**
     * @var \Magento\Razorpay\Model\CheckoutFactory
     */
    protected $checkoutFactory;

    /**
     * @var \Magento\Catalog\Model\Session
     */
    protected $catalogSession;

    /**
     * @var \Razorpay\Magento\Model\Config
     */
    protected $config;

    protected $webhookId;

    protected $active_events;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;

    protected $webhookUrl;

    protected $webhooks;

    protected const THREE_DECIMAL_CURRENCIES = ["KWD", "OMR", "BHD"];
    private const MAX_DEVICE_ID_LENGTH       = 255;
    private const MAX_USER_AGENT_LENGTH      = 512;
    private const MAX_LINE_ITEM_NAME_LENGTH  = 125;

    protected $trackPluginInstrumentation;

    /**
     * @var \Magento\Directory\Model\CountryFactory
     */
    private $countryFactory;

    private $iso3Cache = [];

    /**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Razorpay\Model\CheckoutFactory $checkoutFactory
     * @param \Magento\Razorpay\Model\Config\Payment $razorpayConfig
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Razorpay\Magento\Model\CheckoutFactory $checkoutFactory,
        \Razorpay\Magento\Model\Config $config,
        \Magento\Catalog\Model\Session $catalogSession,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Psr\Log\LoggerInterface $logger,
        TrackPluginInstrumentation $trackPluginInstrumentation,
        \Magento\Directory\Model\CountryFactory $countryFactory
    )
    {
        parent::__construct(
            $context,
            $customerSession,
            $checkoutSession,
            $config
        );

        $this->checkoutFactory = $checkoutFactory;
        $this->catalogSession  = $catalogSession;
        $this->config          = $config;
        $this->logger          = $logger;
        $this->webhookId       = null;
        $this->active_events   = [];
        $this->_storeManager   = $storeManager;
        $this->webhookUrl      = $this->_storeManager
                                    ->getStore()
                                    ->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB) .
                                    'razorpay/payment/webhook';

        $this->webhooks = (object)[];

        $this->webhooks->entity = 'collection';
        $this->webhooks->items  = [];
        $this->trackPluginInstrumentation = $trackPluginInstrumentation;
        $this->countryFactory             = $countryFactory;
    }

    public function execute()
    {
        if(empty($this->config->getConfigData('webhook_triggered_at')) === false)
        {
            $webhookTriggeredAt = (int) $this->config->getConfigData('webhook_triggered_at');

            $domain    = parse_url($this->webhookUrl, PHP_URL_HOST);
            $domain_ip = gethostbyname($domain);

            if (!filter_var($domain_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))
            {
                // @codeCoverageIgnoreStart
                $this->logger->info("Can't enable/disable webhook on $domain or private ip($domain_ip).");

                $properties = [
                    'error_message' => "Can't enable/disable webhook on $domain or private ip($domain_ip).",
                    'file_path' => 'controller/Payment/Order.php',
                    'exception_type' => null,
                    'notes' => 'Webhook creation failed'
                ];

                $this->trackPluginInstrumentation->rzpTrackDataLake('razorpay.std.create.webhook.failed', $properties);
                // @codeCoverageIgnoreEnd
            }
            else if(($webhookTriggeredAt + (24*60*60)) < time())
            {
                try
                {
                    $webhookPresent = $this->getExistingWebhook();

                    $razorpayParams['enable_webhook']                    = $this->config->getConfigData('enable_webhook');
                    $razorpayParams['webhook_events']['value']           = explode (",", $this->config->getConfigData('webhook_events'));
                    $razorpayParams['supported_webhook_events']['value'] = explode (",", $this->config->getConfigData('supported_webhook_events'));

                    $websiteId = (int) $this->_storeManager->getStore()->getWebsiteId();

                    if(empty($this->config->getConfigData('webhook_secret')) === false)
                    {
                        $razorpayParams['webhook_secret']['value'] = $this->config->getConfigData('webhook_secret');

                        // @codeCoverageIgnoreStart
                        $this->logger->info("Razorpay Webhook with existing secret.");
                        // @codeCoverageIgnoreEnd
                    }
                    else
                    {
                        $secret = $this->generatePassword();

                        $this->config->setConfigData('webhook_secret', $secret, ScopeInterface::SCOPE_WEBSITES, $websiteId);

                        $razorpayParams['webhook_secret']['value'] = $secret;

                        // @codeCoverageIgnoreStart
                        $this->logger->info("Razorpay Webhook created new secret.");
                        // @codeCoverageIgnoreEnd
                    }

                    $events = [];

                    foreach($razorpayParams['webhook_events']['value'] as $event)
                    {
                        $events[$event] = true;
                    }

                    foreach($this->active_events as $event)
                    {
                        if(in_array($event, $razorpayParams['supported_webhook_events']['value']))
                        {
                            $events[$event] = true;
                        }
                    }

                    if(empty($this->webhookId) === false)
                    {
                        $webhook = $this->rzp->webhook->edit([
                            "url" => $this->webhookUrl,
                            "events" => $events,
                            "secret" => $razorpayParams['webhook_secret']['value'],
                            "active" => true,
                        ], $this->webhookId);

                        $this->config->setConfigData('webhook_triggered_at', time(), ScopeInterface::SCOPE_WEBSITES, $websiteId);

                        // @codeCoverageIgnoreStart
                        $this->logger->info("Razorpay Webhook Updated by Admin.");
                        // @codeCoverageIgnoreEnd
                    }
                    else
                    {
                        $webhook = $this->rzp->webhook->create([
                            "url" => $this->webhookUrl,
                            "events" => $events,
                            "secret" => $razorpayParams['webhook_secret']['value'],
                            "active" => true,
                        ]);

                        $this->config->setConfigData('webhook_triggered_at', time(), ScopeInterface::SCOPE_WEBSITES, $websiteId);
                        
                        // @codeCoverageIgnoreStart
                        $this->logger->info("Razorpay Webhook Created by Admin");
                        // @codeCoverageIgnoreEnd
                    }
                }
                catch(\Razorpay\Api\Errors\Error $e)
                {
                    // @codeCoverageIgnoreStart
                    $this->logger->info($e->getMessage());
                    // @codeCoverageIgnoreEnd

                    $properties = [
                        'error_message' => $e->getMessage(),
                        'file_path' => 'controller/Payment/Order.php',
                        'exception_type' => get_class($e),
                        'notes' => 'Webhook creation failed',
                    ];
                    $this->trackPluginInstrumentation->rzpTrackDataLake('razorpay.std.create.webhook.failed', $properties);
                }
                catch(\Exception $e)
                {
                    // @codeCoverageIgnoreStart
                    $this->logger->info($e->getMessage());
                    // @codeCoverageIgnoreEnd

                    $properties = [
                        'error_message' => $e->getMessage(),
                        'file_path' => 'controller/Payment/Order.php',
                        'exception_type' => get_class($e),
                        'notes' => 'Webhook creation failed',
                    ];
                    $this->trackPluginInstrumentation->rzpTrackDataLake('razorpay.std.create.webhook.failed', $properties);
                }
            }
        }

        $mazeOrder = $this->checkoutSession->getLastRealOrder();

        $amount = (int) (number_format($mazeOrder->getGrandTotal() * 100, 0, ".", ""));

        $receipt_id = $mazeOrder->getIncrementId();

        $requestBody = json_decode($this->getRequest()->getContent(), true);
        $rawDeviceId = isset($requestBody['device_id']) ? (string)$requestBody['device_id'] : '';
        // Format: {version}.{hex-hash}.{timestamp}.{random} — max 255 chars, safe chars only
        $deviceId = (strlen($rawDeviceId) <= self::MAX_DEVICE_ID_LENGTH && preg_match('/^[a-zA-Z0-9._-]+$/', $rawDeviceId))
            ? $rawDeviceId
            : '';
        $userAgent = substr((string) $this->getRequest()->getServer('HTTP_USER_AGENT', ''), 0, self::MAX_USER_AGENT_LENGTH);

        $clientIp = $this->getClientIp();

        $payment_action = $this->config->getPaymentAction();

        $maze_version = $this->_objectManager->get('Magento\Framework\App\ProductMetadataInterface')->getVersion();
        $module_version =  $this->_objectManager->get('Magento\Framework\Module\ModuleList')->getOne('Razorpay_Magento')['setup_version'];


        //if already order from same session , let make it's to pending state
        $new_order_status = $this->config->getNewOrderStatus();

        $orderModel = $this->_objectManager->get('Magento\Sales\Model\Order')->load($mazeOrder->getEntityId());
        
        if($orderModel->getState() !== 'new')
        {
            $responseContent = [
                'message'   => 'Payment already made for order :' . $receipt_id,
                'parameters' => [],
            ];

            $response = $this->resultFactory->create(ResultFactory::TYPE_JSON);
            $response->setData($responseContent);
            $response->setHttpResponseCode(400);

            $this->logger->critical("Razorpay Order: Payment already made for order :" . $receipt_id,);

            $properties = [
                'error_message' => 'Payment already made for order :' . $receipt_id ?? 'No receipt id found',
                'file_path' => 'controller/Payment/Order.php',
                'exception_type' => null,
                'notes' => 'Mage Order: ' . $receipt_id . ' order creation failed'
            ];
            $this->trackPluginInstrumentation->rzpTrackDataLake('razorpay.std.create.order.failed', $properties);

            return $response;
        }

        $orderModel->setState('new')
                   ->setStatus($new_order_status)
                   ->save();

        if ($payment_action === 'authorize') 
        {
                $payment_capture = 0;
        }
        else
        {
                $payment_capture = 1;
        }

        $code = 400;
        
        $rzpOrderId = null;

        $orderLink = $this->_objectManager->get('Razorpay\Magento\Model\OrderLink')
                        ->getCollection()
                        ->addFilter('order_id', $mazeOrder->getEntityId())
                        ->getFirstItem();

        $rzpOrderId = $orderLink->getRzpOrderId();

        try
        {
            // @codeCoverageIgnoreStart
            $this->logger->info("Razorpay Order: create order started with Magento OrderId:" . $receipt_id
                                    . " and amount:" . $amount);
            // @codeCoverageIgnoreEnd

            if (in_array($mazeOrder->getOrderCurrencyCode(), static::THREE_DECIMAL_CURRENCIES) === true)
            {
                $this->logger->info($mazeOrder->getOrderCurrencyCode() . " currency is not supported at the moment.");
                
                $responseContent = [
                    'message'   => 'Unable to create your order. Please contact support.',
                    'parameters' => []
                ];

                $code = 400;

                $response = $this->resultFactory->create(ResultFactory::TYPE_JSON);
                $response->setData($responseContent);
                $response->setHttpResponseCode($code);

                $properties = [
                    'error_message' => 'currency is not supported at the moment.',
                    'file_path' => 'controller/Payment/Order.php',
                    'exception_type' => null,
                    'notes' => 'Mage Order: order creation failed',
                ];

                $this->trackPluginInstrumentation->rzpTrackDataLake('razorpay.std.create.order.failed', $properties);

                return $response;
            }
            
            if ((isset($rzpOrderId) === false) and
                (empty($rzpOrderId) === true))
            {
                $orderPayload = [
                    'amount'            => $amount,
                    'receipt'           => $receipt_id,
                    'currency'          => $mazeOrder->getOrderCurrencyCode(),
                    'payment_capture'   => $payment_capture,
                    'line_items_total'  => $this->toPaise($mazeOrder->getSubtotal() + $mazeOrder->getDiscountAmount()),
                    'line_items'        => $this->buildLineItems($mazeOrder),
                    'shipping_fee'      => $this->toPaise($mazeOrder->getShippingInclTax()),
                    'customer_details'  => $this->buildCustomerDetails($mazeOrder),
                    'notes' => [
                        'referrer'          => (isset($_SERVER['HTTP_REFERER']) === true) ? $_SERVER['HTTP_REFERER'] : null,
                        'shield_device_id'  => $deviceId,
                        'shield_user_agent' => $userAgent,
                        'shield_client_ip'  => $clientIp,
                    ]
                ];
                
                $order = $this->rzp->order->create($orderPayload);

                if (null !== $order && !empty($order->id))
                {
                    $rzpOrderId = $order->id;

                    // @codeCoverageIgnoreStart
                    $this->logger->info("New Razorpay Order ($rzpOrderId) created for Magento OrderId:" . $receipt_id);
                    // @codeCoverageIgnoreEnd
                }
            }


            $responseContent = [
                'message'   => 'Unable to create your order. Please contact support.',
                'parameters' => []
            ];

            if ((isset($rzpOrderId) === true) and
                (empty($rzpOrderId) === false))
            {
                $is_hosted = false;
                $merchantPreferences    = $this->getMerchantPreferences();

                $responseContent = [
                    'success'           => true,
                    'rzp_order'         => $rzpOrderId,
                    'order_id'          => $receipt_id,
                    'amount'            => $amount,
                    'quote_currency'    => $mazeOrder->getOrderCurrencyCode(),
                    'quote_amount'      => number_format($mazeOrder->getGrandTotal(), 2, ".", ""),
                    'maze_version'      => $maze_version,
                    'module_version'    => $module_version,
                    'is_hosted'         => $merchantPreferences['is_hosted'],
                    'image'             => $merchantPreferences['image'],
                    'embedded_url'      => $merchantPreferences['embedded_url'],
                ];

                $code = 200;

                $this->catalogSession->setRazorpayOrderID($rzpOrderId);

                $quoteId = $mazeOrder->getQuoteId();
                $quote = $this->_objectManager->get('Magento\Quote\Model\Quote')->load($quoteId);

                $quote->setIsActive(false)->save();
            }
        }
        catch(\Razorpay\Api\Errors\Error $e)
        {
            $responseContent = [
                'message'   => $e->getMessage(),
                'parameters' => []
            ];

            $properties = [
                'error_message' => $e->getMessage(),
                'file_path' => 'controller/Payment/Order.php',
                'exception_type' => get_class($e),
                'notes' => 'Mage Order: order creation failed'
            ];

            $return_data = $this->trackPluginInstrumentation->rzpTrackDataLake('razorpay.std.create.order.failed', $properties);

            // @codeCoverageIgnoreStart
            $this->logger->critical("Razorpay Order: Mage Order($receipt_id), Error message from api:" . $e->getMessage());
            // @codeCoverageIgnoreEnd
        }
        catch(\Exception $e)
        {
            $responseContent = [
                'message'   => $e->getMessage(),
                'parameters' => []
            ];

            $properties = [
                'error_message' => $e->getMessage(),
                'file_path' => 'controller/Payment/Order.php',
                'exception_type' => get_class($e),
                'notes' => 'Mage Order: order creation failed'
            ];

            $this->trackPluginInstrumentation->rzpTrackDataLake('razorpay.std.create.order.failed', $properties);

            // @codeCoverageIgnoreStart
            $this->logger->critical("Razorpay Order: Mage Order($receipt_id), Error message:" . $e->getMessage());
            // @codeCoverageIgnoreEnd
        }

        $orderLink->setRzpOrderId($rzpOrderId)
                    ->setOrderId($mazeOrder->getEntityId())
                    ->setWebsiteId((int) $this->_storeManager->getStore()->getWebsiteId())
                    ->save();

        $this->logger->info("Data saved in razorpay_sales_order for Mage Order($receipt_id)");

        $response = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $response->setData($responseContent);
        $response->setHttpResponseCode($code);

        return $response;
    }
    // @codeCoverageIgnoreStart
    public function getOrderID()
    {
        return $this->catalogSession->getRazorpayOrderID();
    }
    // @codeCoverageIgnoreEnd
    /**
     * getExistingWebhook.
     *
     * @return return array
     */
    private function getExistingWebhook()
    {
        try
        {
            //fetch all the webhooks
            $webhooks = $this->getWebhooks();

            if(($webhooks->count) > 0 and (empty($this->webhookUrl) === false))
            {
                foreach ($webhooks->items as $key => $webhook)
                {
                    if($webhook->url === $this->webhookUrl)
                    {
                        $this->webhookId = $webhook->id;

                        foreach($webhook->events as $eventKey => $eventActive)
                        {
                            if($eventActive)
                            {
                                $this->active_events[] = $eventKey;
                            }
                        }
                        return ['id' => $webhook->id, 'active_events'=>$this->active_events];
                    }
                }
            }
        }
        catch(\Razorpay\Api\Errors\Error $e)
        {
            // @codeCoverageIgnoreStart
            $this->logger->info($e->getMessage());
            // @codeCoverageIgnoreEnd

            $properties = [
                'error_message' => $e->getMessage(),
                'file_path' => 'controller/Payment/Order.php',
                'exception_type' => get_class($e),
                'notes' => 'Webhook fetch failed'
            ];

            $this->trackPluginInstrumentation->rzpTrackDataLake('razorpay.std.get.webhook.failed', $properties);
        }
        catch(\Exception $e)
        {
            // @codeCoverageIgnoreStart
            $this->logger->info($e->getMessage());
            // @codeCoverageIgnoreEnd

            $properties = [
                'error_message' => $e->getMessage(),
                'file_path' => 'controller/Payment/Order.php',
                'exception_type' => get_class($e),
                'notes' => 'Webhook fetch failed'
            ];

            $this->trackPluginInstrumentation->rzpTrackDataLake('razorpay.std.get.webhook.failed', $properties);
        }

        return ['id' => null,'active_events'=>null];
    }

    function getWebhooks($count=10, $skip=0)
    {
        $webhooks = $this->rzp->webhook->all(['count' => $count, 'skip' => $skip]);

        if ($webhooks['count'] > 0)
        {
            $this->webhooks->items = array_merge($this->webhooks->items, $webhooks['items']);
            $this->webhooks->count = count($this->webhooks->items);

            $this->getWebhooks($count, $this->webhooks->count);
        }

        return $this->webhooks;
    }

    private function generatePassword()
    {
        $digits    = array_flip(range('0', '9'));
        $lowercase = array_flip(range('a', 'z'));
        $uppercase = array_flip(range('A', 'Z'));
        $special   = array_flip(str_split('!@#$%^&*()_+=-}{[}]\|;:<>?/'));
        $combined  = array_merge($digits, $lowercase, $uppercase, $special);

        return str_shuffle( array_rand($digits) .
                            array_rand($lowercase) .
                            array_rand($uppercase) .
                            array_rand($special) .
                            implode(
                                array_rand($combined, rand(8, 12))
                            )
                        );
    }

    protected function getMerchantPreferences()
    {
        $preferences = [];

        $preferences['embedded_url'] = Api::getFullUrl("checkout/embedded");
        $preferences['is_hosted'] = false;
        $preferences['image'] = '';
        
        try
        {
            $api = $this->getPublicRazorpayApiInstance();
            $response = $api->request->request("GET", "preferences");
            
            $preferences['image'] = $response['options']['image'];

            if(isset($response['options']['redirect']) and 
                $response['options']['redirect'] === true)
            {
                $preferences['is_hosted'] = true;
            }
        }
        catch (\Razorpay\Api\Errors\Error $e)
        {
            // @codeCoverageIgnoreStart
            $this->logger->critical('Razorpay Order: Magento Error : ' . $e->getMessage());
            // @codeCoverageIgnoreEnd

            $properties = [
                'error_message' => $e->getMessage(),
                'file_path' => 'controller/Payment/Order.php',
                'exception_type' => get_class($e),
                'notes' => 'Merchant preferences fetch failed'
            ];

            $this->trackPluginInstrumentation->rzpTrackDataLake('razorpay.std.get.preferences.failed', $properties);
        }

        return $preferences;
    }
    // @codeCoverageIgnoreStart
    function getPublicRazorpayApiInstance()
    {
       return new Api($this->config->getKeyId(), "");
    }
    // @codeCoverageIgnoreEnd

    private function toPaise($amount)
    {
        return (int) round((float) ($amount ?? 0) * 100);
    }

    private function buildLineItems($order)
    {
        $lineItems = [];

        $mediaUrl = $this->_storeManager->getStore()
                        ->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA);

        foreach ($order->getAllVisibleItems() as $item)
        {
            $product    = $item->getProduct();
            $imageUrl   = '';
            $productUrl = '';

            if ($product)
            {
                $imageUrl = $product->getImage() ? $mediaUrl . 'catalog/product' . $product->getImage() : '';
                try
                {
                    $productUrl = $product->getProductUrl();
                }
                catch (\Exception $e)
                {
                    $productUrl = '';
                }
            }

            $price      = $this->toPaise($item->getPrice());
            $qty        = (int)($item->getQtyOrdered() ?? 0);
            // intdiv intentionally floors per-item discount; remainder (≤ qty-1 paise) is absorbed into line_items_total
            $discount   = ($qty > 0 && $item->getDiscountAmount()) ? intdiv($this->toPaise((float)$item->getDiscountAmount()), $qty) : 0;
            $offerPrice = max(0, $price - $discount);
            $name       = substr((string)$item->getName(), 0, self::MAX_LINE_ITEM_NAME_LENGTH);

            $lineItems[] = [
                'type'        => 'e-commerce',
                'sku'         => (string)$item->getSku(),
                'variant_id'  => (string)$item->getProductId(),
                'price'       => $price,
                'offer_price' => $offerPrice,
                'tax_amount'  => ($qty > 0) ? intdiv($this->toPaise((float)$item->getTaxAmount()), $qty) : 0,
                'quantity'    => $qty,
                'name'        => $name,
                'description' => $name,
                'image_url'   => $imageUrl,
                'product_url' => $productUrl,
            ];
        }

        return $lineItems;
    }

    private function buildCustomerDetails($order)
    {
        $billingAddress = $order->getBillingAddress();

        $name = trim((string)$order->getCustomerFirstname() . ' ' . (string)$order->getCustomerLastname());
        if (empty($name) && $billingAddress)
        {
            $name = trim((string)$billingAddress->getName());
        }
        return [
            'name'             => $name,
            'contact'          => $billingAddress ? (string)$billingAddress->getTelephone() : '',
            'email'            => (string)($order->getCustomerEmail() ?? ''),
            'insights'         => [
                'has_account'  => ($order->getCustomerId() !== null),
            ],
            'billing_address'  => $this->buildAddress($billingAddress),
            'shipping_address' => $this->buildAddress($order->getShippingAddress() ?: $billingAddress),
        ];
    }

    private function buildAddress($address)
    {
        if (empty($address))
        {
            return [];
        }

        $iso2 = (string)($address->getCountryId() ?? '');

        if (!isset($this->iso3Cache[$iso2]))
        {
            $iso3 = $iso2;
            if (!empty($iso2))
            {
                $countryModel = $this->countryFactory->create()->loadByCode($iso2);
                $iso3         = $countryModel->getData('iso3_code') ?: $iso2;
            }
            $this->iso3Cache[$iso2] = $iso3;
        }

        return [
            'line1'     => (string)$address->getStreetLine(1),
            'line2'     => (string)$address->getStreetLine(2),
            'city'      => (string)$address->getCity(),
            'state'     => (string)$address->getRegion(),
            'zipcode'   => (string)$address->getPostcode(),
            'country'   => $this->iso3Cache[$iso2],
            'name'      => (string)($address->getName() ?? ''),
            'contact'   => (string)($address->getTelephone() ?? ''),
            'latitude'  => null,
            'longitude' => null,
        ];
    }

    private function getClientIp()
    {
        $request = $this->getRequest();
        $ip = $request->getClientIp(true);

        // XFF can be "clientIp, proxy1, proxy2" — take only the first
        if (strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }
}
