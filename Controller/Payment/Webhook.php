<?php

$objectManager = \Magento\Framework\App\ObjectManager::getInstance();
$resource = $objectManager->get('Magento\Framework \App\ResourceConnection');
$connection = $resource->getConnection();
$tableName = $resource->getTableName('sales_payment_transaction');
//$dates = date("Y-m-d");
//$phone = $_POST["phone"];
$sql = "SELECT * FROM sales_payment_transaction WHERE is_closed = '0'";
$result = $connection->fetchAll($sql); 
echo '<pre>'; print_r($result); echo '</pre>';
