<?php
namespace Razorpay\Magento\Controller\Payment;

use Razorpay\Magento\Model\Config;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Module\ModuleListInterface;
use Razorpay\Magento\Model\TrackPluginInstrumentation;
use Razorpay\Magento\Model\PaymentMethod;


/**
 * Using TrackPluginInstrumentation and sending events to Segment
 *
 * ...
 */
class FormDataAnalytics extends \Razorpay\Magento\Controller\BaseController
{
    protected $setup;

    protected PaymentMethod $paymentMethod;

    protected ModuleListInterface $moduleList;

    protected TrackPluginInstrumentation $trackPluginInstrumentation;

    /**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Razorpay\Magento\Model\Config $config
     * @param \Psr\Log\LoggerInterface $logger
     * @param Razorpay\Magento\Model\TrackPluginInstrumentation
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Razorpay\Magento\Model\PaymentMethod $paymentMethod,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Razorpay\Magento\Model\Config $config,
        ModuleListInterface $moduleList,
        \Psr\Log\LoggerInterface $logger
    ) {
        parent::__construct(
            $context,
            $customerSession,
            $checkoutSession,
            $config
        );
        
        $this->config                       = $config;
        $this->paymentMethod                = $paymentMethod;
        $this->checkoutSession              = $checkoutSession;
        $this->customerSession              = $customerSession;
        $this->moduleList                   = $moduleList;
        $this->logger                       = $logger;
    }

    public function execute()
    {
        try
        {
            // Api logic here
            $this->logger->info("FormDataAnalyticsController controller started");
            
            $requestData = $this->getPostData();
            $requestData = is_array($requestData) ? $requestData : array($requestData);
            
            // Get keyid/keysecret
            $keyId = $this->config->getConfigData(Config::KEY_PUBLIC_KEY);
            $keySecret = $this->config->getConfigData(Config::KEY_PRIVATE_KEY);

            // $this->logger->info(json_encode($this->getPostData()));

            $this->trackPluginInstrumentation = new TrackPluginInstrumentation($keyId, $keySecret, $this->moduleList, $this->logger);

            if (array_key_exists('event', $requestData))
            {
                $event = $requestData['event'];
            } 
            else
            {
                throw new \Exception("Empty field passed for event name payload to Segment");
            }
            
            if (array_key_exists('event', $requestData))
            {
                $properties = $requestData['properties'];
            } 
            else
            {
                throw new \Exception("Empty field passed for event properties payload to Segment");
            }                            

            $trackResponse = $this->trackPluginInstrumentation->rzpTrackSegment($event, $properties);

            $response = $this->resultFactory->create(ResultFactory::TYPE_JSON);
            $response->setData($trackResponse);
            $response->setHttpResponseCode(200);

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
    }

    /**
     * Get Webhook post data as an array
     *
     * @return Webhook post data as an array
     */
    protected function getPostData()
    {
        $request = $this->getRequest()->getPostValue(); 
        
        if (!isset($request) || empty($request))
        {
            $request = "{}";
        }
        
        return $request;
    }
}