<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signup_cashback_settings', function (Blueprint $table) {
            $table->id();
            $table->enum('role', ['CUSTOMER', 'MERCHANT'])->unique();
            $table->decimal('cashback_amount', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        
        // Insert default values
        DB::table('signup_cashback_settings')->insert([
            ['role' => 'CUSTOMER', 'cashback_amount' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['role' => 'MERCHANT', 'cashback_amount' => 250, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('signup_cashback_settings');
    }
};
