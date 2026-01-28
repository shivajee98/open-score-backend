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
        Schema::table('users', function (Blueprint $blueprint) {
            $blueprint->string('business_nature')->nullable();
            $blueprint->string('customer_segment')->nullable();
            $blueprint->string('daily_turnover')->nullable();
            $blueprint->text('business_address')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $blueprint) {
            $blueprint->dropColumn(['business_nature', 'customer_segment', 'daily_turnover', 'business_address']);
        });
    }
};
