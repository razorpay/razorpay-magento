<?php

namespace Razorpay\Magento\Model\Util;

class DebugUtils
{
    /**
     * @var \Razorpay\Magento\Model\Config
     */
    protected $config;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    protected $isDebugModeEnabled;

    public function __construct(
        \Razorpay\Magento\Model\Config $config,
        \Psr\Log\LoggerInterface $logger
    )
    {
        $this->config                   = $config;
        $this->logger                   = $logger;

        $this->isDebugModeEnabled = $this->config->isDebugModeEnabled();
    }

    public function log($message)
    {
        if($this->isDebugModeEnabled)
        {
            $this->logger->info($message);
        }
    }
}