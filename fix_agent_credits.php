<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\SubUser;
use App\Models\User;
use App\Models\Loan;

echo "--- Fixing Agent Credit Balances ---\n";

$agents = SubUser::all();
foreach ($agents as $agent) {
    echo "Agent: {$agent->name} (ID: {$agent->id})\n";
    echo "  Initial Credit Balance: {$agent->credit_balance}\n";
    
    // Calculate total disbursed volume (money given out)
    $userIds = User::where('sub_user_id', $agent->id)->pluck('id');
    $disbursedVolume = Loan::whereIn('user_id', $userIds)
        ->whereIn('status', ['DISBURSED', 'CLOSED', 'OVERDUE']) // All loans that successfully released funds
        ->sum('amount');
        
    echo "  Total Released Volume: {$disbursedVolume}\n";
    
    if ($disbursedVolume > 0) {
        // We assume the current balance has NOT been reduced by these loans yet (as per bug report)
        $agent->decrement('credit_balance', $disbursedVolume);
        
        $agent->refresh();
        echo "  Updated Credit Balance: {$agent->credit_balance}\n";
    } else {
        echo "  No adjustments needed.\n";
    }
    echo "--------------------------------\n";
}
echo "Done.\n";
