<?php
$ch = curl_init('https://repo.packagist.org/packages.json');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CAINFO, 'C:/Users/msi/Desktop/symfony-agriculturefinale-20260410-0258/cacert.pem');
$res = curl_exec($ch);
$err = curl_error($ch);
$num = curl_errno($ch);
curl_close($ch);
var_dump($num, $err, $res === false);
?>
