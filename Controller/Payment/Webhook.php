<?php
$msg = 'Request'."\n";
$msg .= var_dump($_REQUEST)."\n\n";
$msg .= 'UA'."\n";
$msg .= var_dump($_SERVER['USER_AGENT'])."\n\n-x-";
mail("seher@kdc.in","WebHook Test ".mt_rand(100000,999999),"Request:".$msg);
?>
