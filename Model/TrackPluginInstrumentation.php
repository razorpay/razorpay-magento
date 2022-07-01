<?php

namespace Razorpay\Magento\Model;

use Razorpay\Api\Api;
use Magento\Framework\Module\ModuleListInterface;
use Razorpay\Api\Errors;
use Razorpay\Magento\Model\PaymentMethod;

use function PHPSTORM_META\type;

class TrackPluginInstrumentation 
{
    const MODULE_NAME = 'Razorpay_Magento';

    protected $api;

    private String $keyId;

    private String $keySecret;

    protected ModuleListInterface $moduleList;

    public function __construct(
        // PaymentMethod $paymentMethod,
        String $keyId, 
        String $keySecret,
        ModuleListInterface $moduleList,
        \Psr\Log\LoggerInterface $logger
    )
    {
        //$this->$paymentMethod = $paymentMethod; 
        $this->keyId        = $keyId;
        $this->keySecret    = $keySecret; 

        $this->api          = $this->setAndGetRzpApiInstance();
        $this->moduleList   = $moduleList;
        $this->logger       = $logger;
    }

    public function setAndGetRzpApiInstance()
    {
        $apiInstance = new Api($this->keyId, $this->keySecret);
        //$apiInstance->setHeader('User-Agent', 'Razorpay/'. $this->paymentMethod->getChannel());

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
            if(empty($properties) === true)
            {
                throw new \Exception("Empty field passed for event properties payload to Segment");
            }

            if (is_string($properties))
            {
                $properties = json_decode($properties);
            }

            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $productMetadata = $objectManager->get('Magento\Framework\App\ProductMetadataInterface');
            $magentoVersion = $productMetadata->getVersion();
    
            $razorpayPluginVersion = $this->moduleList->getOne(self::MODULE_NAME)['setup_version'];
            
            $versionProperties = array();

            $versionProperties['platform'] = 'Magento';
            $versionProperties['platform_version'] = $magentoVersion;
            $versionProperties['plugin'] = 'Razorpay';
            $versionProperties['plugin_version'] = $razorpayPluginVersion;

            $properties = array_merge($properties, $versionProperties);
            
            $data = [
                'event' => $event,
                'properties' => $properties
            ];

            $this->logger->info('Event: '. $event .'. Properties: '. json_encode($properties));

            $response = $this->api->request->request('POST', 'plugins/segment', $data);

            return $response;
        }
        catch (\Razorpay\Api\Errors\Error $e)
        {
            $this->logger->critical("Error:" . $e->getMessage());
            $response = ['status' => 'error', 'message' => $e->getMessage()];
        }
        catch (\Exception $e)
        {
            $this->logger->critical("Error:" . $e->getMessage());
            $response = ['status' => 'error', 'message' => $e->getMessage()];
        }
        return $response;
    }

    public function rzpTrackDataLake($properties)
    {
        try
        {

        }
        catch (\Razorpay\Api\Errors\Error $e)
        {
            $this->logger->info($e->getMessage());
        }
        catch (\Exception $e)
        {
            $this->logger->info($e->getMessage());
        }
    }
}

?>