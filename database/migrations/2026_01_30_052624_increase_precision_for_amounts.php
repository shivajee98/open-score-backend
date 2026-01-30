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
        Schema::table('loan_plans', function (Blueprint $table) {
            $table->decimal('amount', 20, 2)->change();
        });

        Schema::table('loans', function (Blueprint $table) {
            $table->decimal('amount', 20, 2)->change();
        });

        Schema::table('loan_repayments', function (Blueprint $table) {
            $table->decimal('amount', 20, 2)->change();
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->decimal('amount', 20, 2)->change();
        });

        Schema::table('merchant_cashback_tiers', function (Blueprint $table) {
            $table->decimal('min_turnover', 20, 2)->change();
            $table->decimal('max_turnover', 20, 2)->change();
        });

        Schema::table('merchant_cashbacks', function (Blueprint $table) {
            $table->decimal('daily_turnover', 20, 2)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // One way migration
    }
};
