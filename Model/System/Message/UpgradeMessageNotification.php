<?php

namespace Razorpay\Magento\Model\System\Message;

use Magento\Framework\Notification\MessageInterface;
use Razorpay\Magento\Model\Config;
use Requests;

// Include Requests only if not already defined
if (class_exists('WpOrg\Requests\Autoload') === false)
{
    require_once __DIR__.'/../../../../Razorpay/Razorpay.php';
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

class UpgradeMessageNotification implements MessageInterface
{
   /**
    * Message identity
    */
    const MESSAGE_IDENTITY = 'custom_system_notification';

    public $latestVersion = '';

    public $currentVersion = '';

    public $latestVersionLink = '';


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
        // Return true will show the system notification,
        // Here you have to check your condition to display notification and base on that return true or false
        $disableUpgradeNotice = $this->config->getConfigData(Config::DISABLE_UPGRADE_NOTICE);

        $isActive = $this->config->getConfigData(Config::KEY_ACTIVE);

        if ($isActive and !$disableUpgradeNotice)
        {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

            $this->currentVersion =  $objectManager->get('Magento\Framework\Module\ModuleList')
                                     ->getOne('Razorpay_Magento')['setup_version'];

            $request = Requests::get("https://api.github.com/repos/razorpay/razorpay-magento/releases/latest");

            if ($request->status_code === 200)
            {
                 $razorpayLatestRelease = json_decode($request->body);

                 $this->latestVersion = $razorpayLatestRelease->tag_name;

                 $this->latestVersionLink = $razorpayLatestRelease->html_url;

                // fix for beta version check, as version comapre is not comparing for versions with postfix -beta
                if (strpos($this->currentVersion, '-beta') !== false)
                {
                    $betaVersion = $this->currentVersion;
                    $betaVersion = str_replace('-beta', '', $betaVersion);
                    $betaVersion = 'beta-' . $betaVersion;

                    $this->currentVersion = $betaVersion;
                }

                if (version_compare($this->currentVersion, $this->latestVersion, '<'))
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
