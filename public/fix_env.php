<?php
$key = $_GET['key'] ?? '';
if ($key !== 'openscore_deploy_2026') { http_response_code(401); die('Unauthorized'); }

$envPath = '/home/u910898544/domains/msmeloan.sbs/public_html/api/.env';

if (!file_exists($envPath)) {
    echo "❌ .env not found at: $envPath";
    exit;
}

$content = file_get_contents($envPath);

// Fix KYC_FORM_URL
$content = preg_replace(
    '/KYC_FORM_URL=.*/m',
    'KYC_FORM_URL=https://kyc.msmeloan.sbs',
    $content
);

file_put_contents($envPath, $content);

// Verify
$lines = explode("\n", file_get_contents($envPath));
foreach ($lines as $line) {
    if (strpos($line, 'KYC_FORM_URL') !== false) {
        echo "✅ Updated: $line\n";
    }
}
echo "\nDone!";
