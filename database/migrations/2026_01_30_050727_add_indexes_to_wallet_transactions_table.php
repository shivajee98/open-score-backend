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
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->index(['wallet_id', 'status', 'type'], 'idx_balance_calc');
            $table->index(['wallet_id', 'source_type', 'created_at'], 'idx_turnover_calc');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropIndex('idx_balance_calc');
            $table->dropIndex('idx_turnover_calc');
        });
    }
};
