<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Str;
use Database\Seeders\AdminSeeder; // Added this line
use Database\Seeders\SupportSeeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AdminSeeder::class,
            SupportSeeder::class,
        ]);

        // Admin
        $admin = User::create([
            'name' => 'System Admin',
            'mobile_number' => 'admin',
            'role' => 'ADMIN',
            'password' => bcrypt('password'),
        ]);

        // Merchant
        $merchant = User::create([
            'name' => 'Starbucks Coffee',
            'mobile_number' => 'merchant',
            'role' => 'MERCHANT',
            'password' => bcrypt('password'),
        ]);

        // Customer
        $customer = User::create([
            'name' => 'Demo Customer',
            'mobile_number' => 'customer',
            'role' => 'CUSTOMER',
            'password' => bcrypt('password'),
        ]);

        // Create wallets for them
        foreach (User::all() as $user) {
            Wallet::create([
                'user_id' => $user->id,
                'uuid' => (string) Str::uuid(),
                'status' => 'ACTIVE'
            ]);
        }

        // Demo Agent (Sub-User)
        \App\Models\SubUser::create([
            'name' => 'Demo Agent',
            'mobile_number' => '8888888888',
            'email' => 'agent@demo.com',
            'password' => bcrypt('password'),
            'referral_code' => 'DEMOAGENT',
            'credit_balance' => 0,
            'credit_limit' => 50000,
            'default_signup_amount' => 250,
            'is_active' => true
        ]);
    }
}
