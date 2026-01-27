<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "Database: " . DB::connection()->getDatabaseName() . "\n";
echo "Host: " . DB::connection()->getConfig('host') . "\n";

try {
    echo "Modifying user_id to be nullable...\n";
    DB::statement('ALTER TABLE qr_codes MODIFY user_id bigint(20) unsigned NULL');
    
    echo "Modifying wallet_id to be nullable...\n";
    DB::statement('ALTER TABLE qr_codes MODIFY wallet_id bigint(20) unsigned NULL');
    
    echo "Modifying code_data to be nullable...\n";
    DB::statement('ALTER TABLE qr_codes MODIFY code_data varchar(255) NULL');

    echo "Schema Constraints Fixed.\n";
} catch (\Exception $e) {
    echo "Error modifying table: " . $e->getMessage() . "\n";
}
