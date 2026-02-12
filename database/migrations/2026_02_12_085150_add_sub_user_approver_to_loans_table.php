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
        Schema::table('loans', function (Blueprint $table) {
            $table->unsignedBigInteger('approved_by_sub_user_id')->nullable()->after('approved_by');
            // Assuming sub_users table exists
            $table->foreign('approved_by_sub_user_id')->references('id')->on('sub_users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropForeign(['approved_by_sub_user_id']);
            $table->dropColumn('approved_by_sub_user_id');
        });
    }
};
