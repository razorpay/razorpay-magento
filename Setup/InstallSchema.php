$setup->getConnection()->addColumn(
        $setup->getTable('sales_payment_transaction'),
        ‘verify_transaction',
        [
            'type' => \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
            'length' => 255,
            'nullable' => true,
            'comment' => ‘Verify Transaction'
        ]
    );
