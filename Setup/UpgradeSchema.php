<?php

namespace Razorpay\Magento\Setup;

use Razorpay\Magento\Model\Config;
use Magento\Framework\Setup\UpgradeSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Razorpay\Magento\Model\TrackPluginInstrumentation;
use Magento\Framework\Setup\SchemaSetupInterface;
use \Psr\Log\LoggerInterface;
use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Razorpay\Magento\Model\ResourceModel\OrderLink;

class UpgradeSchema implements UpgradeSchemaInterface
{
    protected $config;
    protected $trackPluginInstrumentation;
    protected $logger;
    protected $orderRepository;
    protected $searchCriteriaBuilder;
    protected $sortOrderBuilder;

    protected const STATE_NEW           = 'new';

    public function __construct(
        Config $config,
        TrackPluginInstrumentation $trackPluginInstrumentation,
        LoggerInterface $logger,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder,
        \Magento\Framework\Api\SortOrderBuilder $sortOrderBuilder
    )
    {
        $this->config                       = $config;
        $this->trackPluginInstrumentation   = $trackPluginInstrumentation;
        $this->logger                       = $logger;
        $this->orderRepository              = $orderRepository;
        $this->searchCriteriaBuilder        = $searchCriteriaBuilder;
        $this->sortOrderBuilder             = $sortOrderBuilder;
    }

    public function upgrade(
        SchemaSetupInterface $setup,
        ModuleContextInterface $context
    )
    {
        $this->pluginUpgrade();

        $setup->startSetup();

        //remove older configs for current version
        $select = $setup->getConnection()->select()->from(
            $setup->getTable('core_config_data'),
            ['config_id', 'value', 'path']
        )->where(
            'path like ?',
            '%payment/razorpay%'
        );

        foreach ($setup->getConnection()->fetchAll($select) as $configRow)
        {
            if (in_array($configRow['path'],
                ['payment/razorpay/payment_action',
                 'payment/razorpay/order_status',
                 'payment/razorpay/webhook_wait_time',
                 'payment/razorpay/enable_webhook',
                 'payment/razorpay/webhook_events'
                ]))
            {
                $setup->getConnection()->delete(
                    $setup->getTable('core_config_data'),
                    ['config_id = ?' => $configRow['config_id']]
                );
            }
        }

        $tableName = $setup->getTable('sales_order');
        if ($setup->getConnection()->isTableExists($tableName) == true)
        {
            $setup->getConnection()->addColumn(
                $tableName,
                'rzp_order_id',
                [
                    'nullable' => true,
                    'type'     => \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                    'length'   => 55,
                    'comment'  => 'RZP Order ID'
                ]
            );
            $setup->getConnection()->addColumn(
                $tableName,
                'rzp_webhook_notified_at',
                [
                    'nullable' => true,
                    'type'     => \Magento\Framework\DB\Ddl\Table::TYPE_BIGINT,
                    'comment'  => 'RZP Webhook Notified Timestamp'
                ]
            );

            $setup->getConnection()->addColumn(
                $tableName,
                'rzp_webhook_data',
                [
                    'nullable' => true,
                    'type'     => \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                    'comment'  => 'RZP Webhook Data'
                ]
            );

            $setup->getConnection()->addColumn(
                $tableName,
                'rzp_update_order_cron_status',
                [
                    'nullable' => false,
                    'default'  => 0,
                    'type'     => \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER,
                    'comment'  => 'RZP Update Order Processing Cron # of times executed'
                ]
            );
        }

        $table = $setup->getConnection()->newTable($setup->getTable(OrderLink::TABLE_NAME));

        $table
            ->addColumn(
                'entity_id',
                Table::TYPE_INTEGER,
                null,
                [
                    'identity' => true,
                    'unsigned' => true,
                    'primary'  => true,
                    'nullable' => false
                ]
            )
            ->addColumn(
                'order_id',
                Table::TYPE_INTEGER,
                [
                    'nullable' => true
                ]
            )
            ->addColumn(
                'rzp_order_id',
                Table::TYPE_TEXT,
                25,
                [
                    'nullable' => true
                ]
            )
            ->addColumn(
                'rzp_payment_id',
                Table::TYPE_TEXT,
                25,
                [
                    'nullable' => true
                ]
            )
            ->addColumn(
                'rzp_webhook_data',
                Table::TYPE_TEXT,
                1000,
                [
                    'nullable' => true
                ]
            )
            ->addColumn(
                'rzp_webhook_notified_at',
                Table::TYPE_BIGINT,
                [
                    'nullable' => true
                ]
            )
            ->addColumn(
                'rzp_update_order_cron_status',
                Table::TYPE_INTEGER,
                null,
                [
                    'nullable' => false,
                    'default'  => 0,
                ]
            );

        $setup->getConnection()->createTable($table);

        $setup->endSetup();
    }

    /**
     * Plugin upgrade event track
     */
    public function pluginUpgrade()
    {
        $storeName = $this->config->getMerchantNameOverride();

        $eventData = array(
                        "store_name" => $storeName,
                    );

        $this->logger->info("Event : Plugin Upgrade. In function " . __METHOD__);

        $response['segment'] = $this->trackPluginInstrumentation->rzpTrackSegment('Plugin Upgrade', $eventData);

        $response['datalake'] = $this->trackPluginInstrumentation->rzpTrackDataLake('Plugin Upgrade', $eventData);

        $this->logger->info(json_encode($response));
    }
}
