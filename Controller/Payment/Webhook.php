<?php
$msg = 'Request:'."\n";
$msg .= json_encode($_REQUEST)."\n\n";
$msg .= 'UA:'."\n";
$msg .= $_SERVER['HTTP_USER_AGENT']."\n\n-x-";
mail("seher@kdc.in","WebHook Test ".mt_rand(100000,999999),"Request:".$msg);
echo $msg;
exit();
?>
