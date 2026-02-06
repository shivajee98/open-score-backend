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
        // Referral Settings Table
        Schema::create('referral_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_enabled')->default(true);
            $table->decimal('signup_bonus', 10, 2)->default(100.00);
            $table->decimal('loan_disbursement_bonus', 10, 2)->default(250.00);
            $table->timestamps();
        });

        // Insert default settings
        DB::table('referral_settings')->insert([
            'is_enabled' => true,
            'signup_bonus' => 100.00,
            'loan_disbursement_bonus' => 250.00,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // User Referrals Table - Track who referred whom
        Schema::create('user_referrals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('referrer_id'); // User who referred
            $table->unsignedBigInteger('referred_id'); // User who was referred
            $table->string('referral_code')->nullable(); // Code used
            $table->decimal('signup_bonus_earned', 10, 2)->default(0);
            $table->boolean('signup_bonus_paid')->default(false);
            $table->timestamp('signup_bonus_paid_at')->nullable();
            $table->decimal('loan_bonus_earned', 10, 2)->default(0);
            $table->boolean('loan_bonus_paid')->default(false);
            $table->timestamp('loan_bonus_paid_at')->nullable();
            $table->timestamps();

            $table->foreign('referrer_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('referred_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique('referred_id'); // A user can only be referred once
            $table->index('referrer_id');
        });

        // Add referral code to users table
        Schema::table('users', function (Blueprint $table) {
            $table->string('my_referral_code', 20)->unique()->nullable()->after('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('my_referral_code');
        });

        Schema::dropIfExists('user_referrals');
        Schema::dropIfExists('referral_settings');
    }
};
