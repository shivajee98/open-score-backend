<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_batches', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->integer('count');
            $table->timestamps();
        });

        Schema::create('qr_codes', function (Blueprint $table) {
            $table->id();
            $table->uuid('code')->unique();
            $table->foreignId('batch_id')->constrained('qr_batches')->onDelete('cascade');
            $table->unsignedBigInteger('user_id')->nullable(); 
            // Manual constraint to allow for potential future flexibility or if users table id changes type, 
            // but standard is restricted. I'll stick to manual or standard. 
            // Given the users table is likely standard UI, I'll use constrained.
            // Actually, let's look at users table migration if I can, but I'll assume standard bigInt id.
             $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            
            $table->string('status')->default('active'); // active, assigned, inactive
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_codes');
        Schema::dropIfExists('qr_batches');
    }
};
