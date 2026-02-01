<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WithdrawalRule;
use Illuminate\Support\Facades\Http;
use Tymon\JWTAuth\Facades\JWTAuth;

class TestWithdrawalCycle extends Command
{
    protected $signature = 'test:withdrawal-cycle';
    protected $description = 'Test withdrawal eligibility logic, admin payout listing, and logs.';
    protected $baseUrl = 'https://open-score-backend.onrender.com/api';
    protected $adminToken;

    public function handle()
    {
        $this->info("Starting Withdrawal Cycle Test...");

        // 1. Setup Admin
        $this->adminToken = JWTAuth::fromUser(User::where('role', 'ADMIN')->firstOrFail());
        $this->info("Admin Authenticated.");

        // 2. Cleanup old rules/requests if needed? No, let's keep history but maybe add a generous rule for testing eligibility?
        // Let's create a User with money.
        $user = $this->createTestUser();
        $this->info("Test User Created: {$user->mobile_number} (Balance: 20000)");

        // 3. Test 1: Successful Withdrawal (Eligible)
        $this->info("\n[1] Testing Eligible Withdrawal...");
        $amount = 1000;
        $response = Http::withToken($user->token)->post("{$this->baseUrl}/wallet/request-withdrawal", [
            'amount' => $amount,
            'bank_name' => 'Test Bank',
            'account_number' => '1234567890',
            'ifsc_code' => 'TEST0001',
            'account_holder_name' => 'Test User'
        ]);

        if ($response->failed()) {
            $this->error("Eligible Request Failed: " . $response->body());
        } else {
            $this->info("Request Success: " . $response->status());
        }

        // 4. Test 2: Verify Admin List (Fixes undefined relationship bug)
        $this->info("\n[2] Checking Admin Payouts List...");
        $payouts = Http::withToken($this->adminToken)->get("{$this->baseUrl}/admin/payouts");
        
        if ($payouts->failed()) {
            $this->error("Admin Payouts List Failed: " . $payouts->body());
        } else {
            $data = $payouts->json();
            $count = count($data);
            $this->info("Admin Payouts List Working. Found {$count} requests.");
            
            // Log structure to ensure user relationship is present
            // $this->info(json_encode($data[0]));
        }

        // 5. Test 3: Ineligible Withdrawal (Create Restrictive Rule)
        $this->info("\n[3] Testing Ineligible Withdrawal (Rule Blocked)...");
        
        // Create rule blocking user
        $ruleValues = [
            'rule_name' => 'Test Block',
            'user_type' => 'CUSTOMER',
            'daily_limit' => 10, // Very low limit
            'target_users' => [(string)$user->id],
            'is_active' => true,
            'loan_plan_id' => null
        ];
        
        $ruleRes = Http::withToken($this->adminToken)->post("{$this->baseUrl}/admin/withdrawal-rules", $ruleValues);
        if ($ruleRes->failed()) $this->warn("Failed to create rule: " . $ruleRes->body());
        $ruleId = $ruleRes->json()['id'] ?? null;
        
        sleep(1); // potential cache propagation
        
        // Attempt withdrawal > 10
        $response2 = Http::withToken($user->token)->post("{$this->baseUrl}/wallet/request-withdrawal", [
            'amount' => 500,
            'bank_name' => 'Test Bank',
            'account_number' => '1234567890',
            'ifsc_code' => 'TEST0001',
            'account_holder_name' => 'Test User'
        ]);

        if ($response2->status() === 400) {
            $this->info("Blocked Correctly: " . $response2->json()['error'] ?? 'Unknown Error');
        } else {
            $this->error("Failed to Block! Status: " . $response2->status());
        }

        // Cleanup Rule
        if ($ruleId) {
             Http::withToken($this->adminToken)->delete("{$this->baseUrl}/admin/withdrawal-rules/{$ruleId}");
        }

        // 6. Test Logs Endpoint
        $this->info("\n[4] Testing Admin Logs Endpoint...");
        $logs = Http::withToken($this->adminToken)->get("{$this->baseUrl}/admin/logs");
        if ($logs->status() === 404) {
             $this->error("Logs Endpoint returned 404! Checked: /admin/logs");
        } elseif ($logs->failed()) {
             $this->error("Logs Endpoint Failed: " . $logs->body());
        } else {
             $this->info("Logs Endpoint Working. Found " . count($logs->json()) . " entries.");
        }

        return 0;
    }

    private function createTestUser()
    {
        // 1. Create/Find User
        $mobile = '77' . rand(10000000, 99999999);
        $user = User::create([
            'name' => 'Withdraw Tester',
            'mobile_number' => $mobile,
            'role' => 'CUSTOMER',
            'status' => 'ACTIVE',
            'password' => bcrypt('password')
        ]);
        
        $token = JWTAuth::fromUser($user);
        
        // 2. Fund Wallet
        $ws = app(\App\Services\WalletService::class);
        $wallet = $ws->createWallet($user->id);
        $ws->credit($wallet->id, 20000, 'DEPOSIT', $user->id, 'Test Funds');

        $user->token = $token;
        return $user;
    }
}
