<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\MerchantCashbackTier;

class MerchantCashbackTierSeeder extends Seeder
{
    public function run(): void
    {
        $tiers = [
            [
                'tier_name' => '1K-5K',
                'min_turnover' => 1000,
                'max_turnover' => 5000,
                'cashback_min' => 10,
                'cashback_max' => 50,
                'is_active' => true
            ],
            [
                'tier_name' => '5K-10K',
                'min_turnover' => 5000,
                'max_turnover' => 10000,
                'cashback_min' => 50,
                'cashback_max' => 200,
                'is_active' => true
            ],
            [
                'tier_name' => '10K-20K',
                'min_turnover' => 10000,
                'max_turnover' => 20000,
                'cashback_min' => 200,
                'cashback_max' => 400,
                'is_active' => true
            ],
            [
                'tier_name' => '20K-50K',
                'min_turnover' => 20000,
                'max_turnover' => 50000,
                'cashback_min' => 500,
                'cashback_max' => 1000,
                'is_active' => true
            ],
            [
                'tier_name' => '50K-1L',
                'min_turnover' => 50000,
                'max_turnover' => 100000,
                'cashback_min' => 1000,
                'cashback_max' => 2000,
                'is_active' => true
            ],
            [
                'tier_name' => '1L-2L',
                'min_turnover' => 100000,
                'max_turnover' => 200000,
                'cashback_min' => 2000,
                'cashback_max' => 4000,
                'is_active' => true
            ],
            [
                'tier_name' => '2L-5L',
                'min_turnover' => 200000,
                'max_turnover' => 500000,
                'cashback_min' => 3000,
                'cashback_max' => 5000,
                'is_active' => true
            ],
        ];

        foreach ($tiers as $tier) {
            MerchantCashbackTier::updateOrCreate(
                ['tier_name' => $tier['tier_name']],
                $tier
            );
        }

        $this->command->info('Merchant cashback tiers seeded successfully!');
    }
}
