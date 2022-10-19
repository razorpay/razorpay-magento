<?php

namespace Razorpay\Magento\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Payment;
use Razorpay\Magento\Model\PaymentMethod;
use Magento\Framework\Exception\LocalizedException;
use Razorpay\Magento\Model\TrackPluginInstrumentation;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\RequestInterface;
use Razorpay\Magento\Model\Config;


/**
 * Class AfterConfigSaveObserver
 * @package Razorpay\Magento\Observer
 */
class AfterConfigSaveObserver implements ObserverInterface
{
    /**
     * Store key
     */
    const STORE = 'store';


    private $request;
    private $configWriter;
    protected $trackPluginInstrumentation;

    /**
     * StatusAssignObserver constructor.
     *
     * @param OrderRepositoryInterface $orderRepository
     */
    public function __construct(
        \Razorpay\Magento\Model\Config $config,
        RequestInterface $request,
        WriterInterface $configWriter,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Razorpay\Magento\Model\PaymentMethod $paymentMethod,
        TrackPluginInstrumentation $trackPluginInstrumentation,
        \Psr\Log\LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->request = $request;
        $this->configWriter = $configWriter;
        $this->_storeManager = $storeManager;
        $this->logger          = $logger;

        $this->config = $config;

        $this->key_id = $this->config->getConfigData(Config::KEY_PUBLIC_KEY);
        $this->key_secret = $this->config->getConfigData(Config::KEY_PRIVATE_KEY);

        $this->paymentMethod = $paymentMethod;

        $this->rzp = $this->paymentMethod->setAndGetRzpApiInstance();

        $this->trackPluginInstrumentation = $trackPluginInstrumentation;

        $this->webhookUrl = $this->_storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB) . 'razorpay/payment/webhook';

        $this->webhookId = null;

        $this->active_events = [];

        $this->webhooks = (object)[];

        $this->webhooks->entity = 'collection';
        $this->webhooks->items  = [];
    }

    /**
     * {@inheritdoc}
     */
    public function execute(Observer $observer)
    {
        $razorpayParams = $this->request->getParam('groups')['razorpay']['fields'];

        $this->saveConfigData($razorpayParams);

        $razorpayParams['enable_webhook']                    = $this->config->getConfigData('enable_webhook');
        $razorpayParams['webhook_events']['value']           = explode (",", $this->config->getConfigData('webhook_events'));
        $razorpayParams['supported_webhook_events']['value'] = explode (",", $this->config->getConfigData('supported_webhook_events'));

        $domain = parse_url($this->webhookUrl, PHP_URL_HOST);

        $domain_ip = gethostbyname($domain);

        if(isset($razorpayParams['enable_webhook']) === true)
        {
            if (!filter_var($domain_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))
            {

                $this->logger->info("Can't enable/disable webhook on $domain or private ip($domain_ip).");
                return;
            }

            try
            {
                $webhookPresent = $this->getExistingWebhook();

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

        return;

    }

    /**
     * Create and track admin form data on Save Config click
     */
    public function saveConfigData($razorpayParams)
    {
        $storeName = "";

        $firstElement = array_values($razorpayParams)[0];
        if (empty($firstElement) === false and array_keys($firstElement)[0] === "value")
        {
            $razorpayParamsFormattedArray = array('config_settings' => array());
            foreach($razorpayParams as $key=>$value)
            {
                if ($key != "key_id" && $key != "key_secret")
                {
                    $razorpayParamsFormattedArray['config_settings'][$key] = empty(array_values($value)) === false?
                        array_values($value)[0] :
                        null;
                }
            }
            $storeName = $razorpayParamsFormattedArray['config_settings']['merchant_name_override'];
        }

        $metaData = array("store_name" => $storeName);

        $eventData = array_merge($razorpayParamsFormattedArray, $metaData);

        $this->logger->info("Event : Save Config Clicked. In function " . __METHOD__);

        $response['segment'] = $this->trackPluginInstrumentation->rzpTrackSegment('Save Config Clicked', ($eventData));

        $response['datalake'] = $this->trackPluginInstrumentation->rzpTrackDataLake('Save Config Clicked', ($eventData));

        $this->logger->info(json_encode($response));
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

    private function disableWebhook()
    {
        $this->config->setConfigData('enable_webhook', 0);

        try
        {
            $webhook = $this->rzp->webhook->edit([
                    "url" => $this->webhookUrl,
                    "active" => false,
                ], $this->webhookId);

            $this->logger->info("Razorpay Webhook Disabled by Admin.");
        }
        catch(\Razorpay\Api\Errors\Error $e)
        {
            $this->logger->info($e->getMessage());
        }
        catch(\Exception $e)
        {
            $this->logger->info($e->getMessage());
        }

        $this->logger->info("Webhook disabled.");
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

}
