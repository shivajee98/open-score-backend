<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Services\WalletService;

class SupportAgentSeeder extends Seeder
{
    public function run()
    {
        $agents = [
            [
                'name' => 'Support Agent One',
                'email' => 'support1@openscore.com',
                'mobile_number' => '9000000001',
            ],
            [
                'name' => 'Support Agent Two',
                'email' => 'support2@openscore.com',
                'mobile_number' => '9000000002',
            ],
            [
                'name' => 'Support Agent Three',
                'email' => 'support3@openscore.com',
                'mobile_number' => '9000000003',
            ],
            [
                'name' => 'Support Agent Four',
                'email' => 'support4@openscore.com',
                'mobile_number' => '9000000004',
            ],
        ];

        foreach ($agents as $agent) {
            $user = User::firstOrCreate(
                ['mobile_number' => $agent['mobile_number']],
                [
                    'name' => $agent['name'],
                    'email' => $agent['email'],
                    'role' => 'ADMIN',
                    'status' => 'ACTIVE',
                    'is_onboarded' => true,
                    'password' => bcrypt('password'), // Not used but good to have
                ]
            );

            // Ensure they have a wallet (though admins might not need one, standard user flow might create it)
            // Using WalletService to be consistent
            $walletService = app(WalletService::class);
            if (!$user->wallet) {
                $walletService->createWallet($user->id);
            }
        }
    }
}
