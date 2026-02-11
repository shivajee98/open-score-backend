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
        // Columns already exist
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loan_repayments', function (Blueprint $table) {
            $table->dropForeign(['agent_approved_by']);
            $table->dropColumn([
                'emi_number', 
                'unique_emi_id', 
                'transaction_id', 
                'agent_approved_at', 
                'agent_approved_by'
            ]);
        });
    }
};
