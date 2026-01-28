<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Loan;

$token = '593c7e1a-ae0e-4b3d-acd0-cee2493f4cdb';
$loan = Loan::with('user')->where('kyc_token', $token)->first();

if ($loan) {
    echo "SUCCESS: Token found!\n";
    echo "Loan ID: " . $loan->id . "\n";
    echo "User: " . ($loan->user ? $loan->user->name : 'N/A') . " (" . $loan->user_id . ")\n";
    echo "Status: " . $loan->status . "\n";
    echo "KYC Submitted At: " . ($loan->kyc_submitted_at ?? 'Not submitted yet') . "\n";
} else {
    echo "ERROR: Token NOT found.\n";
}
