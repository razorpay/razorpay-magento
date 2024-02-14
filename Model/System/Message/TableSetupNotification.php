<?php

namespace Razorpay\Magento\Model\System\Message;

use Magento\Framework\Notification\MessageInterface;

class TableSetupNotification implements MessageInterface
{
   /**
    * Message identity
    */
    const MESSAGE_IDENTITY = 'custom_system_notification';

    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    protected $resourceConnection;


    public function __construct(
        \Magento\Framework\App\ResourceConnection $resourceConnection
    )
    {
        $this->resourceConnection = $resourceConnection;
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
        $connection = $this->resourceConnection->getConnection();
        $table = $connection->getTableName('razorpay_sales_order');

        $tableDescription = $connection->describeTable($table);

        $allColumnDescriptions = [
            [
                'COLUMN_NAME'   => 'entity_id',
                'DATA_TYPE'     => 'int',
                'NULLABLE'      => false,
                'PRIMARY'       => true,
                'IDENTITY'      => true,
            ],
            [
                'COLUMN_NAME'   => 'order_id',
                'DATA_TYPE'     => 'int',
                'NULLABLE'      => true,
            ],
            [
                'COLUMN_NAME'   => 'rzp_order_id',
                'DATA_TYPE' => 'varchar',
                'NULLABLE'  => true,
                'LENGTH'    => '25'
            ],
            [
                'COLUMN_NAME'   => 'rzp_payment_id',
                'DATA_TYPE' => 'varchar',
                'NULLABLE'  => true,
                'LENGTH'    => '25'
            ],
            [
                'COLUMN_NAME'   => 'rzp_webhook_data',
                'DATA_TYPE' => 'text',
                'NULLABLE'  => true
            ],
            [
                'COLUMN_NAME'   => 'rzp_webhook_notified_at',
                'DATA_TYPE' => 'bigint',
                'NULLABLE'  => true
            ],
            [
                'COLUMN_NAME'   => 'rzp_update_order_cron_status',
                'DATA_TYPE' => 'int',
                'NULLABLE'  => false,
                'DEFAULT'   => '0'
            ]
        ];
        
        foreach($allColumnDescriptions as $singleColumnDescription)
        {
            $name = $singleColumnDescription['COLUMN_NAME'];
            foreach($singleColumnDescription as $key => $value)
            {
                if($tableDescription[$name][$key] !== $value)
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
        return __('Database error: razorpay_sales_order table is not setup correctly. Please reach out to us on <a href="https://razorpay.com/support/" target="_blank"> https://razorpay.com/support/ </a> if you face any issues.');
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
