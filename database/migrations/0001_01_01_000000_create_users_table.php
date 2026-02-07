<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('mobile_number')->unique();
            $table->string('email')->nullable()->unique();
            $table->enum('role', ['CUSTOMER', 'MERCHANT', 'ADMIN', 'SUPPORT', 'SYSTEM'])->default('CUSTOMER');
            $table->string('business_name')->nullable();
            $table->string('profile_image')->nullable();
            $table->boolean('is_onboarded')->default(false);
            $table->string('status')->default('ACTIVE'); // ACTIVE, SUSPENDED
            $table->string('password')->nullable();
            $table->string('otp')->nullable();
            $table->timestamp('otp_expires_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('sessions');
    }
};
