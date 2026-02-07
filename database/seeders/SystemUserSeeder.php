<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Services\WalletService;

class SystemUserSeeder extends Seeder
{
    public function run()
    {
        // 1. Create or Get System User
        $user = User::firstOrCreate(
            ['email' => 'system@openscore.com'],
            [
                'name' => 'System Treasury',
                'mobile_number' => '0000000000',
                'role' => 'SYSTEM', // Assuming 'SYSTEM' role is allowed in enum
                'status' => 'ACTIVE',
                'is_onboarded' => true,
                'password' => bcrypt('system_secure_password_2026'),
            ]
        );

        // 2. Ensure Wallet Exists
        $walletService = app(WalletService::class);
        if (!$user->wallet) {
            $walletService->createWallet($user->id);
            $this->command->info('System Wallet Created.');
        } else {
            $this->command->info('System Wallet already exists.');
        }

        // 3. Optional: Seed Initial Float if empty?
        // Let's leave it 0. Admin can add funds.
    }
}
