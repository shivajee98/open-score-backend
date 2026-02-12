<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$r = app(App\Services\ReferralService::class);
$su = App\Models\SubUser::find(4);

$u60 = App\Models\User::find(60);
$r->grantAgentSignupBonus($su, $u60);
echo "Bonus granted for User 60\n";

$u61 = App\Models\User::find(61);
$r->grantAgentSignupBonus($su, $u61);
echo "Bonus granted for User 61\n";

echo "DONE\n";
