<?php
require_once 'vendor/autoload.php';

use OTPHP\TOTP;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

echo "Testing OTPHP and QR Code libraries...\n";
echo "GD Extension loaded: " . (extension_loaded('gd') ? "YES" : "NO") . "\n";

try {
    // Test TOTP generation
    $totp = TOTP::create();
    $secret = $totp->secret;
    echo "✓ TOTP Secret generated: " . substr($secret, 0, 10) . "...\n";
    echo "✓ TOTP label: " . $totp->getLabel() . "\n";
    
    // Test provisioning URI
    $provisioningUri = $totp->getProvisioningUri();
    echo "✓ Provisioning URI: " . substr($provisioningUri, 0, 50) . "...\n";
    
    // Test QR Code generation
    $qrCode = new QrCode(data: $provisioningUri);
    $writer = new PngWriter();
    echo "✓ QR Code object created successfully\n";
    
    // Try to write to file
    $result = $writer->write($qrCode);
    echo "✓ QR Code PNG generated successfully\n";
    
    echo "\n✓✓✓ ALL TESTS PASSED ✓✓✓\n";
} catch (Throwable $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}
