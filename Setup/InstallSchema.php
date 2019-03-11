<?php

namespace Razorpay\Magento\Setup;
 
use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
 
class InstallSchema implements InstallSchemaInterface
{
    public function install(
        SchemaSetupInterface $setup,
        ModuleContextInterface $context
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
