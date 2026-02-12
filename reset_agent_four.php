<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\SubUser;

$agent = SubUser::find(4);
echo "Agent 4 Current Balance: {$agent->credit_balance}\n";
$agent->credit_balance = 0;
$agent->save();
echo "Reset Agent 4 Credit Balance to 0.\n";
