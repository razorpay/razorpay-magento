<?php

namespace Razorpay\Magento\Setup;

use Magento\Framework\Setup\UpgradeSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Razorpay\Magento\Model\ResourceModel\OrderLink;

class UpgradeSchema implements  UpgradeSchemaInterface
{
	public function upgrade(SchemaSetupInterface $setup,
							ModuleContextInterface $context
						)
	{
		$setup->startSetup();

		if (version_compare($context->getVersion(), '3.0.0', '>'))
		{
			// Get module table
			$tableName = $setup->getTable('sales_order');

			// Check if the table already exists
			if ($setup->getConnection()->isTableExists($tableName) == true)
			{
				// Declare column definition
				$definition =  [
									'type' => \Magento\Framework\DB\Ddl\Table::TYPE_BOOLEAN,
									'nullable' => false,
									'comment' => 'Is placed by razorpay webhook',
									'default' => 0
								];

				$connection = $setup->getConnection();

				$connection->addColumn($tableName, 'by_razorpay_webhook', $definition);			 
			}
		}



        if (version_compare($context->getVersion(), '3.2.3', '>=')) {
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
                    'quote_id',
                    Table::TYPE_INTEGER,
                    [
                        'identity' => true,
                        'unique'   => true,
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
                    'increment_order_id',
                    Table::TYPE_TEXT,
                    32,
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
                    'by_webhook',
                    Table::TYPE_BOOLEAN,
                    1,
                    [
                        'nullable' => false,
                        'default' => 0
                    ]
                )
                ->addColumn(
                    'by_frontend',
                    Table::TYPE_BOOLEAN,
                    1,
                    [
                        'nullable' => false,
                        'default' => 0
                    ]
                )
                ->addColumn(
                    'webhook_count',
                    Table::TYPE_SMALLINT,
                    3,
                    [
                        'nullable' => false,
                        'default' => 0
                    ]
                )
                ->addColumn(
                    'order_placed',
                    Table::TYPE_BOOLEAN,
                    1,
                    [
                        'nullable' => false,
                        'default' => 0
                    ]
                )
                ->addIndex(
			        'quote_id',
			        ['quote_id', 'rzp_payment_id'],
			        [
						'type'		=> AdapterInterface::INDEX_TYPE_UNIQUE,
						'nullable'  => false,
					]
				)
				->addIndex(
			        'increment_order_id',
			        ['increment_order_id'],
			        [
						'type'		=> AdapterInterface::INDEX_TYPE_UNIQUE,
					]
				);

            $setup->getConnection()->createTable($table);
        }

		$setup->endSetup();
	}
}