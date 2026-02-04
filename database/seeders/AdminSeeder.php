<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Services\WalletService;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $mobile = '999999999';
        
        $admin = User::firstOrCreate(
            ['mobile_number' => $mobile],
            [
                'name' => 'Super Admin',
                'email' => 'admin@openscore.com',
                'role' => 'ADMIN',
                'status' => 'ACTIVE',
                'is_onboarded' => true,
                'password' => Hash::make('password'), // Not used with OTP flow but required
            ]
        );

        // Ensure role is ADMIN if user already existed
        if ($admin->role !== 'ADMIN') {
            $admin->role = 'ADMIN';
            $admin->save();
        }

        // Create Wallet if missing
        $walletService = app(WalletService::class);
        if (!$walletService->getWallet($admin->id)) {
            $walletService->createWallet($admin->id);
        }
    }
}
