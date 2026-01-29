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
            $table->dropColumn([
                'tenure_days',
                'interest_rate',
                'processing_fee',
                'application_fee',
                'other_fee',
                'allowed_frequencies',
                'cashback_amount'
            ]);
            $table->json('configurations')->nullable()->after('amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loan_plans', function (Blueprint $table) {
            $table->dropColumn('configurations');
            $table->integer('tenure_days')->default(30);
            $table->decimal('interest_rate', 5, 2)->default(0);
            $table->decimal('processing_fee', 8, 2)->default(0);
            $table->decimal('application_fee', 8, 2)->default(0);
            $table->decimal('other_fee', 8, 2)->default(0);
            $table->json('allowed_frequencies')->nullable();
            $table->decimal('cashback_amount', 8, 2)->default(0);
        });
    }
};
