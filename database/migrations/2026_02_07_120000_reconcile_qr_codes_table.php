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
        Schema::table('qr_codes', function (Blueprint $table) {
            // 1. Drop legacy columns and their constraints if they exist
            if (Schema::hasColumn('qr_codes', 'wallet_id')) {
                try {
                    $table->dropForeign(['wallet_id']);
                } catch (\Exception $e) {}
                $table->dropColumn('wallet_id');
            }
            if (Schema::hasColumn('qr_codes', 'code_data')) {
                $table->dropColumn('code_data');
            }

            // 2. Add required columns if they are missing (fixing the Jan 27 no-op issue)
            if (!Schema::hasColumn('qr_codes', 'code')) {
                $table->uuid('code')->unique()->after('id');
            }
            if (!Schema::hasColumn('qr_codes', 'batch_id')) {
                $table->unsignedBigInteger('batch_id')->after('code');
            }
            
            // 3. Ensure user_id is nullable (it was required in Jan 25 migration)
            if (Schema::hasColumn('qr_codes', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->change();
            }
        });

        // Separate closure for foreign key to ensure batch_id exists first
        Schema::table('qr_codes', function (Blueprint $table) {
            try {
                // Check if the foreign key already exists to avoid errors
                // We'll just try to add it, if it fails it's usually because it already exists
                $table->foreign('batch_id')->references('id')->on('qr_batches')->onDelete('cascade');
            } catch (\Exception $e) {}
        });
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
