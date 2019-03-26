<?php
$request = file_get_contents('php://input');
$msg = 'Request:'."\n";
$msg .= json_decode($request, true)."\n\n";

$msg .= 'UA:'."\n";
$msg .= $_SERVER['HTTP_USER_AGENT']."\n\n\n-x-";
mail("seher@kdc.in","WebHook Test ".mt_rand(100000,999999),"Request:".$msg);
echo $msg;
exit();
?>
