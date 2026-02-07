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
        $table->unsignedBigInteger('batch_id');
        $table->unsignedBigInteger('user_id')->nullable();
        $table->string('status')->default('active');
        $table->timestamps();
    });
    echo "qr_codes created.\n";
} else {
    echo "qr_codes exists. Harmonizing columns...\n";
    
    Schema::table('qr_codes', function (Blueprint $table) {
        // Drop legacy columns if they exist
        if (Schema::hasColumn('qr_codes', 'wallet_id')) {
            echo "Dropping legacy column: wallet_id\n";
            try { $table->dropForeign(['wallet_id']); } catch (\Exception $e) {}
            $table->dropColumn('wallet_id');
        }
        if (Schema::hasColumn('qr_codes', 'code_data')) {
            echo "Dropping legacy column: code_data\n";
            $table->dropColumn('code_data');
        }

        // Add missing columns
        if (!Schema::hasColumn('qr_codes', 'code')) {
            echo "Adding missing column: code\n";
            $table->uuid('code')->unique()->after('id');
        }
        if (!Schema::hasColumn('qr_codes', 'batch_id')) {
            echo "Adding missing column: batch_id\n";
            $table->unsignedBigInteger('batch_id')->after('code');
        }
        
        // Fix nullability
        if (Schema::hasColumn('qr_codes', 'user_id')) {
            echo "Making user_id nullable\n";
            $table->unsignedBigInteger('user_id')->nullable()->change();
        }
    });

    // Ensure foreign key
    Schema::table('qr_codes', function (Blueprint $table) {
        try {
            $table->foreign('batch_id')->references('id')->on('qr_batches')->onDelete('cascade');
            echo "Foreign key added.\n";
        } catch (\Exception $e) {
            echo "Foreign key check: already exists or failed.\n";
        }
    });

}

// 3. Fix Loans Table Defaults (the other error in logs)
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
