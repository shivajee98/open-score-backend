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
        Schema::create('loan_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g., "Silver Plan"
            $table->decimal('amount', 10, 2);
            $table->integer('tenure_days'); // Total days. e.g. 30, 90
            $table->decimal('interest_rate', 5, 2)->default(0); // per month or flat? Let's assume flat rate for the tenure in the logic or monthly.
            // Based on frontend offers: "6% Monthly", "0% Interest". Let's store as Monthly Percentage for standardization
            
            // Fees
            $table->decimal('processing_fee', 10, 2)->default(0);
            $table->decimal('application_fee', 10, 2)->default(0); // Login Fee
            $table->decimal('other_fee', 10, 2)->default(0); // Field KYC / Other
            
            // Repayment
            $table->string('repayment_frequency'); // DAILY, WEEKLY, MONTHLY
            
            // UI & Logic
            $table->decimal('cashback_amount', 10, 2)->default(0);
            $table->string('plan_color')->default('bg-blue-500'); // CSS class for frontend
            $table->string('tag_text')->nullable(); // "Best Value", etc.
            
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_plans');
    }
};
