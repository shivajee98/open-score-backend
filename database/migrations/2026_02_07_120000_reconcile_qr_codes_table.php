<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Drop legacy columns and their constraints
        Schema::table('qr_codes', function (Blueprint $table) {
            if (Schema::hasColumn('qr_codes', 'wallet_id')) {
                try {
                    $table->dropForeign(['wallet_id']);
                } catch (\Exception $e) {}
                $table->dropColumn('wallet_id');
            }
        });

        Schema::table('qr_codes', function (Blueprint $table) {
            if (Schema::hasColumn('qr_codes', 'code_data')) {
                // SQLite fix: drop index before dropping column
                try {
                    $table->dropUnique('qr_codes_code_data_unique');
                } catch (\Exception $e) {}
                $table->dropColumn('code_data');
            }
        });

        // 2. Add required columns
        Schema::table('qr_codes', function (Blueprint $table) {
            if (!Schema::hasColumn('qr_codes', 'code')) {
                $table->uuid('code')->unique()->after('id');
            }
        });

        Schema::table('qr_codes', function (Blueprint $table) {
            if (!Schema::hasColumn('qr_codes', 'batch_id')) {
                $table->unsignedBigInteger('batch_id')->after('code');
            }
        });
        
        // 3. Ensure user_id is nullable
        Schema::table('qr_codes', function (Blueprint $table) {
            if (Schema::hasColumn('qr_codes', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->change();
            }
        });

        // Separate closure for foreign key to ensure batch_id exists first
        // Check if the foreign key already exists to avoid Errno 121 "Duplicate key on write or update"
        $foreignKeyExists = false;
        try {
            $results = DB::select("
                SELECT CONSTRAINT_NAME 
                FROM information_schema.KEY_COLUMN_USAGE 
                WHERE TABLE_NAME = 'qr_codes' 
                AND CONSTRAINT_NAME = 'qr_codes_batch_id_foreign'
                AND TABLE_SCHEMA = DATABASE()
            ");
            $foreignKeyExists = count($results) > 0;
        } catch (\Exception $e) {
            // If we can't check, we'll try to add it and catch the error during execution
        }

        if (!$foreignKeyExists) {
            Schema::table('qr_codes', function (Blueprint $table) {
                try {
                    $table->foreign('batch_id')->references('id')->on('qr_batches')->onDelete('cascade');
                } catch (\Exception $e) {
                    // Final fallback
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('qr_codes', function (Blueprint $table) {
            $table->string('code_data')->nullable()->unique();
            $table->unsignedBigInteger('wallet_id')->nullable();
        });
    }
};
