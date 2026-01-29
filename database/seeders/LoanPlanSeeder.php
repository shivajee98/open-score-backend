<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\LoanPlan;

class LoanPlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Starter Plan (10K)
        LoanPlan::create([
            'name' => 'Starter Credit',
            'amount' => 10000,
            'configurations' => [
                [
                    'tenure_days' => 30,
                    'interest_rate' => 0,
                    'fees' => [
                        ['name' => 'Application Fee', 'amount' => 300],
                        ['name' => 'Field Verification', 'amount' => 500]
                    ],
                    'allowed_frequencies' => ['WEEKLY', 'MONTHLY'],
                    'cashback' => ['WEEKLY' => 0, 'MONTHLY' => 0]
                ]
            ],
            'plan_color' => 'bg-emerald-500',
            'tag_text' => 'Urgent',
            'is_active' => true,
        ]);

        // 2. Short Term (30K)
        LoanPlan::create([
            'name' => 'Growth Credit',
            'amount' => 30000,
            'configurations' => [
                [
                    'tenure_days' => 90, 
                    'interest_rate' => 0,
                    'fees' => [
                        ['name' => 'Processing Fee', 'amount' => 1200],
                        ['name' => 'Application Fee', 'amount' => 200],
                        ['name' => 'Field Verification', 'amount' => 600]
                    ],
                    'allowed_frequencies' => ['MONTHLY'],
                    'cashback' => ['MONTHLY' => 50]
                ]
            ],
            'plan_color' => 'bg-blue-500',
            'tag_text' => 'Best Value',
            'is_active' => true,
        ]);

        // 3. Medium Term (50K - 3 Months & 6 Months)
        LoanPlan::create([
            'name' => 'Business Plus',
            'amount' => 50000,
            'configurations' => [
                [
                    'tenure_days' => 90,
                    'interest_rate' => 6,
                    'fees' => [
                        ['name' => 'Processing Fee', 'amount' => 1200],
                        ['name' => 'Application Fee', 'amount' => 200],
                        ['name' => 'Field Verification', 'amount' => 600]
                    ],
                    'allowed_frequencies' => ['MONTHLY'],
                    'cashback' => ['MONTHLY' => 0]
                ],
                [
                    'tenure_days' => 180,
                    'interest_rate' => 12,
                    'fees' => [
                        ['name' => 'Processing Fee', 'amount' => 1500],
                        ['name' => 'Application Fee', 'amount' => 200],
                        ['name' => 'Field Verification', 'amount' => 600]
                    ],
                    'allowed_frequencies' => ['MONTHLY'],
                    'cashback' => ['MONTHLY' => 100]
                ]
            ],
            'plan_color' => 'bg-purple-500',
            'tag_text' => 'Popular',
            'is_active' => true,
        ]);

        // 4. Long Term (100K)
        LoanPlan::create([
            'name' => 'Enterprise Scale',
            'amount' => 100000,
            'configurations' => [
                [
                    'tenure_days' => 180,
                    'interest_rate' => 12,
                    'fees' => [
                        ['name' => 'Processing Fee', 'amount' => 2000],
                        ['name' => 'Consultation', 'amount' => 1000]
                    ],
                    'allowed_frequencies' => ['MONTHLY'],
                    'cashback' => ['MONTHLY' => 500]
                ]
            ],
            'plan_color' => 'bg-indigo-500',
            'tag_text' => 'Long Term',
            'is_active' => true,
        ]);
    }
}
