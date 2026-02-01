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
        Schema::create('withdrawal_rules', function (Blueprint $table) {
            $table->id();
            // Link to a specific loan plan (e.g., Bronze, Silver). 
            // Nullable if we ever want global features, but currently used for per-loan rules.
            $table->foreignId('loan_plan_id')->nullable()->constrained('loan_plans')->onDelete('cascade');
            
            $table->enum('user_type', ['MERCHANT', 'CUSTOMER']);
            
            // Unlocking Conditions
            $table->decimal('min_spend_amount', 15, 2)->default(0); // e.g., Spend 30% of loan (stored as absolute value or calculated dynamically? User said "set amounts". Let's assume absolute amounts for now as per "Spend 50k" example, but if logic differs, we'll adjust. Actually, user said "spending/transaction targets". If it's per plan, absolute amount makes sense.)
            $table->integer('min_txn_count')->default(0); // e.g., 10 transactions
            
            // Daily Cap
            $table->decimal('daily_limit', 15, 2)->nullable(); // e.g., Max 1000 per day
            
            // Targeting: Specific Users or "*" for all
            $table->json('target_users')->nullable(); 
            
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('withdrawal_rules');
    }
};
