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
            $table->string('unique_ticket_id')->nullable()->unique()->after('id');
            $table->string('payment_status')->nullable()->after('status'); // PENDING_VERIFICATION, AGENT_APPROVED, ADMIN_APPROVED, REJECTED
            $table->decimal('payment_amount', 12, 2)->nullable()->after('payment_status');

            $table->timestamp('agent_approved_at')->nullable();
            $table->unsignedBigInteger('agent_approved_by')->nullable();
            $table->timestamp('admin_approved_at')->nullable();
            $table->unsignedBigInteger('admin_approved_by')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->foreign('agent_approved_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('admin_approved_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropForeign(['agent_approved_by']);
            $table->dropForeign(['admin_approved_by']);
            $table->dropColumn([
                'unique_ticket_id',
                'payment_status',
                'payment_amount',
                'agent_approved_at',
                'agent_approved_by',
                'admin_approved_at',
                'admin_approved_by',
                'rejection_reason',
            ]);
        });
    }
};
