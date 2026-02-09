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
        Schema::table('support_tickets', function (Blueprint $table) {
            if (!Schema::hasColumn('support_tickets', 'assigned_to')) {
                $table->foreignId('assigned_to')->nullable()->constrained('users')->onDelete('set null');
            }
            if (!Schema::hasColumn('support_tickets', 'issue_type')) {
                $table->string('issue_type')->nullable()->after('subject');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            if (Schema::hasColumn('support_tickets', 'assigned_to')) {
                $table->dropForeign(['assigned_to']);
                $table->dropColumn(['assigned_to']);
            }
            if (Schema::hasColumn('support_tickets', 'issue_type')) {
                $table->dropColumn(['issue_type']);
            }
        });
    }
};
