<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "Database: " . DB::connection()->getDatabaseName() . "\n";
echo "Host: " . DB::connection()->getConfig('host') . "\n";

try {
    $columns = DB::select('DESCRIBE qr_codes');
    foreach ($columns as $col) {
        echo json_encode($col) . "\n";
    }
} catch (\Exception $e) {
    echo "Error describing table: " . $e->getMessage() . "\n";
}
