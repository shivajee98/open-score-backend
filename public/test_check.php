<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';

use App\Models\User;
use App\Models\UserReferral;
use App\Models\ReferralSetting;
use App\Models\WalletTransaction;

$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

header('Content-Type: text/plain');

echo "=== DIAGNOSTICS ===\n\n";

// 1. Check Specific Referrer
$code = 'MJQUJVQH';
$referrer = User::where('my_referral_code', $code)->first();
if ($referrer) {
    echo "Referrer found: {$referrer->name} (ID: {$referrer->id})\n";
} else {
    echo "Referrer NOT found for code: $code\n";
}

// 2. Recent Users
echo "\nLast 5 Users:\n";
foreach (User::latest()->take(5)->get() as $u) {
    echo "- ID: {$u->id} | Phone: {$u->mobile_number} | Created: {$u->created_at}\n";
}

// 3. Recent Referrals
echo "\nLast 5 Referral Records:\n";
foreach (UserReferral::with(['referrer', 'referredUser'])->latest()->take(5)->get() as $ref) {
    $referrerName = $ref->referrer ? $ref->referrer->name : 'Unknown';
    $referredName = $ref->referredUser ? $ref->referredUser->name : 'Unknown';
    echo "- Referrer: $referrerName (ID: {$ref->referrer_id}) -> Referred: $referredName (ID: {$ref->referred_id}) | Paid: " . ($ref->signup_bonus_paid ? 'Yes' : 'No') . "\n";
}

// 4. Recent Wallet Transactions (Specific to Referrals)
echo "\nLast 5 Referral Bonus Transactions:\n";
foreach (WalletTransaction::where('source_type', 'REFERRAL_SIGNUP_BONUS')->latest()->take(5)->get() as $tx) {
    echo "- Wallet: {$tx->wallet_id} | Amount: {$tx->amount} | Desc: {$tx->description} | Time: {$tx->created_at}\n";
}

// 5. Check if Table exists
try {
    $count = UserReferral::count();
    echo "\nTotal Referral Records: $count\n";
} catch (\Exception $e) {
    echo "\nERROR accessing user_referrals table: " . $e->getMessage() . "\n";
}
