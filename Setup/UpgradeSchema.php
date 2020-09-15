<?php

namespace Razorpay\Magento\Setup;

use Magento\Framework\Setup\UpgradeSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

class UpgradeSchema implements  UpgradeSchemaInterface
{
	public function upgrade(SchemaSetupInterface $setup,
							ModuleContextInterface $context
						)
	{
		$setup->startSetup();

		if (version_compare($context->getVersion(), '1.0.1') < 0)
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

		$setup->endSetup();
	}
}