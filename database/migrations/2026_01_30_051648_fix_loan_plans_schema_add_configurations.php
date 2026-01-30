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
            if (!Schema::hasColumn('loan_plans', 'configurations')) {
                $table->json('configurations')->nullable()->after('amount');
            }
            
            // Drop legacy columns if they exist
            $columnsToDrop = [];
            if (Schema::hasColumn('loan_plans', 'tenure_days')) $columnsToDrop[] = 'tenure_days';
            if (Schema::hasColumn('loan_plans', 'interest_rate')) $columnsToDrop[] = 'interest_rate';
            if (Schema::hasColumn('loan_plans', 'processing_fee')) $columnsToDrop[] = 'processing_fee';
            if (Schema::hasColumn('loan_plans', 'application_fee')) $columnsToDrop[] = 'application_fee';
            if (Schema::hasColumn('loan_plans', 'other_fee')) $columnsToDrop[] = 'other_fee';
            if (Schema::hasColumn('loan_plans', 'cashback_amount')) $columnsToDrop[] = 'cashback_amount';
            if (Schema::hasColumn('loan_plans', 'allowed_frequencies')) $columnsToDrop[] = 'allowed_frequencies';
            
            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }

    public function down(): void
    {
        // One way migration mainly
    }
};
