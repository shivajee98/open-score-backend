<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\LoanPlan;

class NewLoanPlansSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Enterprise Suite (100K)
        LoanPlan::create([
            'name' => 'Enterprise Suite',
            'amount' => 100000,
            'configurations' => [
                [
                    'tenure_days' => 180,
                    'interest_rate' => 12,
                    'fees' => [
                        ['name' => 'Processing Fee', 'amount' => 2500],
                        ['name' => 'Application Fee', 'amount' => 500],
                        ['name' => 'Legal Fee', 'amount' => 1000]
                    ],
                    'allowed_frequencies' => ['WEEKLY', 'MONTHLY'],
                    'cashback' => ['WEEKLY' => 200, 'MONTHLY' => 500]
                ],
                [
                    'tenure_days' => 365,
                    'interest_rate' => 15,
                    'fees' => [
                        ['name' => 'Processing Fee', 'amount' => 3000],
                        ['name' => 'Legal Fee', 'amount' => 1000]
                    ],
                    'allowed_frequencies' => ['MONTHLY'],
                    'cashback' => ['MONTHLY' => 1000]
                ]
            ],
            'plan_color' => 'bg-indigo-600',
            'tag_text' => 'Corporate Choice',
            'is_active' => true,
            'is_public' => true,
            'is_locked' => true,
        ]);

        // 2. Elite Business (200K)
        LoanPlan::create([
            'name' => 'Elite Business',
            'amount' => 200000,
            'configurations' => [
                [
                    'tenure_days' => 180,
                    'interest_rate' => 10,
                    'fees' => [
                        ['name' => 'Processing Fee', 'amount' => 5000],
                        ['name' => 'Application Fee', 'amount' => 500],
                        ['name' => 'Insurance Fee', 'amount' => 2000]
                    ],
                    'allowed_frequencies' => ['WEEKLY', 'MONTHLY'],
                    'cashback' => ['WEEKLY' => 500, 'MONTHLY' => 1000]
                ],
                [
                    'tenure_days' => 365,
                    'interest_rate' => 14,
                    'fees' => [
                        ['name' => 'Processing Fee', 'amount' => 6000],
                        ['name' => 'Insurance Fee', 'amount' => 2500]
                    ],
                    'allowed_frequencies' => ['MONTHLY'],
                    'cashback' => ['MONTHLY' => 2000]
                ]
            ],
            'plan_color' => 'bg-slate-900',
            'tag_text' => 'Premium Elite',
            'is_active' => true,
            'is_public' => true,
            'is_locked' => true,
        ]);

        // 3. Mega Scale (500K)
        LoanPlan::create([
            'name' => 'Mega Scale',
            'amount' => 500000,
            'configurations' => [
                [
                    'tenure_days' => 365,
                    'interest_rate' => 12,
                    'fees' => [
                        ['name' => 'Processing Fee', 'amount' => 10000],
                        ['name' => 'Consulting Fee', 'amount' => 5000],
                        ['name' => 'Field Verification', 'amount' => 2000]
                    ],
                    'allowed_frequencies' => ['MONTHLY'],
                    'cashback' => ['MONTHLY' => 5000]
                ],
                [
                    'tenure_days' => 730,
                    'interest_rate' => 18,
                    'fees' => [
                        ['name' => 'Processing Fee', 'amount' => 15000],
                        ['name' => 'Consulting Fee', 'amount' => 5000]
                    ],
                    'allowed_frequencies' => ['MONTHLY'],
                    'cashback' => ['MONTHLY' => 10000]
                ]
            ],
            'plan_color' => 'bg-amber-600',
            'tag_text' => 'Maximum Scalability',
            'is_active' => true,
            'is_public' => true,
            'is_locked' => true,
        ]);
    }
}
