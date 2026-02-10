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
        Schema::table('loan_repayments', function (Blueprint $table) {
            $table->integer('emi_number')->nullable()->after('loan_id');
            $table->string('unique_emi_id')->nullable()->unique()->after('emi_number');
            $table->string('transaction_id')->nullable()->after('amount');
            $table->timestamp('agent_approved_at')->nullable()->after('status');
            $table->unsignedBigInteger('agent_approved_by')->nullable()->after('agent_approved_at');
            
            $table->foreign('agent_approved_by')->references('id')->on('users')->onDelete('set null');
        });
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
