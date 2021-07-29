<?php 

namespace Razorpay\Magento\Model\System\Message;

use Magento\Framework\Notification\MessageInterface;
use Razorpay\Magento\Model\Config;
use Requests;

// Include Requests only if not already defined
if (class_exists('Requests') === false)
{
  require_once __DIR__.'/../../../../Razorpay/libs/Requests-1.8.0/library/Requests.php';
}

try
{
    Requests::register_autoloader();

    if (version_compare(Requests::VERSION, '1.6.0') === -1)
    {
      throw new \Exception('Requests class found but did not match'.Requests::VERSION);
    }
}
catch (\Exception $e)
{
    throw new \Exception('Requests class found but did not match'.Requests::VERSION);
}

class UpgradeMessageNotification implements MessageInterface {

   /**
    * Message identity
    */
   const MESSAGE_IDENTITY = 'custom_system_notification';

   public $latestVersion = "";

   public $currentVersion = "";

   public $latestVersionLink = "";


  public function __construct(
        \Razorpay\Magento\Model\Config $config
    ) {

      $this->config = $config;
    }
   /**
    * Retrieve unique system message identity
    *
    * @return string
    */
   public function getIdentity()
   {
      return self::MESSAGE_IDENTITY;
   }

   /**
    * Check whether the system message should be shown
    *
    * @return bool
    */
   public function isDisplayed()
   {
      // return true will show the system notification, here you have to check your condition to display notification and base on that return true or false
      
      $disableUpgradeNotice = $this->config->getConfigData(Config::DISABLE_UPGRADE_NOTICE);
      
      $isActive = $this->config->getConfigData(Config::KEY_ACTIVE);

      if($isActive and !$disableUpgradeNotice)
      {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

        $this->currentVersion =  $objectManager->get('Magento\Framework\Module\ModuleList')->getOne('Razorpay_Magento')['setup_version'];

        $request = Requests::get("https://api.github.com/repos/razorpay/razorpay-magento/releases/latest");

        if($request->status_code === 200)
        {
          $razorpayLatestRelease = json_decode($request->body);

          $this->latestVersion = $razorpayLatestRelease->tag_name;

          $this->latestVersionLink = $razorpayLatestRelease->html_url;

          if(version_compare($this->currentVersion, $this->latestVersion, '<'))
          {
            return true;
          }
        
        }
      }
      
      
      return false;
   }

   /**
    * Retrieve system message text
    *
    * @return \Magento\Framework\Phrase
    */
   public function getText()
   {    
      return __('Please upgrade to the latest version of Razorpay (<a href="' . $this->latestVersionLink
                  . '" target="_blank">' . $this->latestVersion . '</a>) ');
   }

   /**
    * Retrieve system message severity
    * Possible default system message types:
    * - MessageInterface::SEVERITY_CRITICAL
    * - MessageInterface::SEVERITY_MAJOR
    * - MessageInterface::SEVERITY_MINOR
    * - MessageInterface::SEVERITY_NOTICE
    *
    * @return int
    */
   public function getSeverity()
   {
       return self::SEVERITY_NOTICE;
   }

}