<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Merchant cashback configurations
        Schema::create('merchant_cashback_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('tier_name'); // e.g., "1K-5K"
            $table->decimal('min_turnover', 15, 2); // 1000
            $table->decimal('max_turnover', 15, 2); // 5000
            $table->decimal('cashback_min', 10, 2); // 10
            $table->decimal('cashback_max', 10, 2); // 50
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Individual merchant cashback records
        Schema::create('merchant_cashbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('tier_id')->nullable()->constrained('merchant_cashback_tiers')->onDelete('set null');
            $table->decimal('daily_turnover', 15, 2)->default(0);
            $table->decimal('cashback_amount', 10, 2);
            $table->date('cashback_date');
            $table->enum('status', ['PENDING', 'APPROVED', 'PAID', 'REJECTED'])->default('PENDING');
            $table->text('notes')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            
            $table->index(['merchant_id', 'cashback_date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_cashbacks');
        Schema::dropIfExists('merchant_cashback_tiers');
    }
};
