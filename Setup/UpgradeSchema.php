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
                'rzp_signature',
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
            ->addColumn(
                'webhook_first_notified_at',
                Table::TYPE_BIGINT,
                [
                    'nullable' => true
                ]
            )
            ->addIndex(
                'quote_id',
                ['quote_id', 'rzp_payment_id'],
                [
                    'type'      => AdapterInterface::INDEX_TYPE_UNIQUE,
                    'nullable'  => false,
                ]
            )
            ->addIndex(
                'increment_order_id',
                ['increment_order_id'],
                [
                    'type'      => AdapterInterface::INDEX_TYPE_UNIQUE,
                ]
            );

        $setup->getConnection()->createTable($table);

        $setup->endSetup();
    }
}