<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;

$user = User::find(56);
if ($user) {
    $user->sub_user_id = 4;
    $user->save();
    echo "User 56 linked to Agent 4\n";
} else {
    echo "User 56 not found\n";
}
