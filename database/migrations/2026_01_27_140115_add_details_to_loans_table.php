<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->integer('tenure')->after('amount'); // e.g., 3, 6, 12
            $table->string('payout_frequency')->after('tenure'); // e.g., 'Weekly', 'Daily'
            $table->string('payout_option_id')->nullable()->after('payout_frequency'); // e.g., 'daily_fixed_122'
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(['tenure', 'payout_frequency', 'payout_option_id']);
        });
    }
};
