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
                [
                    'nullable' => false,
                    'default'  => 0,
                ]
            );

        $setup->getConnection()->createTable($table);

        $setup->endSetup();

        // if (version_compare($context->getVersion(), '4.0.6', '<'))
        // {
        //     $sortOrder = $this->sortOrderBuilder->setField('entity_id')->setDirection('DESC')->create();

        //     $searchCriteria = $this->searchCriteriaBuilder
        //                         ->addFilter(
        //                             'state',
        //                             static::STATE_NEW,
        //                             'eq'
        //                         )->setSortOrders(
        //                             [$sortOrder]
        //                         )->create();
            
        //     $orders = $this->orderRepository->getList($searchCriteria);
                                
        //     $objectManagement = \Magento\Framework\App\ObjectManager::getInstance();

        //     foreach ($orders->getItems() as $order)
        //     {
        //         if ($order->getPayment()->getMethod() === 'razorpay') 
        //         {

        //             $orderLink = $objectManagement->get('Razorpay\Magento\Model\OrderLink')
        //                                             ->getCollection()
        //                                             ->addFilter('entity_id', $order->getEntityId())
        //                                             ->getFirstItem();
                    
        //             $orderLink->setOrderId($order->getEntityId())
        //                     ->setRzpWebhookData($order->getRzpWebhookData())
        //                     ->setRzpUpdateOrderCronStatus(0)
        //                     ->setRzpOrderId($order->getRzpOrderId())
        //                     ->save();
                    
        //             $this->logger->info('Migrated Data from sales_order table to razorpay_sales_order for id = '.$order->getEntityId()); 
        //         }
        //     }
        // }
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
