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
        \Illuminate\Support\Facades\DB::statement("ALTER TABLE loans AUTO_INCREMENT = 4001;");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No easy way to reset it without shrinking the table, which we don't want.
    }
};
