<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payer_wallet_id')->constrained('wallets');
            $table->foreignId('payee_wallet_id')->constrained('wallets');
            $table->decimal('amount', 15, 2);
            $table->string('status')->default('COMPLETED');
            $table->string('transaction_ref')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
