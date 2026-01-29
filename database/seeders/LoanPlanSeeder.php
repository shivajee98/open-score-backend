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
        // Fees: 0 Processing, 300 Login, 500 Field
        LoanPlan::create([
            'name' => 'Starter Credit',
            'amount' => 10000,
            'tenure_days' => 30, // 1 Month
            'interest_rate' => 0, // 0%
            'processing_fee' => 0,
            'application_fee' => 300, // Login
            'other_fee' => 500, // Field KYC
            'repayment_frequency' => 'MONTHLY', // Or DAILY? The legacy logic calculated based on request. Let's assume defaults.
            // Wait, front end offers say "Urgent", "1 Month".
            // Backend logic defaults to monthly if not daily/weekly.
            // Let's create a few variations if we want, but for now match the "Offers" list.
            'cashback_amount' => 0,
            'plan_color' => 'bg-emerald-500',
            'tag_text' => 'Urgent',
            'is_active' => true,
        ]);

        // 2. Short Term (30K)
        // Fees: 1200 Processing, 200 Login, 600 Field (Standard for non-10k)
        LoanPlan::create([
            'name' => 'Growth Credit',
            'amount' => 30000,
            'tenure_days' => 90, // 3 Months
            'interest_rate' => 0,
            'processing_fee' => 1200,
            'application_fee' => 200,
            'other_fee' => 600,
            'repayment_frequency' => 'MONTHLY',
            'cashback_amount' => 0,
            'plan_color' => 'bg-blue-500',
            'tag_text' => 'Best Value',
            'is_active' => true,
        ]);

        // 3. Medium Term (50K - 3 Months)
        // Fees: Standard (1200+200+600)
        // Frontend says "16% One time if paid early" -> This might be 'interest' logic or 'fee' logic.
        // Frontend: "6% Monthly (3 Months)".
        LoanPlan::create([
            'name' => 'Business Plus',
            'amount' => 50000,
            'tenure_days' => 90, // 3 Months
            'interest_rate' => 6, // 6% Monthly
            'processing_fee' => 1200,
            'application_fee' => 200,
            'other_fee' => 600,
            'repayment_frequency' => 'MONTHLY',
            'cashback_amount' => 0,
            'plan_color' => 'bg-purple-500',
            'tag_text' => 'Popular',
            'is_active' => true,
        ]);

        // 4. Long Term (50K - 6 Months)
        LoanPlan::create([
            'name' => 'Enterprise Scale',
            'amount' => 50000,
            'tenure_days' => 180, // 6 Months
            'interest_rate' => 12, // 12% Monthly
            'processing_fee' => 1200,
            'application_fee' => 200,
            'other_fee' => 600,
            'repayment_frequency' => 'MONTHLY',
            'cashback_amount' => 0, // As per admin request, this can be set later
            'plan_color' => 'bg-indigo-500',
            'tag_text' => 'Long Term',
            'is_active' => true,
        ]);
    }
}
