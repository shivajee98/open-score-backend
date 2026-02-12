<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

// Reverse the incorrectly granted signup bonuses for Agent 4
// These were signup-based bonuses - the new policy is cashback on disbursement only
DB::transaction(function() {
    // Delete the incorrect signup bonus transactions
    $deleted = App\Models\SubUserTransaction::where('sub_user_id', 4)
        ->whereIn('reference_id', ['USER_60', 'USER_61'])
        ->delete();
    
    echo "Deleted {$deleted} incorrect signup bonus transactions\n";
    
    // Reset agent earnings balance to 0
    $agent = App\Models\SubUser::find(4);
    $agent->earnings_balance = 0;
    $agent->save();
    
    echo "Reset Agent 4 earnings_balance to 0\n";
});

echo "DONE - Incorrect signup bonuses reversed\n";
