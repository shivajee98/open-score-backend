<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SupportCategory;

class SupportSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $categories = [
            [
                'name' => 'Cashback Not Received',
                'slug' => 'cashback_issue',
                'permissions' => ['view_profile', 'view_transaction', 'add_cashback'],
            ],
            [
                'name' => 'Unable To Transfer & Approve My Emi / Payment',
                'slug' => 'transfer_emi_issue',
                'permissions' => ['view_profile', 'view_transaction', 'approve_emi'],
            ],
            [
                'name' => 'Loan / KYC / Payment / Other',
                'slug' => 'loan_kyc_other',
                'permissions' => ['view_profile', 'view_transaction', 'approve_loan', 'update_kyc'],
            ],
        ];

        foreach ($categories as $cat) {
            SupportCategory::updateOrCreate(
                ['slug' => $cat['slug']], // Check by slug to avoid duplicates
                [
                    'name' => $cat['name'],
                    'permissions' => $cat['permissions']
                ]
            );
        }
    }
}
