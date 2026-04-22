<?php
$der = file_get_contents('951030CD37FE1933733FF12A246B513B54BA2539-avast.cer');
$pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END CERTIFICATE-----\n";
file_put_contents('avast-root.pem', $pem);
$cert = openssl_x509_read($pem);
if ($cert) {
    echo "PEM_OK\n";
    openssl_x509_export($cert, $pem2);
    file_put_contents('avast-root-verified.pem', $pem2);
    echo "VERIFIED_OK\n";
} else {
    echo "PEM_PARSE_FAIL\n";
}
?>
