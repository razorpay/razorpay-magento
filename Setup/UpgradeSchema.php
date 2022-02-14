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

		$setup->endSetup();
	}
}