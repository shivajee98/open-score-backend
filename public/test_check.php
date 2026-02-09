<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';

use App\Models\User;
use App\Models\ReferralSetting;

$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$code = 'MJQUJVQH';
$user = User::where('my_referral_code', $code)->first();

echo "Checking Referral Code: $code\n";
if ($user) {
    echo "Found Referrer: {$user->name} (ID: {$user->id})\n";
} else {
    echo "Referrer NOT found for code: $code\n";
    // Check all referral codes to be sure
    echo "Current Referral Codes in DB:\n";
    foreach (User::whereNotNull('my_referral_code')->get() as $u) {
        echo "- {$u->my_referral_code} ({$u->name})\n";
    }
}

$settings = ReferralSetting::first();
echo "\nRecent Referrals:\n";
$referrals = \App\Models\UserReferral::with(['referrer', 'referredUser'])->latest()->take(5)->get();
foreach ($referrals as $ref) {
    echo "- Referrer: {$ref->referrer->name} (ID: {$ref->referrer_id}) -> Referred: {$ref->referredUser->name} (ID: {$ref->referred_id}) | Bonus: {$ref->signup_bonus_earned} | Paid: " . ($ref->signup_bonus_paid ? 'Yes' : 'No') . "\n";
}
