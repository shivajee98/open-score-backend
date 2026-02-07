<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

// Bootstrap only if run directly
if (!defined('LARAVEL_START')) {
    require __DIR__ . '/vendor/autoload.php';
    $app = require_once __DIR__ . '/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
}

if (php_sapi_name() !== 'cli' && !defined('LARAVEL_START')) {
    header('Content-Type: text/plain');
}

echo "Checking Database Schema...\n";

// 1. Reset QR System (User wants all batches deleted and schema fixed)
echo "Resetting QR System tables...\n";
if (Schema::hasTable('qr_codes')) {
    Schema::drop('qr_codes');
}
if (Schema::hasTable('qr_batches')) {
    Schema::drop('qr_batches');
}

echo "Creating qr_batches table...\n";
Schema::create('qr_batches', function (Blueprint $table) {
    $table->id();
    $table->string('name')->nullable();
    $table->integer('count');
    $table->timestamps();
});

echo "Creating qr_codes table...\n";
Schema::create('qr_codes', function (Blueprint $table) {
    $table->id();
    $table->uuid('code')->unique();
    $table->foreignId('batch_id')->constrained('qr_batches')->onDelete('cascade');
    $table->unsignedBigInteger('user_id')->nullable();
    $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
    $table->string('status')->default('active');
    $table->timestamps();
});
echo "QR System Reset Complete.\n";

// 3. Fix Loans Table Defaults
if (Schema::hasTable('loans')) {
    echo "Fixing loans table defaults...\n";
    Schema::table('loans', function (Blueprint $table) {
        $table->integer('tenure')->default(30)->change();
        $table->string('payout_frequency')->default('MONTHLY')->change();
        $table->string('status')->default('PENDING')->change();
    });
    echo "Loans table fixed.\n";
}

// 4. Fix Loan Plans Table (Added is_locked)
if (Schema::hasTable('loan_plans')) {
    echo "Checking loan_plans table for is_locked column...\n";
    Schema::table('loan_plans', function (Blueprint $table) {
        if (!Schema::hasColumn('loan_plans', 'is_locked')) {
            echo "Adding is_locked column to loan_plans\n";
            $table->boolean('is_locked')->default(false)->after('is_public');
        }
    });
}

echo "Schema Fix Completed.\n";
