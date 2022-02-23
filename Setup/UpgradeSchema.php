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
                 'payment/razorpay/webhook_wait_time'
                ]))
            {
                $setup->getConnection()->delete(
                    $setup->getTable('core_config_data'),
                    ['config_id = ?' => $configRow['config_id']]
                );
            }
        }

        if (version_compare($context->getVersion(), 'beta-3.3.4', '>')) {
            $tableName = $setup->getTable('sales_order');
            if ($setup->getConnection()->isTableExists($tableName) == true) {
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
            }
        }

		$setup->endSetup();
	}
}