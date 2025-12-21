<?php

namespace Razorpay\Magento\Helper;

use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\HTTP\Client\Curl;


class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
    /**
     * @var ModuleListInterface
     */
    protected $moduleList;

    /**
     * @var Curl
     */
    protected $curl;

    /**
     * Constructor
     */
    public function __construct(
        Context $context,
        ModuleListInterface $moduleList,
        Curl $curl
    ) {
        $this->moduleList = $moduleList;
        $this->curl = $curl;
        parent::__construct($context);
    }

    /**
     * Get current module name
     *
     * @return string
     */
    public function getModuleName()
    {
        return 'Razorpay_Magento';
    }

    /**
     * Get current module version
     *
     * @return string|null
     */
    public function getModuleVersion()
    {
        $moduleInfo = $this->moduleList->getOne($this->getModuleName());
        return $moduleInfo['setup_version'] ?? null;
    }

    /**
     * Get latest version from GitHub
     *
     * @return string|null
     */
    public function getLatestVersion()
    {
        $url = "https://api.github.com/repos/razorpay/razorpay-magento/releases/latest";

        try {
            $this->curl->addHeader("User-Agent", "Magento"); // GitHub requires this
            $this->curl->get($url);
            $response = $this->curl->getBody();

            $release = json_decode($response);

            if (isset($release->tag_name)) {
                return $release->tag_name;
            }
        } catch (\Exception $e) {
            return null; // Handle error gracefully
        }

        return null;
    }
    
    /**
     * Check if the module needs to be updated
     *
     * @return array
     */
    public function needToUpdate()
    {
        $currentVersion = $this->getModuleVersion();
        $latestVersion = $this->getLatestVersion();

        if ($currentVersion && $latestVersion) {
            if (strpos($currentVersion, '-beta') !== false) {
                $betaVersion = str_replace('-beta', '', $currentVersion);
                $betaVersion = 'beta-' . $betaVersion;
                $currentVersion = $betaVersion;
            }

            if (version_compare($currentVersion, $latestVersion, '<')) {
                return true;
            }
        }
        // If either version is not available, we cannot determine if an update is needed
        return false; // Default to false if versions are not available
    }
}
