<?php

namespace Razorpay\Magento\Block\Adminhtml\Grid\Column\Renderer;

use Razorpay\Api\Api;
use \Magento\Framework\View\Element\Template;
use \Magento\Framework\View\Element\Template\Context;
use \Magento\Store\Model\StoreManagerInterface;

class Assignaction extends \Magento\Backend\Block\Widget\Grid\Column\Renderer\Text
{

    protected $_helper;
    protected $urlBuilder;

    public function __construct(\Magento\Framework\UrlInterface $urlBuilder) 
    {
        $this->urlBuilder = $urlBuilder;
    }

    public function render(\Magento\Framework\DataObject $row)
    {
        /** @var \Magento\Integration\Model\Integration $row */
        $merchantId = $row->getData("merchant_id");
        $manageDealId = $row->getData("entity_id");
        $actionUrl = $this->urlBuilder->getUrl("#" );
        return "<a href=".$actionUrl.">Verify</a>";
    }
}
