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
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('support_category_id')->nullable()->constrained('support_categories')->nullOnDelete();
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->constrained('support_categories')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['support_category_id']);
            $table->dropColumn('support_category_id');
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');
        });
    }
};
