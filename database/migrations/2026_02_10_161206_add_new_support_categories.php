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
        \DB::table('support_categories')->insert([
            [
                'name' => 'Wallet Top-up',
                'slug' => 'wallet_topup',
                'permissions' => json_encode(['view', 'reply', 'approve_payment']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Services',
                'slug' => 'services',
                'permissions' => json_encode(['view', 'reply']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        \DB::table('support_categories')->whereIn('slug', ['wallet_topup', 'services'])->delete();
    }
};
