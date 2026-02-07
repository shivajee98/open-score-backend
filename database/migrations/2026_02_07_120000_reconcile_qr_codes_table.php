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
            // Drop legacy columns if they exist
            if (Schema::hasColumn('qr_codes', 'code_data')) {
                $table->dropColumn('code_data');
            }
            if (Schema::hasColumn('qr_codes', 'wallet_id')) {
                // Check if index exists is tricky, but we know it's there from the error
                try {
                    $table->dropForeign(['wallet_id']);
                } catch (\Exception $e) {
                    // Ignore if already dropped
                }
                $table->dropColumn('wallet_id');
            }
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
