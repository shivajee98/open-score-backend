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
        Schema::create('sub_user_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sub_user_id')->constrained('sub_users')->onDelete('cascade');
            $table->decimal('amount', 15, 2);
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED'])->default('PENDING');
            $table->string('bank_details')->nullable(); // JSON or text summary
            $table->text('admin_message')->nullable(); // Message from admin
            $table->string('proof_image')->nullable(); // Uploaded transaction proof
            $table->foreignId('processed_by')->nullable()->constrained('users')->onDelete('set null'); // Admin ID
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sub_user_payouts');
    }
};
