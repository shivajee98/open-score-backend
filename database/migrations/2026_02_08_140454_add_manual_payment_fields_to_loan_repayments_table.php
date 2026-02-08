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
            if (!Schema::hasColumn('loan_repayments', 'proof_image')) {
                $table->string('proof_image')->nullable()->after('status');
            }
            if (!Schema::hasColumn('loan_repayments', 'payment_mode')) {
                $table->string('payment_mode')->default('WALLET')->after('status'); // WALLET, UPI_MANUAL
            }
            if (!Schema::hasColumn('loan_repayments', 'admin_note')) {
                $table->text('admin_note')->nullable()->after('proof_image');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loan_repayments', function (Blueprint $table) {
            $table->dropColumn(['proof_image', 'payment_mode', 'admin_note']);
        });
    }
};
