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
            $table->enum('status', ['PENDING', 'COMPLETED', 'FAILED', 'REJECTED'])->default('COMPLETED')->after('amount');
            $table->index(['wallet_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropColumn('status');
            $table->dropIndex(['wallet_id', 'status']);
        });
    }
};
