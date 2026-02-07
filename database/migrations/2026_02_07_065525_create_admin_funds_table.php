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
        Schema::create('admin_funds', function (Blueprint $table) {
            $table->id();
            $table->decimal('total_funds', 15, 2)->default(0);
            $table->decimal('available_funds', 15, 2)->default(0); // Cached/Derived
            $table->timestamps();
        });

        // Initialize with one record if not exists (Singleton pattern)
        DB::table('admin_funds')->insert([
            'total_funds' => 0,
            'available_funds' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_funds');
    }
};
