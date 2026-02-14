<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Change enum to string to support more roles
        Schema::table('signup_cashback_settings', function (Blueprint $table) {
            $table->string('role')->change();
        });

        // Insert STUDENT cashback setting if it doesn't exist
        $exists = DB::table('signup_cashback_settings')->where('role', 'STUDENT')->exists();
        
        if (!$exists) {
            DB::table('signup_cashback_settings')->insert([
                'role' => 'STUDENT',
                'cashback_amount' => 50.00, // Default to same as Customer
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }
    }

    public function down(): void
    {
        DB::table('signup_cashback_settings')->where('role', 'STUDENT')->delete();
        // We generally don't revert the column type change to avoid data loss on other custom roles if added
    }
};
