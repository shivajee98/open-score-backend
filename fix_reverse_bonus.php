<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

DB::transaction(function() {
    // Delete incorrect signup bonus sub_user_transactions
    $deleted1 = App\Models\SubUserTransaction::where('sub_user_id', 4)
        ->whereIn('reference_id', ['USER_60', 'USER_61'])
        ->delete();
    echo "Deleted {$deleted1} sub_user_transactions\n";

    // Delete matching wallet_transactions (system wallet debits for agent commission)
    $deleted2 = DB::table('wallet_transactions')
        ->where('source_type', 'AGENT_COMMISSION')
        ->where('source_id', 4)
        ->where('description', 'like', '%Signup Reward%')
        ->delete();
    echo "Deleted {$deleted2} wallet_transactions\n";
    
    // Reset agent earnings
    $agent = App\Models\SubUser::find(4);
    $agent->earnings_balance = 0;
    $agent->save();
    echo "Reset Agent 4 earnings_balance to 0\n";
});

echo "DONE\n";
