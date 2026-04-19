<?php
// Debug script to check API key and connectivity

$apiKey = getenv('PROFANITY_FILTER_API_KEY') ?: trim($_ENV['PROFANITY_FILTER_API_KEY'] ?? '');

echo "=== API NINJAS DEBUG INFO ===\n";
echo "API Key: " . ($apiKey ? "PRESENT (length: " . strlen($apiKey) . ")" : "NOT FOUND") . "\n";
echo "API Key value: " . $apiKey . "\n";
echo "API Key formatted: [" . substr($apiKey, 0, 5) . "..." . substr($apiKey, -5) . "]\n";

if (empty($apiKey)) {
    echo "\n❌ ERROR: API key is not configured!\n";
    echo "Please add this to your .env file:\n";
    echo "API_NINJAS_KEY=your_actual_api_key_here\n";
    exit(1);
}

echo "\nTesting API connectivity...\n";

// Try a simple cURL request
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://api.api-ninjas.com/v1/imagetotext',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'X-Api-Key: ' . $apiKey,
        'Content-Type: application/octet-stream'
    ],
    CURLOPT_POSTFIELDS => 'test',
    CURLOPT_TIMEOUT => 10
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP Status: " . $http_code . "\n";
if ($error) {
    echo "cURL Error: " . $error . "\n";
} else {
    echo "Response: " . substr($response, 0, 200) . "\n";
}

if ($http_code === 400) {
    echo "\n⚠️  API returned 400 Bad Request\n";
    echo "This likely means:\n";
    echo "1. Invalid API key\n";
    echo "2. Wrong request format\n";
    echo "3. API key has exceeded rate limit\n";
} elseif ($http_code === 401) {
    echo "\n❌ API returned 401 Unauthorized\n";
    echo "Your API key is invalid or expired.\n";
    echo "Get a new one at: https://www.api-ninjas.com/profile/api-keys\n";
}

echo "\n=== END DEBUG ===\n";
