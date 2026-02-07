<?php
// Simulate the referral code generation logic I implemented in AuthController

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Support\Str;

echo "--- Testing Referral Code Generation ---\n";

$mobile = '9876543299'; // Test mobile
User::where('mobile_number', $mobile)->delete();

// 1. Simulate NEW USER Creation (AuthController Logic)
echo "1. Creating new user...\n";
$user = User::create([
    'mobile_number' => $mobile,
    'role' => 'CUSTOMER',
    'status' => 'ACTIVE',
    'is_onboarded' => false,
    'password' => bcrypt('password'),
    'my_referral_code' => strtoupper(Str::random(8)) // Logic from AuthController
]);

echo "Created User ID: {$user->id}\n";
echo "Referral Code: {$user->my_referral_code}\n";

if (empty($user->my_referral_code)) {
    echo "FAIL: Referral code is empty for new user.\n";
    exit(1);
} else {
    echo "PASS: Referral code present for new user.\n";
}

// 2. Simulate EXISTING USER Logic (AuthController Logic)
// First, clear the code manually to simulate old user
$user->my_referral_code = null;
$user->save();
$user->refresh();

if (!empty($user->my_referral_code)) {
    echo "FAIL: Could not clear referral code for test.\n";
    exit(1);
}

echo "2. Applying patch for existing user...\n";
// Logic from AuthController else block
if (empty($user->my_referral_code)) {
    $user->my_referral_code = strtoupper(Str::random(8));
    $user->save();
}

$user->refresh();
echo "Patched Code: {$user->my_referral_code}\n";

if (empty($user->my_referral_code)) {
    echo "FAIL: Patch failed for existing user.\n";
    exit(1);
} else {
    echo "PASS: Patch successful for existing user.\n";
}

echo "--- ALL TESTS PASSED ---\n";
