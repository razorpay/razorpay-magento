<?php

namespace Razorpay\Magento\Model;

use Razorpay\Api\Api;
use Magento\Framework\Module\ModuleListInterface;
use Razorpay\Api\Errors;
use Razorpay\Magento\Model\PaymentMethod;
use Razorpay\Magento\Model\Config;

use function PHPSTORM_META\type;
use Requests;

// Include Requests only if not already defined
if (class_exists('WpOrg\Requests\Autoload') === false)
{
    require_once __DIR__.'/../../Razorpay/Razorpay.php';
}

try
{
    \WpOrg\Requests\Autoload::register();

    if (version_compare(Requests::VERSION, '1.6.0') === -1)
    {
        throw new Exception('Requests class found but did not match');
    }
}
catch (\Exception $e)
{
    throw new Exception('Requests class found but did not match');
}


class TrackPluginInstrumentation
{
    const MODULE_NAME = 'Razorpay_Magento';

    protected $api;

    protected $mode;

    protected $moduleList;

    public function __construct(
        \Razorpay\Magento\Model\Config $config,
        ModuleListInterface $moduleList,
        \Psr\Log\LoggerInterface $logger
    )
    {
        $this->config       = $config;
        $this->api          = $this->setAndGetRzpApiInstance();
        $this->moduleList   = $moduleList;
        $this->logger       = $logger;
    }

    public function setAndGetRzpApiInstance()
    {
        $keyId          = $this->config->getConfigData(Config::KEY_PUBLIC_KEY);
        $keySecret      = $this->config->getConfigData(Config::KEY_PRIVATE_KEY);
        $apiInstance    = new Api($keyId, $keySecret);

        return $apiInstance;
    }

    public function rzpTrackSegment($event, $properties)
    {
        try
        {
            if (empty($event) === true or is_string($event) === false)
            {
                throw new \Exception("Empty field passed for event name payload to Segment");
            }
            if (empty($properties) === true)
            {
                throw new \Exception("Empty field passed for event properties payload to Segment");
            }

            if (is_string($properties))
            {
                $properties = json_decode($properties);
            }

            $defaultProperties = $this->getDefaultProperties();

            $properties = array_merge($properties, $defaultProperties);

            $data = [
                'event'         => $event,
                'properties'    => $properties
            ];

            $this->logger->info('Event: '. $event .'. Properties: '. json_encode($properties));

            $response = $this->api->request->request('POST', 'plugins/segment', $data);

            return $response;
        }
        catch (\Razorpay\Api\Errors\Error $e)
        {
            $this->logger->critical("Error:" . $e->getMessage());
            $response = ['status' => 'error', 'message' => $e->getMessage()];
            return $response;
        }
        catch (\Exception $e)
        {
            $this->logger->critical("Error:" . $e->getMessage());
            $response = ['status' => 'error', 'message' => $e->getMessage()];
            return $response;
        }
    }

    public function rzpTrackDataLake($event, $properties)
    {
        try
        {
            if (empty($event) === true or is_string($event) === false)
            {
                throw new \Exception("Empty field passed for event name payload to Datalake");
            }
            if (empty($properties) === true)
            {
                throw new \Exception("Empty field passed for event properties payload to Datalake");
            }

            if (is_string($properties))
            {
                $properties = json_decode($properties);
            }

            $defaultProperties = $this->getDefaultProperties();

            $mode = $defaultProperties['mode'];
            unset($defaultProperties['mode']);

            $properties = array_merge($properties, $defaultProperties);

            $headers = [
                'Content-Type'  => 'application/json'
            ];

            $data = json_encode(
                [
                    'mode'   => $mode,
                    'key'    => '0c08FC07b3eF5C47Fc19B6544afF4A98',
                    'events' => [
                        [
                            'event_type'    => 'plugin-events',
                            'event_version' => 'v1',
                            'timestamp'     => time(),
                            'event'         => str_replace(' ', '.', $event),
                            'properties'    => $properties
                        ]
                    ]
                ]
            );

            $options = [
                'timeout'   => 45
            ];

            $request = Requests::post("https://lumberjack.razorpay.com/v1/track", $headers, $data, $options);

            return ['status' => 'success'];
        }
        catch (\Razorpay\Api\Errors\Error $e)
        {
            $this->logger->critical("Error:" . $e->getMessage());
            $response = ['status' => 'error', 'message' => $e->getMessage()];
            return $response;
        }
        catch (\Exception $e)
        {
            $this->logger->critical("Error:" . $e->getMessage());
            $response = ['status' => 'error', 'message' => $e->getMessage()];
            return $response;
        }
    }

    public function getDefaultProperties()
    {
        $keyId              = $this->config->getConfigData(Config::KEY_PUBLIC_KEY);
        $this->mode         = (substr($keyId, 0, 8) === 'rzp_live') ? 'live' : 'test';

        $objectManager      = \Magento\Framework\App\ObjectManager::getInstance();
        $productMetadata    = $objectManager->get('Magento\Framework\App\ProductMetadataInterface');
        $magentoVersion     = $productMetadata->getVersion();

        $razorpayPluginVersion = $this->moduleList->getOne(self::MODULE_NAME)['setup_version'];

        $defaultProperties = [];

        $defaultProperties['platform']          = 'Magento';
        $defaultProperties['platform_version']  = $magentoVersion;
        $defaultProperties['plugin']            = 'Razorpay';
        $defaultProperties['plugin_version']    = $razorpayPluginVersion;
        $defaultProperties['mode']              = $this->mode;
        if(isset($_SERVER['HTTP_HOST']))
        {
            $defaultProperties['ip_address']    = $_SERVER['HTTP_HOST'];
        }
        else
        {
            $defaultProperties['ip_address']    = "";
        }

        return $defaultProperties;
    }
}

?>
