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
        Schema::table('referral_settings', function (Blueprint $table) {
            $table->decimal('agent_signup_bonus', 10, 2)->default(50.00)->after('is_enabled');
        });

        Schema::table('sub_users', function (Blueprint $table) {
             // We want this to be nullable so we can distinguish between "0" and "Default"
            $table->decimal('default_signup_amount', 10, 2)->nullable()->change();
        });

        Schema::table('loan_repayments', function (Blueprint $table) {
            $table->boolean('is_verified_by_agent')->default(false)->after('status');
            $table->unsignedBigInteger('verified_by_sub_user_id')->nullable()->after('is_verified_by_agent');
            $table->timestamp('agent_verified_at')->nullable()->after('verified_by_sub_user_id');
            $table->string('agent_verification_note')->nullable()->after('agent_verified_at');
            
            $table->foreign('verified_by_sub_user_id')->references('id')->on('sub_users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('referral_settings', function (Blueprint $table) {
            $table->dropColumn('agent_signup_bonus');
        });

        Schema::table('sub_users', function (Blueprint $table) {
            $table->decimal('default_signup_amount', 10, 2)->default(0)->change();
        });

        Schema::table('loan_repayments', function (Blueprint $table) {
            $table->dropForeign(['verified_by_sub_user_id']);
            $table->dropColumn(['is_verified_by_agent', 'verified_by_sub_user_id', 'agent_verified_at', 'agent_verification_note']);
        });
    }
};
