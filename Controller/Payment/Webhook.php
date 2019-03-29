<?php

$response = file_get_contents('php://input');
$rzp_response = json_decode($response, true);

$rzp = $rzp_response['payload']['payment']['entity'];
$rzp_id = $rzp['id'];
$rzp_status = $rzp['status'];
$rzp_captured = $rzp['captured'];

$objectManager = \Magento\Framework\App\ObjectManager::getInstance();
$resource = $objectManager->get('Magento\Framework\App\ResourceConnection');
$connection = $resource->getConnection();

$query1 = "SELECT order_id FROM sales_payment_transaction WHERE txn_id = 'pay_CDBwkdURcNaAHN'";
$magento_orderId = $connection->fetchAll($query1); 

//$query2 = "SELECT status FROM sales_order WHERE entity_id = '$magento_orderId'";
//$magento_orderStatus = $connection->fetchAll($query2);

echo '<pre>'; print_r($magento_orderId); echo '</pre>';
//echo '<pre>'; print_r($magento_orderStatus); echo '</pre>';
