<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('sub_user_id')->nullable()->after('referral_campaign_id');
            $table->foreign('sub_user_id')->references('id')->on('sub_users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['sub_user_id']);
            $table->dropColumn('sub_user_id');
        });
    }
};
