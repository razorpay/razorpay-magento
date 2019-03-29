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
$response1 = $connection->fetchAll($query1); 
$magento_orderId = $response1[0]['order_id'];

$query2 = "SELECT status FROM sales_order WHERE entity_id = '$magento_orderId'";
$response2 = $connection->fetchAll($query2);

$magento_orderStatus = $response1[0]['status'];

echo '<pre>'; print_r($response1); echo '</pre>';
echo '<pre>'; print_r($magento_orderId); echo '</pre>';
echo '<pre>'; print_r($response1); echo '</pre>';
echo '<pre>'; print_r($magento_orderStatus); echo '</pre>';
