<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('wallets')->onDelete('cascade');
            $table->enum('type', ['CREDIT', 'DEBIT']);
            $table->decimal('amount', 15, 2);
            $table->string('source_type'); // LOAN, QR_PAYMENT, WITHDRAW, ADMIN
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
            
            $table->index(['wallet_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
