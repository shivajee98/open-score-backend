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
            $table->string('payment_mode')->nullable()->after('status'); // ONLINE, MANUAL
            $table->unsignedBigInteger('collected_by')->nullable()->after('payment_mode');
            $table->text('notes')->nullable()->after('collected_by');
            $table->string('proof_image')->nullable()->after('notes');
            $table->boolean('is_manual_collection')->default(false)->after('proof_image');
            
            $table->foreign('collected_by')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loan_repayments', function (Blueprint $table) {
            $table->dropForeign(['collected_by']);
            $table->dropColumn(['payment_mode', 'collected_by', 'notes', 'proof_image', 'is_manual_collection']);
        });
    }
};
