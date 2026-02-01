<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\LoanPlan;
use App\Models\Loan;
use App\Models\Wallet;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

class TestLoanCycle extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:loan-cycle';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automated test for loan creation, approval, and full repayment cycle for all tenures/frequencies.';

    // Config
    protected $targetPhone = '9430083275';
    protected $baseUrl = 'https://open-score-backend.onrender.com/api';
    protected $pin = '147258';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Starting Loan Cycle Test...");

        // 1. Setup Users
        $user = User::where('mobile_number', $this->targetPhone)->first();
        if (!$user) {
            $this->error("User with phone {$this->targetPhone} not found!");
            return 1;
        }

        $admin = User::where('role', 'ADMIN')->first();
        if (!$admin) {
            $this->error("Admin user not found!");
            return 1;
        }

        $this->info("User found: {$user->name} (ID: {$user->id})");
        $this->info("Admin found: {$admin->name} (ID: {$admin->id})");

        // 2. Setup Wallet PIN via API (Ensure Remote DB matches Test PIN)
        $this->info("Setting Wallet PIN via API on Remote...");
        
        $userToken = JWTAuth::fromUser($user);
        $adminToken = JWTAuth::fromUser($admin);

        $response = Http::withToken($userToken)->withHeaders(['Accept' => 'application/json'])->post("{$this->baseUrl}/wallet/set-pin", [
            'pin' => $this->pin,
            'pin_confirmation' => $this->pin
        ]);

        if ($response->failed()) {
            // It might fail if validation fails (e.g. invalid format), but we send digits:6 so should be fine.
            // Note: If no wallet exists, setPin creates one.
            $this->error("Failed to set PIN on Remote: " . $response->body());
            return 1;
        }
        $this->info("  -> PIN reset successfully to {$this->pin}");

        // 3. Tokens are already generated above


        // 4. Clean up any existing active loans
        $this->cleanupActiveLoans($user);

        // 5. Fetch Plans from API (to ensure IDs match and check eligibility)
        $response = Http::withToken($userToken)->withHeaders(['Accept' => 'application/json'])->get("{$this->baseUrl}/loan-plans");
        if ($response->failed()) {
             $this->error("Failed to fetch plans from API: " . $response->body());
             return 1;
        }
        
        $plans = $response->json();
        
        if (empty($plans)) {
            $this->error("No active loan plans found via API.");
            return 1;
        }

        $this->info("Found " . count($plans) . " active plans via API.");

        foreach ($plans as $plan) {
            $this->info("Processing Plan: {$plan['name']} (Amount: {$plan['amount']})");

            // Skip locked plans
            if (!empty($plan['is_locked'])) {
                $this->info("  -> Plan is LOCKED for this user. Skipping.");
                continue;
            }

            $configs = $plan['configurations'] ?? [];
            if (empty($configs)) {
                $this->warn("No configurations for this plan. Skipping.");
                continue;
            }

            foreach ($configs as $config) {
                $tenureDays = $config['tenure_days'];
                $frequencies = $config['allowed_frequencies'] ?? ['MONTHLY'];

                foreach ($frequencies as $freq) {
                    $this->info("---------------------------------------------------");
                    $this->info("Testing Cycle: Plan={$plan['name']}, Tenure={$tenureDays} days, Freq={$freq}");

                    try {
                        // Pass specific amount to satisfy min:1 validation
                        $this->runCycle($userToken, $adminToken, $plan['id'], $tenureDays, $freq, $plan['amount']);
                        $this->info("Cycle COMPLETED SUCCESSFULLY.");
                    } catch (\Exception $e) {
                        $this->error("Cycle FAILED: " . $e->getMessage());
                        return 1;
                    }
                }
            }
        }

        $this->info("ALL TESTS PASSED SUCCESSFULLY!");
        return 0;
    }

    private function runCycle($userToken, $adminToken, $planId, $tenure, $freq, $amount)
    {
        // A. Apply
        $response = Http::withToken($userToken)->withHeaders(['Accept' => 'application/json'])->post("{$this->baseUrl}/loans/apply", [
            'amount' => $amount, // Use supplied amount
            'tenure' => $tenure,
            'payout_frequency' => $freq,
            'payout_option_id' => 'BANK_TRANSFER', // Mock
            'loan_plan_id' => $planId
        ]);

        if ($response->failed()) {
            throw new \Exception("Apply failed (" . $response->status() . "): " . $response->body());
        }

        $loan = $response->json();
        if (!$loan || !isset($loan['id'])) {
            throw new \Exception("Apply returned invalid JSON: " . $response->body());
        }
        
        $loanId = $loan['id'];
        $this->info("  -> Loan Applied (ID: {$loanId}, Status: {$loan['status']})");

        // B. Confirm
        $response = Http::withToken($userToken)->withHeaders(['Accept' => 'application/json'])->post("{$this->baseUrl}/loans/{$loanId}/confirm");
        if ($response->failed()) throw new \Exception("Confirm failed: " . $response->body());
        $this->info("  -> Confirmed");

        // C. Admin Proceed (PENDING -> PROCEEDED)
        $response = Http::withToken($adminToken)->withHeaders(['Accept' => 'application/json'])->post("{$this->baseUrl}/admin/loans/{$loanId}/proceed");
        if ($response->failed()) throw new \Exception("Admin Proceed failed: " . $response->body());
        $this->info("  -> Admin Proceeded");

        // D. Send KYC (PROCEEDED -> KYC_SENT)
        $response = Http::withToken($adminToken)->withHeaders(['Accept' => 'application/json'])->post("{$this->baseUrl}/admin/loans/{$loanId}/send-kyc");
        if ($response->failed()) throw new \Exception("Send KYC failed: " . $response->body());
        $this->info("  -> KYC Sent");

        // E. Submit Form (KYC_SENT -> FORM_SUBMITTED)
        // Need to simulate form submission.
        $response = Http::withToken($userToken)->withHeaders(['Accept' => 'application/json'])->post("{$this->baseUrl}/loans/{$loanId}/submit-form", [
            'name' => 'Automated Tester',
            'pan' => 'ABCDE1234F'
        ]);
        if ($response->failed()) throw new \Exception("Submit Form failed: " . $response->body());
        $this->info("  -> Form Submitted");

        // F. Approve (FORM_SUBMITTED -> APPROVED)
        $response = Http::withToken($adminToken)->withHeaders(['Accept' => 'application/json'])->post("{$this->baseUrl}/admin/loans/{$loanId}/approve");
        if ($response->failed()) throw new \Exception("Admin Approve failed: " . $response->body());
        $this->info("  -> APPROVED");

        // G. Release (APPROVED -> DISBURSED)
        $response = Http::withToken($adminToken)->withHeaders(['Accept' => 'application/json'])->post("{$this->baseUrl}/admin/loans/{$loanId}/release");
        if ($response->failed()) throw new \Exception("Admin Release failed: " . $response->body());
        $this->info("  -> DISBURSED (Funds Released)");

        // H. Repayments
        $this->processRepayments($userToken, $loanId);

        // I. Final Check - Use API to check status
        $response = Http::withToken($userToken)->withHeaders(['Accept' => 'application/json'])->get("{$this->baseUrl}/loans/{$loanId}/repayments");
        if ($response->failed()) throw new \Exception("Final status check failed: " . $response->body());
        
        $data = $response->json();
        $finalStatus = $data['loan']['status'] ?? 'UNKNOWN';
        
        if ($finalStatus !== 'CLOSED') {
            throw new \Exception("Loan status is {$finalStatus}, expected CLOSED.");
        }
        $this->info("  -> Loan CLOSED Verified.");
    }

    private function processRepayments($userToken, $loanId)
    {
        $this->info("  -> Starting Repayments...");
        
        while (true) {
            // Get Repayments
            $response = Http::withToken($userToken)->get("{$this->baseUrl}/loans/{$loanId}/repayments");
            if ($response->failed()) throw new \Exception("Get Repayments failed");

            $data = $response->json();
            $repayments = collect($data['repayments']);
            $pending = $repayments->where('status', 'PENDING')->sortBy('due_date');

            if ($pending->isEmpty()) {
                break; // All paid
            }

            foreach ($pending as $repayment) {
                $pin = $this->pin;

                $this->info("    -> Paying EMI ID: {$repayment['id']} (Amount: {$repayment['amount']})");

                $payResponse = Http::withToken($userToken)->post("{$this->baseUrl}/loans/{$loanId}/repay", [
                    'pin' => $pin
                ]);

                if ($payResponse->failed()) {
                     throw new \Exception("Repayment failed for ID {$repayment['id']}: " . $payResponse->body());
                }
                
                $this->info("      -> Success");
            }
        }
        $this->info("  -> All EMIs Paid.");
    }

    private function cleanupActiveLoans($user)
    {
        $blockingLoans = Loan::where('user_id', $user->id)
            ->whereIn('status', ['PENDING', 'PROCEEDED', 'KYC_SENT', 'FORM_SUBMITTED', 'APPROVED', 'PREVIEW', 'DISBURSED'])
            ->get();

        foreach ($blockingLoans as $loan) {
            $this->info("Cancelling/Closing existing blocking loan ID: {$loan->id} ({$loan->status})");
            $loan->status = 'CANCELLED';
            if ($loan->status === 'DISBURSED') {
                 $loan->status = 'CLOSED';
                 $loan->paid_amount = $loan->amount;
            }
            $loan->save();
        }
    }
}
