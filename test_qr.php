<?php
// Helper to call API
error_reporting(E_ALL);
ini_set('display_errors', 1);

function callApi($method, $url, $data = null, $token = null) {
    $curl = curl_init();
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;

    curl_setopt($curl, CURLOPT_URL, 'http://127.0.0.1:8001/api' . $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    if ($method === 'POST') curl_setopt($curl, CURLOPT_POST, 1);
    if ($data) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    
    echo "[$method] $url - Status: $httpCode\n";
    if ($httpCode >= 400) echo "Error: $response\n";
    return json_decode($response, true);
}

// 1. Login as Admin
echo "Logging in as Admin...\n";
$login = callApi('POST', '/auth/verify', [
    'mobile_number' => '999999999',
    'otp' => '123456',
    'role' => 'ADMIN'
]);

if (!isset($login['access_token'])) {
    die("Login failed!\n");
}
$token = $login['access_token'];
echo "Token obtained.\n\n";

// 2. Generate Batches
echo "Generating QR Batch...\n";
$qr = callApi('POST', '/admin/qr/generate', [
    'count' => 5,
    'name' => 'Test Batch'
], $token);

if (isset($qr['batch_id'])) {
    echo "Batch Created ID: " . $qr['batch_id'] . "\n";
    
    // 3. Get Codes
    echo "Fetching Codes...\n";
    $codes = callApi('GET', '/admin/qr/batches/' . $qr['batch_id'], null, $token);
    echo "Retrieved " . count($codes) . " codes.\n";
} else {
    echo "Failed to create batch.\n";
}
