<?php

namespace Razorpay\Magento\Model\Util;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

/**
 * Dedicated logger for the bundle child line item flow.
 *
 * Writes to var/log/bundle_child_items.log.
 *
 * Uses Monolog's StreamHandler (the logging stack Magento 2.4.x ships with)
 * rather than \Zend_Log, which is not installed in Magento 2.4.
 *
 * Logging must never break checkout, so every write is guarded.
 */
class BundleChildLogger
{
    public const LOG_FILE = 'bundle_child_items.log';

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var Logger|null
     */
    private $logger;

    public function __construct(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    /**
     * @param string $message
     * @param array  $context
     * @return void
     */
    public function info($message, array $context = [])
    {
        $this->write('info', $message, $context);
    }

    /**
     * @param string $message
     * @param array  $context
     * @return void
     */
    public function warning($message, array $context = [])
    {
        $this->write('warning', $message, $context);
    }

    /**
     * @param string $message
     * @param array  $context
     * @return void
     */
    public function error($message, array $context = [])
    {
        $this->write('error', $message, $context);
    }

    /**
     * @param string $level
     * @param string $message
     * @param array  $context
     * @return void
     */
    private function write($level, $message, array $context = [])
    {
        try
        {
            $logger = $this->getLogger();

            if ($logger === null)
            {
                return;
            }

            $logger->{$level}(self::marker($message), $context);
        }
        catch (\Throwable $e)
        {
            // Logging must never interrupt order creation.
            // Fall back to the PHP error log so the failure is not silent.
            error_log('bundle_child_items.log write failed: ' . $e->getMessage());
        }
    }

    /**
     * Prefix every line so the flow is easy to isolate with grep.
     *
     * @param string $message
     * @return string
     */
    private static function marker($message)
    {
        return '[bundle_child_line_items] ' . $message;
    }

    /**
     * @return Logger|null
     */
    private function getLogger()
    {
        if ($this->logger !== null)
        {
            return $this->logger;
        }

        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $path = $directory->getAbsolutePath('log/' . self::LOG_FILE);

        $logger = new Logger('bundle_child_line_items');
        $logger->pushHandler(new StreamHandler($path));

        $this->logger = $logger;

        return $this->logger;
    }
}
