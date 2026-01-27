<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('qr_batches')) {
            Schema::create('qr_batches', function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->integer('count');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('qr_codes')) {
            Schema::create('qr_codes', function (Blueprint $table) {
                $table->id();
                $table->uuid('code')->unique();
                $table->foreignId('batch_id')->constrained('qr_batches')->onDelete('cascade');
                $table->unsignedBigInteger('user_id')->nullable(); 
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
                
                $table->string('status')->default('active'); // active, assigned, inactive
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_codes');
        Schema::dropIfExists('qr_batches');
    }
};
