<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

class TestDeploymentCycle extends Command
{
    protected $signature = 'test:deployment-cycle';
    protected $description = 'Comprehensive test for Admin, Referral, Cashback, and Loan features on hosted env.';

    protected $baseUrl = 'https://open-score-backend.onrender.com/api';
    protected $adminToken;
    protected $adminUser;

    public function handle()
    {
        $this->info("Starting Comprehensive Deployment Cycle Test...");

        // 1. Setup Admin
        $this->setupAdmin();

        // 2. Test Referral System
        $referralCode = $this->testReferralSystem();

        // 3. Test Merchant Onboarding & Cashback
        $merchant = $this->testMerchantOnboarding();

        // 4. Test Payment (Customer -> Merchant)
        $customer = $this->testPayment($referralCode, $merchant);

        // 5. Test Admin Analytics
        $this->testAdminAnalytics();

        $this->info("\nAll System Tests Passed Successfully! 🚀");
        return 0;
    }

    private function setupAdmin()
    {
        $this->info("\n[1] Setting up Admin...");
        // Fetch local admin to generate token? No, valid approach is finding local user
        // But we are hitting REMOTE API. We need a token that works on REMOTE.
        // Assuming the DB is shared or we can simulate login via OTP bypass for a known admin.
        
        // HACK: We will try to login as the known admin phone number using the OTP bypass
        // The previous test used local JWT generation ($adminToken = JWTAuth::fromUser($admin))
        // This implies the local code is running against the SAME DB as the Remote, 
        // OR the Remote accepts tokens signed by the same secret.
        // Given your previous test worked (Admin Proceeded), it implies Local JWT works on Remote 
        // OR you are running this against the REMOTE DB connection locally.
        
        // Let's rely on the local DB user finding method as per previous success.
        $this->adminUser = User::where('role', 'ADMIN')->first();
        if (!$this->adminUser) {
            $this->error("Local Admin user not found.");
            exit(1);
        }
        $this->adminToken = JWTAuth::fromUser($this->adminUser);
        $this->info("Authenticated as Admin: {$this->adminUser->name}");
    }

    private function testReferralSystem()
    {
        $this->info("\n[2] Testing Referral System...");
        
        // A. Create Campaign
        $code = 'TEST' . rand(1000, 9999);
        $amount = 500;
        
        $this->info("Creating Referral Campaign: {$code} with ₹{$amount} cashback");
        
        $response = Http::withToken($this->adminToken)->post("{$this->baseUrl}/admin/referrals", [
            'name' => "Automated Test Campaign {$code}",
            'code' => $code,
            'cashback_amount' => $amount
        ]);

        if ($response->failed()) {
            $this->error("Failed to create campaign: " . $response->body());
            exit(1);
        }
        $this->info("Campaign Created.");

        return $code;
    }

    private function testMerchantOnboarding()
    {
        $this->info("\n[3] Testing Merchant Onboarding...");

        // Generate Random Merchant
        $phone = '99' . rand(10000000, 99999999);
        $this->info("Registering new Merchant: {$phone}");

        // Login/Register
        $response = Http::post("{$this->baseUrl}/auth/verify", [
            'mobile_number' => $phone,
            'otp' => '123456', // Demo OTP
            'role' => 'MERCHANT'
        ]);

        if ($response->failed()) {
            $this->error("Merchant Registration Failed: " . $response->body());
            exit(1);
        }

        $data = $response->json();
        $token = $data['access_token'];
        $user = $data['user'];
        $this->info("Merchant Registered (ID: {$user['id']})");

        // Complete Profile
        $this->info("Completing Merchant Profile (Expecting ₹250 Bonus)...");
        $response = Http::withToken($token)->post("{$this->baseUrl}/auth/complete-merchant-profile", [
            'business_name' => 'Test Corp ' . rand(1,100),
            'business_nature' => 'Retail',
            'customer_segment' => 'General',
            'daily_turnover' => '1000-5000',
            'business_address' => '123 Test St',
            'pincode' => '800001',
            'pin' => '123456',
            'pin_confirmation' => '123456'
        ]);

        if ($response->failed()) {
            $this->error("Profile Completion Failed: " . $response->body());
            exit(1);
        }
        
        // Verify Wallet
        $check = Http::withToken($token)->get("{$this->baseUrl}/wallet/balance");
        $balance = $check->json()['balance'];
        
        if ($balance < 250) {
            $this->error("Merchant did not receive Onboarding Bonus! Balance: {$balance}");
            exit(1);
        }
        $this->info("Merchant Verified. Balance: ₹{$balance}.");

        return ['token' => $token, 'user' => $user, 'phone' => $phone];
    }

    private function testPayment($referralCode, $merchant)
    {
        $this->info("\n[4] Testing Customer Creation (Referral) & Payment...");

        // Generate Random Customer
        $phone = '88' . rand(10000000, 99999999);
        $this->info("Registering new Customer: {$phone} with code {$referralCode}");

        $response = Http::post("{$this->baseUrl}/auth/verify", [
            'mobile_number' => $phone,
            'otp' => '123456',
            'role' => 'CUSTOMER',
            'referral_code' => $referralCode
        ]);

        if ($response->failed()) {
            $this->error("Customer Registration Failed: " . $response->body());
            exit(1);
        }

        $data = $response->json();
        $token = $data['access_token'];
        $user = $data['user'];

        // Verify Referral Bonus
        $check = Http::withToken($token)->get("{$this->baseUrl}/wallet/balance");
        $balance = $check->json()['balance'];
        $this->info("Customer Balance: ₹{$balance}");

        if ($balance < 500) { // Should be 500 from referral
            $this->error("Referral Bonus Verification Failed! Expected 500, got {$balance}");
             // Not exiting, continuing to payment test if enough funds or if logic allows overdraft? 
             // Logic doesn't allow overdraft usually. But let's see. 
             // If validation failed, we cant pay.
             exit(1);
        }
        $this->info("Referral Bonus Verified!");
        
        // Set PIN for Customer
        Http::withToken($token)->post("{$this->baseUrl}/auth/set-pin", [
            'pin' => '123456',
            'pin_confirmation' => '123456'
        ]);

        // Pay Merchant
        $payAmount = 100;
        $this->info("Paying Merchant ₹{$payAmount}...");

        // We need payee_uuid or mobile+vpa?
        // PaymentController::pay expects { amount, description, payee_id (UUID) OR payee_mobile? }
        // Looking at routes... /payment/pay calls PaymentController::pay
        // Let's peek at PaymentController signature later or assume standard. 
        // Based on `listPayees` returning `wallet_uuid`, likely uses that or User ID?
        // Let's use `findPayee` first.
        
        // Wait briefly for indexing if needed (though local DB search should be instant)
        sleep(1);

        // Find Merchant to Pay
        $merchantVpa = $merchant['phone'] . '@openscore';
        $this->info("Searching for Payee: {$merchantVpa}");
        
        // 1. Try Search API
        $payeeLookup = Http::withToken($token)->get("{$this->baseUrl}/payment/search?query={$merchant['phone']}");
        $payeeData = $payeeLookup->json();
        
        $targetUuid = null;

        if (!empty($payeeData)) {
            // Find exact match
            foreach ($payeeData as $p) {
                if ($p['vpa'] === $merchantVpa) {
                    $targetUuid = $p['wallet_uuid'];
                    break;
                }
            }
        }
        
        // 2. If search fails, try direct findPayee logic if accessible (it is public via /payment/payee/{uuid} or similar? No, only findPayee/{id} exists)
        if (!$targetUuid) {
             $this->info("Search failed, trying direct VPA lookup endpoint...");
             $direct = Http::withToken($token)->get("{$this->baseUrl}/payment/payee/{$merchantVpa}");
             if ($direct->successful()) {
                 $targetUuid = $direct->json()['payee_wallet_uuid'];
             }
        }
        
        if (!$targetUuid) {
            $this->error("Could not find merchant wallet UUID.");
            // Debug info
            $this->info("Search Result: " . json_encode($payeeData));
            exit(1);
        }

        $payParams = [
            'amount' => $payAmount,
            'payee_wallet_uuid' => $targetUuid, // Parameter name fixed to match Controller validation
            'description' => 'Test Payment',
            'pin' => '123456'
        ];

        $payment = Http::withToken($token)->post("{$this->baseUrl}/payment/pay", $payParams);
        
        if ($payment->failed()) {
            $this->error("Payment Failed: " . $payment->body());
            // Try 'payee_uuid' instead? Or 'wallet_uuid'?
            // If failed, we might need to inspect PaymentController
            exit(1);
        }
        
        $this->info("Payment Successful!");
        
        return $user;
    }

    private function testAdminAnalytics()
    {
        $this->info("\n[5] Testing Admin Analytics...");
        
        $response = Http::withToken($this->adminToken)->get("{$this->baseUrl}/admin/analytics/dashboard");
        if ($response->failed()) {
            $this->error("Analytics Failed: " . $response->body());
            exit(1);
        }
        
        $stats = $response->json();
        $this->info("Dashboard Stats Loaded.");
        $this->info("Total Users: " . ($stats['total_users'] ?? 'N/A'));
        $this->info("Total Transactions: " . ($stats['total_transactions'] ?? 'N/A'));
        
        // Basic check
        if (!isset($stats['total_users'])) {
            $this->error("Invalid Analytics Structure");
            exit(1);
        }
        $this->info("Analytics Schema Verified.");
    }
}
