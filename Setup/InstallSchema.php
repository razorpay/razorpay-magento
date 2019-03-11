<?php

namespace Razorpay\Magento\Setup;
 
class InstallSchema implements Magento\Framework\Setup\InstallSchemaInterface
{
    public function install(
        SchemaSetupInterface $setup
    ) {
        $setup->getConnection()->addColumn(
                $setup->getTable('sales_payment_transaction'),
                ‘verify_transaction',
                [
                        'type' => \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                        'comment' => ‘Verify Transaction'
                ]
        );
    }
}
