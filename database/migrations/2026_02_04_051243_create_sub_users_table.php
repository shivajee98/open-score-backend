<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sub_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('mobile_number')->unique();
            $table->string('email')->nullable();
            $table->string('password');
            $table->string('referral_code')->unique();
            $table->decimal('credit_balance', 10, 2)->default(0);
            $table->decimal('credit_limit', 10, 2)->default(0);
            $table->decimal('default_signup_amount', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sub_users');
    }
};
