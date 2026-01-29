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
            $table->dropColumn('repayment_frequency');
            $table->json('allowed_frequencies')->nullable()->after('other_fee');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loan_plans', function (Blueprint $table) {
            $table->dropColumn('allowed_frequencies');
            $table->string('repayment_frequency')->default('MONTHLY');
        });
    }
};
