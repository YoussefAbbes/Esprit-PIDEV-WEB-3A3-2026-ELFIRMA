<?php
$ctx = stream_context_create(array(
    'ssl' => array(
        'cafile' => 'C:/Users/msi/Desktop/symfony-agriculturefinale-20260410-0258/cacert.pem',
        'verify_peer' => true,
        'verify_peer_name' => true,
    ),
));
$fp = @fopen('https://repo.packagist.org/packages.json', 'r', false, $ctx);
var_dump($fp !== false);
if ($fp) {
    echo fgets($fp, 1200);
    fclose($fp);
} else {
    var_dump(error_get_last());
}
?>
