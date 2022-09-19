<?php

namespace Razorpay\Magento\Controller\Payment;

use Razorpay\Api\Api;
use Razorpay\Magento\Model\PaymentMethod;
use Magento\Framework\Controller\ResultFactory;

class Order extends \Razorpay\Magento\Controller\BaseController
{
    protected $quote;

    protected $checkoutSession;

    protected $_currency = PaymentMethod::CURRENCY;

    protected $logger;
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
        \Psr\Log\LoggerInterface $logger
    ) {
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
                $this->logger->info("Can't enable/disable webhook on $domain or private ip($domain_ip).");
            }
            else if(($webhookTriggeredAt + (24*60*60)) < time())
            {
                try
                {
                    $webhookPresent = $this->getExistingWebhook();

                    $razorpayParams['enable_webhook']                    = $this->config->getConfigData('enable_webhook');
                    $razorpayParams['webhook_events']['value']           = explode (",", $this->config->getConfigData('webhook_events'));
                    $razorpayParams['supported_webhook_events']['value'] = explode (",", $this->config->getConfigData('supported_webhook_events'));

                    if(empty($this->config->getConfigData('webhook_secret')) === false)
                    {
                        $razorpayParams['webhook_secret']['value'] = $this->config->getConfigData('webhook_secret');

                        $this->logger->info("Razorpay Webhook with existing secret.");
                    }
                    else
                    {
                        $secret = $this->generatePassword();

                        $this->config->setConfigData('webhook_secret',$secret);

                        $razorpayParams['webhook_secret']['value'] = $secret;

                        $this->logger->info("Razorpay Webhook created new secret.");
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

                        $this->config->setConfigData('webhook_triggered_at', time());

                        $this->logger->info("Razorpay Webhook Updated by Admin.");
                    }
                    else
                    {
                        $webhook = $this->rzp->webhook->create([
                            "url" => $this->webhookUrl,
                            "events" => $events,
                            "secret" => $razorpayParams['webhook_secret']['value'],
                            "active" => true,
                        ]);

                        $this->config->setConfigData('webhook_triggered_at', time());

                        $this->logger->info("Razorpay Webhook Created by Admin");
                    }
                }
                catch(\Razorpay\Api\Errors\Error $e)
                {
                    $this->logger->info($e->getMessage());
                }
                catch(\Exception $e)
                {
                    $this->logger->info($e->getMessage());
                }
            }
        }

        $mazeOrder = $this->checkoutSession->getLastRealOrder();

        $amount = (int) (number_format($mazeOrder->getGrandTotal() * 100, 0, ".", ""));

        $receipt_id = $mazeOrder->getIncrementId();

        $payment_action = $this->config->getPaymentAction();

        $maze_version = $this->_objectManager->get('Magento\Framework\App\ProductMetadataInterface')->getVersion();
        $module_version =  $this->_objectManager->get('Magento\Framework\Module\ModuleList')->getOne('Razorpay_Magento')['setup_version'];


        //if already order from same session , let make it's to pending state
        $new_order_status = $this->config->getNewOrderStatus();

        $orderModel = $this->_objectManager->get('Magento\Sales\Model\Order')->load($mazeOrder->getEntityId());

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

        try
        {
            $this->logger->info("Razorpay Order: create order started with quoteID:" . $receipt_id
                                    ." and amount:".$amount);
            $order = $this->rzp->order->create([
                'amount' => $amount,
                'receipt' => $receipt_id,
                'currency' => $mazeOrder->getOrderCurrencyCode(),
                'payment_capture' => $payment_capture
            ]);

            $responseContent = [
                'message'   => 'Unable to create your order. Please contact support.',
                'parameters' => []
            ];

            if (null !== $order && !empty($order->id))
            {
                $this->logger->info("Razorpay Order: order created with rzp_order:" . $order->id);
                $is_hosted = false;
                $merchantPreferences    = $this->getMerchantPreferences();

                $responseContent = [
                    'success'           => true,
                    'rzp_order'         => $order->id,
                    'order_id'          => $receipt_id,
                    'amount'            => $order->amount,
                    'quote_currency'    => $mazeOrder->getOrderCurrencyCode(),
                    'quote_amount'      => number_format($mazeOrder->getGrandTotal(), 2, ".", ""),
                    'maze_version'      => $maze_version,
                    'module_version'    => $module_version,
                    'is_hosted'         => $merchantPreferences['is_hosted'],
                    'image'             => $merchantPreferences['image'],
                    'embedded_url'      => $merchantPreferences['embedded_url'],
                ];

                $code = 200;

                $this->catalogSession->setRazorpayOrderID($order->id);

                $orderModel->setRzpOrderId($order->id)
                   ->save();
            }
        }
        catch(\Razorpay\Api\Errors\Error $e)
        {
            $responseContent = [
                'message'   => $e->getMessage(),
                'parameters' => []
            ];
            $this->logger->critical("Razorpay Order: Error message:" . $e->getMessage());
        }
        catch(\Exception $e)
        {
            $responseContent = [
                'message'   => $e->getMessage(),
                'parameters' => []
            ];
            $this->logger->critical("Razorpay Order: Error message:" . $e->getMessage());
        }

        $response = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $response->setData($responseContent);
        $response->setHttpResponseCode($code);

        return $response;
    }

    public function getOrderID()
    {
        return $this->catalogSession->getRazorpayOrderID();
    }

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
            $this->logger->info($e->getMessage());
        }
        catch(\Exception $e)
        {
            $this->logger->info($e->getMessage());
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
        try
        {
            $api = new Api($this->config->getKeyId(),"");

            $response = $api->request->request("GET", "preferences");
        }
        catch (\Razorpay\Api\Errors\Error $e)
        {
            echo 'Magento Error : ' . $e->getMessage();
        }

        $preferences = [];

        $preferences['embedded_url'] = Api::getFullUrl("checkout/embedded");
        $preferences['is_hosted'] = false;
        $preferences['image'] = $response['options']['image'];

        if(isset($response['options']['redirect']) && $response['options']['redirect'] === true)
        {
            $preferences['is_hosted'] = true;
        }

        return $preferences;
    }

}
