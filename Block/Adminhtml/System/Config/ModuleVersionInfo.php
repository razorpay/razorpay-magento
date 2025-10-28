<?php

namespace Razorpay\Magento\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Razorpay\Magento\Helper\Data as RazorpayHelper;

class ModuleVersionInfo extends Field
{
    protected $helper;

    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        RazorpayHelper $helper,
        array $data = []
    ) {
        $this->helper = $helper;
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        $current = $this->helper->getModuleVersion() ?? 'Unknown';
        $latest = $this->helper->getLatestVersion() ?? 'Could not fetch';

        $style = "background: #f4f6f8; padding: 10px; margin-bottom: 15px; border-left: 4px solid #007bdb;";
        $html = "<div style='{$style}'>
                    <strong>Current Razorpay Plugin Version:</strong> {$current}<br/>
                    <strong>Latest Available Version:</strong> {$latest}
                </div>";

        if ($this->helper->needToUpdate()) {
            $html .= "<div style='color: red;'>
                        <strong>Update Available!</strong> Please update to the latest version for new features and bug fixes.<br/>
                        <a href='https://github.com/razorpay/razorpay-magento' target='_blank'>Click here to download the latest version</a>
                      </div>";
        } else {
            $html .= "<div style='color: green;'>
                        <strong>Your plugin is up to date!</strong>
                      </div>";
        }

        return $html;
    }
}
