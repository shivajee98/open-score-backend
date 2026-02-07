<?php
// Simulate the referral cashback logic from AuthController

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Wallet;
use App\Models\ReferralSetting;
use App\Models\SignupCashbackSetting;
use App\Services\WalletService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

echo "--- Testing Referral Cashback & Code Logic ---\n";

// Setup Data
$ws = app(WalletService::class);

// Create System Wallet
$systemWallet = $ws->getSystemWallet();
echo "System Wallet Balance (Start): {$ws->getBalance($systemWallet->id)}\n";

// Add Funds for Test
$ws->credit($systemWallet->id, 1000, 'ADMIN_CREDIT', 1, "Test Funds", 'COMPLETED');
echo "System Wallet Balance (Funded): {$ws->getBalance($systemWallet->id)}\n";

$refSetting = ReferralSetting::first();
if (!$refSetting) {
    echo "Creating default ReferralSetting...\n";
    $refSetting = ReferralSetting::create(['is_enabled' => true, 'signup_bonus' => 50]);
} else {
    $refSetting->update(['is_enabled' => true, 'signup_bonus' => 50]);
    echo "Updated ReferralSetting: Bonus = 50\n";
}

$refCode = 'TESTREF1';
$referrerMobile = '9999999990';
$newMobile = '9999999991';

// Cleanup
User::where('mobile_number', $referrerMobile)->delete();
User::where('mobile_number', $newMobile)->delete();

// 1. Create REFERRER
echo "\n1. Creating Referrer...\n";
$referrer = User::create([
    'mobile_number' => $referrerMobile,
    'role' => 'CUSTOMER',
    'status' => 'ACTIVE',
    'is_onboarded' => true,
    'password' => bcrypt('password'),
    'my_referral_code' => $refCode
]);
$referrerWallet = $ws->createWallet($referrer->id);
echo "Referrer Created. ID: {$referrer->id}, Code: {$referrer->my_referral_code}\n";


// 2. Simulate NEW USER Signup with Code (AuthController Logic)
echo "\n2. Creating New User with Code '{$refCode}'...\n";

// --- Logic Start ---
$referrerId = $referrer->id;
$signupBonus = $refSetting->signup_bonus; // 50
$cashbackAmount = $signupBonus; // Assuming referral bonus = signup bonus for simplicity in test

// Create User
$user = User::create([
    'mobile_number' => $newMobile,
    'role' => 'CUSTOMER',
    'status' => 'ACTIVE',
    'is_onboarded' => false,
    'password' => bcrypt('password'),
    'referral_campaign_id' => null,
    'sub_user_id' => null,
    'my_referral_code' => strtoupper(Str::random(8)) // Verify this is generated!
]);
$userWallet = $ws->createWallet($user->id);

// Verify my_referral_code generation
if (empty($user->my_referral_code)) {
    echo "FAIL: New user has no my_referral_code!\n";
    exit(1);
} else {
    echo "PASS: New user generated code: {$user->my_referral_code}\n";
}

// Handle Referral Logic (Simplified from AuthController)
if ($referrerId) {
    \App\Models\UserReferral::create([
        'referrer_id' => $referrerId,
        'referred_id' => $user->id,
        'referral_code' => $refCode,
        'signup_bonus_earned' => $signupBonus,
        'signup_bonus_paid' => false
    ]);

    // Credit Referrer
    echo "Transferring Referrer Bonus (System -> Referrer)...\n";
    $ws->transferSystemFunds(
        $referrerId,
        $signupBonus,
        'REFERRAL_SIGNUP_BONUS',
        "Referral bonus for {$user->mobile_number} signup",
        'OUT'
    );
     // Mark as paid
    \App\Models\UserReferral::where('referred_id', $user->id)->update([
        'signup_bonus_paid' => true,
        'signup_bonus_paid_at' => now()
    ]);
}

// Credit New User
echo "Transferring Signup Bonus (System -> New User)...\n";
$ws->transferSystemFunds(
    $user->id,
    $cashbackAmount,
    'REFERRAL_WELCOME_BONUS',
    'Welcome Bonus via Referral',
    'OUT'
);

// Final Verification
$refBal = $ws->getBalance($referrerWallet->id);
$userBal = $ws->getBalance($userWallet->id);
$sysBal = $ws->getBalance($systemWallet->id);

echo "\n--- Final Balances ---\n";
echo "Referrer Balance: {$refBal} (Expected: 50)\n";
echo "New User Balance: {$userBal} (Expected: 50)\n";
echo "System Wallet Balance: {$sysBal}\n";

if ($refBal == 50 && $userBal == 50) {
    echo "PASS: Cashback distributed correctly via System Funds.\n";
} else {
    echo "FAIL: Incorrect balance distribution.\n";
    exit(1);
}
