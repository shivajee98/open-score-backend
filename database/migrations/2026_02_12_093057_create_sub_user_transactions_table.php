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
        Schema::table('sub_users', function (Blueprint $table) {
            $table->decimal('earnings_balance', 10, 2)->default(0)->after('credit_limit');
        });

        Schema::create('sub_user_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sub_user_id')->constrained('sub_users')->onDelete('cascade');
            $table->decimal('amount', 10, 2);
            $table->enum('type', ['CREDIT', 'DEBIT']);
            $table->string('description');
            $table->string('reference_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sub_user_transactions');
        
        Schema::table('sub_users', function (Blueprint $table) {
            $table->dropColumn('earnings_balance');
        });
    }
};
