<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Checking Database Schema...\n";

// 1. Check qr_batches table
if (!Schema::hasTable('qr_batches')) {
    echo "Creating qr_batches table...\n";
    Schema::create('qr_batches', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->integer('count');
        $table->timestamps();
    });
    echo "qr_batches created.\n";
} else {
    echo "qr_batches exists.\n";
}

// 2. Check qr_codes table
if (!Schema::hasTable('qr_codes')) {
    echo "Creating qr_codes table...\n";
    Schema::create('qr_codes', function (Blueprint $table) {
        $table->id();
        $table->uuid('code')->unique();
        $table->unsignedBigInteger('batch_id'); // Explicitly adding
        $table->unsignedBigInteger('user_id')->nullable();
        $table->string('status')->default('active');
        $table->timestamps();
    });
    echo "qr_codes created.\n";
} else {
    echo "qr_codes exists. Checking columns...\n";
    
    Schema::table('qr_codes', function (Blueprint $table) {
        if (!Schema::hasColumn('qr_codes', 'batch_id')) {
            echo "Adding missing column: batch_id\n";
            $table->unsignedBigInteger('batch_id')->after('id');
        } else {
            echo "Column batch_id exists.\n";
        }

        if (!Schema::hasColumn('qr_codes', 'code')) {
            echo "Adding missing column: code\n";
            $table->uuid('code')->unique()->after('id');
        }
    });
}
echo "Schema Fix Completed.\n";
