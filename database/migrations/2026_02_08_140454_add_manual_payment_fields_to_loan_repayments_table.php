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
            $table->string('proof_image')->nullable()->after('status');
            $table->string('payment_mode')->default('WALLET')->after('status'); // WALLET, UPI_MANUAL
            $table->text('admin_note')->nullable()->after('proof_image');
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
