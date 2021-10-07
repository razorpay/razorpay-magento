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
                225,
                [
                    'nullable' => true
                ]
            )
            ->addColumn(
                'rzp_order_amount',
                Table::TYPE_INTEGER,
                20,
                [
                    'nullable' => true,
                    'comment'  => 'RZP order amount'
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
            ->addColumn(
                'amount_paid',
                Table::TYPE_INTEGER,
                20,
                [
                    'nullable' => true,
                    'comment'  => 'Actual paid amount'
                ]
            )
            ->addColumn(
                'email',
                Table::TYPE_TEXT,
                255,
                [
                    'nullable' => true,
                    'comment'  => 'payment email'
                ]
            )
            ->addColumn(
                'contact',
                Table::TYPE_TEXT,
                25,
                [
                    'nullable' => true,
                    'comment'  => 'payment contact'
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

        if (version_compare($context->getVersion(), '3.5.4', '<')) {
            $setup->getConnection()->addColumn(
                $setup->getTable(OrderLink::TABLE_NAME),
                'rzp_signature',
                [
                    'nullable' => true,
                    'type'     => Table::TYPE_TEXT,
                    'length'   => 255,
                    'comment'  => 'RZP signature'
                ]
            );

            $setup->getConnection()->addColumn(
                $setup->getTable(OrderLink::TABLE_NAME),
                'rzp_order_amount',
                [
                    'nullable' => true,
                    'type'     => Table::TYPE_INTEGER,
                    'length'   => 20,
                    'comment'  => 'RZP order amount'
                ]
            );
        }

        if (version_compare($context->getVersion(), '3.6.5', '<')) {
            $setup->getConnection()->addColumn(
                $setup->getTable(OrderLink::TABLE_NAME),
                'amount_paid',
                [
                    'nullable' => true,
                    'type'     => Table::TYPE_INTEGER,
                    'length'   => 20,
                    'comment'  => 'Actual paid amount'
                ]
            );

            $setup->getConnection()->addColumn(
                $setup->getTable(OrderLink::TABLE_NAME),
                'email',
                [
                    'nullable' => true,
                    'type'     => Table::TYPE_TEXT,
                    'length'   => 255,
                    'comment'  => 'payment email'
                ]
            );

            $setup->getConnection()->addColumn(
                $setup->getTable(OrderLink::TABLE_NAME),
                'contact',
                [
                    'nullable' => true,
                    'type'     => Table::TYPE_TEXT,
                    'length'   => 25,
                    'comment'  => 'payment contact'
                ]
            );
        }

        $setup->endSetup();
    }
}
