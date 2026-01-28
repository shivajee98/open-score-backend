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
        Schema::table('loans', function (Blueprint $table) {
            $table->json('form_data')->nullable()->after('payout_option_id');
            $table->timestamp('disbursed_at')->nullable()->after('approved_by');
            $table->foreignId('disbursed_by')->nullable()->constrained('users')->after('disbursed_at');
            $table->string('status')->change();
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(['form_data', 'disbursed_at', 'disbursed_by']);
        });
    }
};
