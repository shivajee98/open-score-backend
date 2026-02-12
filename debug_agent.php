<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\SubUser;
use App\Models\User;
use App\Models\Loan;

$agents = SubUser::all();
foreach ($agents as $agent) {
    echo "Agent ID: {$agent->id} | Name: {$agent->name}\n";
    echo "  Limit: {$agent->credit_limit}\n";
    echo "  Balance: {$agent->credit_balance}\n";
    
    $userIds = User::where('sub_user_id', $agent->id)->pluck('id');
    $disbursed = Loan::whereIn('user_id', $userIds)
        ->whereIn('status', ['DISBURSED', 'CLOSED', 'OVERDUE'])
        ->sum('amount');
    
    echo "  Real Disbursed: {$disbursed}\n";
    echo "  Expected Balance (Limit - Disbursed): " . ($agent->credit_limit - $disbursed) . "\n";
    echo "--------------------------\n";
}
