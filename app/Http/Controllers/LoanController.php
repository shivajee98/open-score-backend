<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Loan;
use App\Services\WalletService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\LoanRepayment;
use Carbon\Carbon;

class LoanController extends Controller
{
    protected $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }

    public function apply(Request $request)
    {
        // Validation: loan_plan_id is optional for backward compat but recommended
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'tenure' => 'required|integer',
            'payout_frequency' => 'required|string',
            'payout_option_id' => 'required|string',
            'loan_plan_id' => 'nullable|exists:loan_plans,id'
        ]);

        // Restriction: Only one active/pending loan allowed
        $processingLoan = Loan::where('user_id', Auth::id())
            ->whereIn('status', ['PENDING', 'PROCEEDED', 'KYC_SENT', 'FORM_SUBMITTED', 'APPROVED', 'PREVIEW'])
            ->first();

        if ($processingLoan) {
            return response()->json([
                'error' => 'Application Under Process',
                'message' => 'You already have a loan application under process. Please revoke (cancel) your current application if you wish to apply for a new one.'
            ], 403);
        }

        // Restriction: Cannot have any active (DISBURSED) loan that is NOT fully paid
        $activeLoan = Loan::where('user_id', Auth::id())
            ->where('status', 'DISBURSED')
            ->get()
            ->filter(function($l) {
                return (float)$l->paid_amount < (float)$l->amount;
            })
            ->first();

        if ($activeLoan) {
             return response()->json([
                'error' => 'Active Loan Exists',
                'message' => 'You already have an active loan. Please repay it fully to apply for a new one.'
            ], 403);
        }

        // Restriction: Cannot apply within 15 days of a disbursed loan, UNLESS it's already paid back
        $lastDisbursed = Loan::where('user_id', Auth::id())
            ->where('status', 'DISBURSED')
            ->where('disbursed_at', '>', Carbon::now()->subDays(15))
            ->orderBy('disbursed_at', 'desc')
            ->get()
            ->filter(function($l) {
                // If fully paid, we ignore the wait period
                return (float)$l->paid_amount < (float)$l->amount;
            })
            ->first();

        if ($lastDisbursed) {
            $daysLeft = 15 - Carbon::now()->diffInDays($lastDisbursed->disbursed_at);
            return response()->json([
                'error' => 'Wait Period Active',
                'message' => "Your last loan was disbursed on {$lastDisbursed->disbursed_at->format('d M')}. You can apply for a new loan after 15 days from disbursal (approx. {$daysLeft} days left)."
            ], 403);
        }
        
        // Use Plan details if provided, otherwise trust request (legacy)
        $amount = $request->amount;
        $tenure = $request->tenure;
        $frequency = $request->payout_frequency;
        
        if ($request->loan_plan_id) {
            $plan = \App\Models\LoanPlan::find($request->loan_plan_id);
            if ($plan) {
                // Progressive Unlocking Logic for loans > 50,000
                if ((float)$plan->amount > 50000) {
                    $allPlans = \App\Models\LoanPlan::orderBy('amount', 'asc')->get();
                    $currentIndex = $allPlans->search(fn($p) => $p->id == $plan->id);
                    
                    if ($currentIndex > 0) {
                        $prevPlan = $allPlans[$currentIndex - 1];
                        
                        // Check if user has a CLOSED loan OR fully paid DISBURSED loan for the previous amount
                        $hasClosedPrev = Loan::where('user_id', Auth::id())
                            ->where(function($q) {
                                $q->where('status', 'CLOSED')
                                  ->orWhere(function($sq) {
                                      $sq->where('status', 'DISBURSED')
                                         ->whereColumn('paid_amount', '>=', 'amount');
                                  });
                            })
                            ->where('amount', $prevPlan->amount)
                            ->exists();
                            
                        if (!$hasClosedPrev) {
                            return response()->json([
                                'error' => 'Eligibility Required',
                                'message' => "You're currently not eligible for the ₹" . number_format($plan->amount) . " loan. Please build your eligibility by successfully repaying your previous ₹" . number_format($prevPlan->amount) . " loan."
                            ], 403);
                        }
                    }
                }

                // Find matching configuration for the requested tenure
                // Heuristic: If tenure > 6, assume DAYS. Else, MONTHS.
                $tenureIsDays = $request->tenure > 6;
                $targetDays = $tenureIsDays ? $request->tenure : $request->tenure * 30;
                
                $config = null;
                if ($plan->configurations) {
                    foreach ($plan->configurations as $conf) {
                        // Allow some flexibility (e.g. 90 days vs 3 months)
                        if (abs($conf['tenure_days'] - $targetDays) <= 5) {
                            $config = $conf;
                            break;
                        }
                    }
                }

                if (!$config) {
                     return response()->json(['error' => 'Invalid Tenure', 'message' => 'Selected tenure is not available for this plan.'], 400);
                }

                $amount = $plan->amount; // Base amount
                
                // Validate Frequency against the CONFIG's allowed frequencies
                $allowed = $config['allowed_frequencies'] ?? [];
                if (!in_array($request->payout_frequency, $allowed)) {
                     return response()->json(['error' => 'Invalid Frequency', 'message' => "This tenure option supports: " . implode(', ', $allowed)], 400);
                }
            }
        }
        
        $loan = Loan::create([
            'user_id' => Auth::id(),
            'amount' => $amount,
            'tenure' => $tenure,
            'payout_frequency' => $frequency,
            'payout_option_id' => $request->payout_option_id,
            'loan_plan_id' => $request->loan_plan_id,
            'status' => 'PREVIEW'
        ]);

        // ============================================================
        // SPECIAL CASE: ₹10,000 Loan - Auto-approve & Lock Amount
        // For ₹10,000 loans, we skip the normal flow and auto-approve
        // The user just needs to contact admin to release the funds
        // ============================================================
        if ((float)$amount == 10000) {
            // Auto-fill minimal KYC data
            $user = Auth::user();
            $loan->form_data = [
                'first_name' => explode(' ', $user->name ?? 'Customer')[0],
                'last_name' => count(explode(' ', $user->name ?? '')) > 1 ? explode(' ', $user->name)[1] : '',
                'email' => $user->email ?? '',
                'phone' => $user->mobile_number ?? '',
                'street_address' => $user->business_address ?? 'N/A',
                'city' => $user->city ?? 'N/A',
                'state' => 'N/A',
                'postal_code' => $user->pincode ?? 'N/A',
                'employer' => $user->business_name ?? 'Self',
                'occupation' => 'Business Owner',
                'consent' => true,
                'auto_approved' => true,
                'auto_approved_at' => now()->toISOString()
            ];
            
            // Skip to APPROVED status (bypassing PENDING, PROCEEDED, KYC_SENT, FORM_SUBMITTED)
            $loan->status = 'APPROVED';
            $loan->approved_at = now();
            $loan->save();

            // Lock the amount in user's wallet immediately
            $wallet = $this->walletService->getWallet($loan->user_id);
            if (!$wallet) $wallet = $this->walletService->createWallet($loan->user_id);
            
            $this->walletService->credit(
                $wallet->id, 
                $loan->amount, 
                'LOAN', 
                $loan->id, 
                "₹10,000 Instant Loan (Pending Release)", 
                'PENDING'  // Locked status - will be approved when admin releases
            );

            $loan->load('plan');

            return response()->json([
                ...$loan->toArray(),
                'auto_approved' => true,
                'message' => 'Your ₹10,000 loan has been pre-approved! Contact your supervisor to release the funds to your account.'
            ], 201);
        }

        $loan->load('plan');

        return response()->json($loan, 201);
    }

    /**
     * Calculate EMI preview without creating a loan
     * This is the single source of truth for EMI calculations
     */
    public function calculatePreview(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'tenure_days' => 'required|integer|min:1',
            'frequency' => 'required|string',
            'loan_plan_id' => 'nullable|exists:loan_plans,id'
        ]);

        $amount = $request->amount;
        $tenureDays = $request->tenure_days;
        $frequency = strtoupper($request->frequency);
        
        // LOGGING FOR DEBUGGING
        \Illuminate\Support\Facades\Log::info("CalculatePreview Debug: ", [
            'raw_freq' => $request->frequency,
            'upper_freq' => $frequency,
            'tenure_days' => $tenureDays
        ]);

        // Parse frequency to get interval days
        $intervalDays = $this->parseFrequencyInterval($frequency);
        
        \Illuminate\Support\Facades\Log::info("CalculatePreview Interval: ", ['interval' => $intervalDays]);

        // Calculate number of EMIs
        $numEmis = max(1, floor($tenureDays / $intervalDays));
        
        // Get fees and interest from loan plan if provided
        $processingFee = 0;
        $loginFee = 0;
        $fieldKycFee = 0;
        $otherFees = 0;
        $gst = 0;
        $interestRate = 0;
        $totalInterest = 0;
        
        if ($request->loan_plan_id) {
            $plan = \App\Models\LoanPlan::find($request->loan_plan_id);
            if ($plan && is_array($plan->configurations)) {
                // Find matching configuration
                foreach ($plan->configurations as $config) {
                    if (abs(($config['tenure_days'] ?? 0) - $tenureDays) <= 5) {
                        // Extract fees
                        $fees = $config['fees'] ?? [];
                        foreach ($fees as $fee) {
                            $fAmount = (float)($fee['amount'] ?? 0);
                            $name = strtolower($fee['name'] ?? '');
                            
                            if (strpos($name, 'processing') !== false) {
                                $processingFee += $fAmount;
                            } elseif (strpos($name, 'login') !== false) {
                                $loginFee += $fAmount;
                            } elseif (strpos($name, 'field') !== false || strpos($name, 'kyc') !== false) {
                                $fieldKycFee += $fAmount;
                            } elseif (strpos($name, 'gst') !== false) {
                                $gst += $fAmount;
                            } else {
                                $otherFees += $fAmount;
                            }
                        }
                        
                        // Extract interest rate
                        if (isset($config['interest_rates']) && is_array($config['interest_rates']) && isset($config['interest_rates'][$request->frequency])) {
                            $interestRate = (float)$config['interest_rates'][$request->frequency];
                        } elseif (isset($config['interest_rate'])) {
                            $interestRate = (float)$config['interest_rate'];
                        }
                        
                        // Extract GST Rate
                        $gstRate = isset($config['gst_rate']) ? (float)$config['gst_rate'] : 18;
                        
                        break;
                    }
                }
            }
        }
        
        // Calculate interest
        $months = $tenureDays / 30;
        $totalInterest = round(($amount * $interestRate / 100) * $months);
        
        // Calculate GST based on Processing Fees only
        $gstRate = $gstRate ?? 18; // Default 18 if not found in config
        $gst = round($processingFee * ($gstRate / 100));
        
        // Calculate totals
        $totalFees = $processingFee + $loginFee + $fieldKycFee + $otherFees;
        $totalPayable = $amount + $totalFees + $gst + $totalInterest;
        $emiAmount = round($totalPayable / $numEmis);
        
        return response()->json([
            'principal' => $amount,
            'tenure_days' => $tenureDays,
            'frequency' => $request->frequency,
            'interval_days' => $intervalDays,
            'num_emis' => $numEmis,
            'emi_amount' => $emiAmount,
            'processing_fee' => $processingFee,
            'login_fee' => $loginFee,
            'field_kyc_fee' => $fieldKycFee,
            'other_fees' => $otherFees,
            'gst' => $gst,
            'interest_rate' => $interestRate,
            'total_interest' => $totalInterest,
            'total_payable' => $totalPayable,
            'disbursal_amount' => $amount, // Customer gets full amount
        ]);
    }

    /**
     * Parse frequency string to interval days
     * Handles: DAILY, WEEKLY, MONTHLY, "3_DAYS", "15_DAYS", etc.
     */
    private function parseFrequencyInterval($frequency)
    {
        $freq = strtoupper($frequency);
        
        if ($freq === 'DAILY') {
            return 1;
        } elseif ($freq === 'WEEKLY') {
            return 7;
        } elseif ($freq === 'MONTHLY') {
            return 30;
        } else {
            // Match patterns like "3_DAYS", "15_DAYS", "3 DAYS", "15 DAYS"
            if (preg_match('/(\d+)[\s_]*DAYS?/i', $freq, $matches)) {
                return (int)$matches[1];
            }
        }
        
        // Default to monthly if can't parse
        return 30;
    }

    public function confirm(Request $request, $id)
    {
        $loan = Loan::with('plan')->findOrFail($id);
        
        if ($loan->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($loan->status !== 'PREVIEW') {
            return response()->json(['error' => 'Can only confirm from PREVIEW state'], 400);
        }

        // Ensure KYC data is filled
        if (!$loan->form_data || empty($loan->form_data)) {
            return response()->json(['error' => 'Please complete the KYC form before confirming'], 400);
        }

        $loan->status = 'PENDING';
        $loan->save();

        return response()->json($loan);
    }

    /**
     * Save KYC form data to the loan (before confirmation)
     */
    public function saveKycData(Request $request, $id)
    {
        $loan = Loan::with('plan')->findOrFail($id);
        
        if ($loan->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Allow saving KYC data only in PREVIEW state
        if ($loan->status !== 'PREVIEW') {
            return response()->json(['error' => 'Can only save KYC data in PREVIEW state'], 400);
        }

        // Validate required fields
        $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'email' => 'required|email',
            'phone' => 'required|string',
            'street_address' => 'required|string',
            'city' => 'required|string',
            'state' => 'required|string',
            'postal_code' => 'required|string',
            'employer' => 'required|string',
            'occupation' => 'required|string',
            'aadhar_number' => 'required|string|size:12', // Added validation
            'pan_number' => 'required|string|size:10',    // Added validation
            'consent' => 'required|accepted'
        ]);

        // Merge with existing form_data if any
        $existingData = $loan->form_data ?? [];
        $newData = $request->all();
        
        $loan->form_data = array_merge($existingData, $newData);
        $loan->save();

        // Also update user profile with relevant data
        $user = \App\Models\User::find($loan->user_id);
        if ($user) {
            $profileData = [];
            
            // Update user name if not set
            if (!$user->name || trim($user->name) === '') {
                $profileData['name'] = trim($request->first_name . ' ' . $request->last_name);
            }
            
            // Update email if not set
            if (!$user->email) {
                $profileData['email'] = $request->email;
            }
            
            // Update city if not set
            if (!$user->city) {
                $profileData['city'] = $request->city;
            }
            
            // Update pincode if not set
            if (!$user->pincode) {
                $profileData['pincode'] = $request->postal_code;
            }
            
            // Update business address if not set
            if (!$user->business_address) {
                $profileData['business_address'] = $request->street_address;
            }

            // Always update Aadhar and PAN if provided
            $profileData['aadhar_number'] = $request->aadhar_number;
            $profileData['pan_number'] = $request->pan_number;
            
            if (!empty($profileData)) {
                $user->update($profileData);
            }
        }

        return response()->json([
            'message' => 'KYC data saved successfully',
            'loan' => $loan
        ]);
    }


    public function cancel(Request $request, $id)
    {
        $loan = Loan::with('plan')->findOrFail($id);

        if ($loan->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Can only cancel if not yet DISBURSED and not already REJECTED/CANCELLED
        if (in_array($loan->status, ['DISBURSED', 'REJECTED', 'CANCELLED'])) {
            return response()->json(['error' => 'Cannot cancel loan in current status'], 400);
        }

        $loan->status = 'CANCELLED';
        $loan->save();

        // Cleanup pending wallet transaction if any
        $this->walletService->rejectLoanTransaction($loan->id);

        return response()->json(['message' => 'Loan application cancelled successfully']);
    }

    public function index()
    {
        return response()->json(Loan::with('plan')->where('user_id', Auth::id())->orderBy('created_at', 'desc')->paginate(20));
    }

    public function proceed(Request $request, $id)
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $loan = Loan::with('plan')->findOrFail($id);
        if ($loan->status !== 'PENDING') {
            return response()->json(['error' => 'Can only proceed from PENDING state'], 400);
        }

        $loan->status = 'PROCEEDED';
        $loan->save();

        return response()->json($loan);
    }

    public function sendKyc(Request $request, $id)
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $loan = Loan::with('plan')->findOrFail($id);
        
        // Allow sending KYC if PROCEEDED (first time), KYC_SENT (resending), or FORM_SUBMITTED (resending after submission)
        if (!in_array($loan->status, ['PROCEEDED', 'KYC_SENT', 'FORM_SUBMITTED'])) {
            return response()->json(['error' => 'Can only send KYC from PROCEEDED, KYC_SENT, or FORM_SUBMITTED state'], 400);
        }

        // Always generate a token if it's missing
        if (!$loan->kyc_token) {
            $loan->kyc_token = (string) Str::uuid();
        }
        
        $loan->status = 'KYC_SENT';
        $loan->kyc_sent_by = Auth::id();
        $loan->save();

        // Reflect loan amount in wallet as LOCKED (PENDING status)
        $wallet = $this->walletService->getWallet($loan->user_id);
        if (!$wallet) $wallet = $this->walletService->createWallet($loan->user_id);
        
        $this->walletService->credit(
            $wallet->id, 
            $loan->amount, 
            'LOAN', 
            $loan->id, 
            "Loan Disbursal (Pending KYC/Final Approval)", 
            'PENDING'
        );

        return response()->json([
            'loan' => $loan,
            'kyc_link' => env('KYC_FORM_URL', 'https://openscorekyc.galobyte.site') . "/form/{$loan->kyc_token}"
        ]);
    }

    public function submitForm(Request $request, $id)
    {
        $loan = Loan::with('plan')->findOrFail($id);
        
        // Ensure only the owner can submit the form
        if ($loan->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($loan->status !== 'KYC_SENT') {
            return response()->json(['error' => 'KYC form not requested or already submitted'], 400);
        }

        $loan->form_data = $request->all();
        $loan->status = 'FORM_SUBMITTED';
        $loan->save();

        return response()->json($loan);
    }

    // --- External KYC System Methods ---

    public function verifyKycToken($token)
    {
        $loan = Loan::where('kyc_token', $token)->firstOrFail();

        if ($loan->status === 'FORM_SUBMITTED' || $loan->kyc_submitted_at) {
            return response()->json(['error' => 'Already submitted'], 400);
        }

        return response()->json([
            'loan_id' => $loan->id,
            'amount' => $loan->amount,
            'status' => $loan->status
        ]);
    }

    public function submitKycData(Request $request, $token)
    {
        $loan = Loan::where('kyc_token', $token)->firstOrFail();

        if ($loan->kyc_submitted_at) {
            return response()->json(['error' => 'Already submitted'], 400);
        }

        // Save bank details to User table
        $user = \App\Models\User::find($loan->user_id);
        if ($user) {
            $user->update($request->only([
                'bank_name', 
                'ifsc_code', 
                'account_holder_name', 
                'account_number',
                'location_url'
            ]));
        }

        $loan->form_data = array_merge($loan->form_data ?? [], $request->all());
        $loan->status = 'FORM_SUBMITTED';
        $loan->kyc_submitted_at = now();
        $loan->save();

        return response()->json(['message' => 'KYC submitted successfully']);
    }

    public function approve(Request $request, $id)
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $loan = Loan::with('plan')->findOrFail($id);
        if ($loan->status !== 'FORM_SUBMITTED') {
            return response()->json(['error' => 'Loan must have submitted form before approval'], 400);
        }

        // FINANCIAL CONTROL: Check Admin Funds
        $adminFund = \App\Models\AdminFund::first();
        if (!$adminFund) {
             return response()->json(['error' => 'Admin fund pool not initialized'], 500);
        }

        // Calculate current available
        $reserved = \App\Models\LoanAllocation::where('status', 'RESERVED')->sum('allocated_amount');
        $disbursed = \App\Models\LoanAllocation::where('status', 'DISBURSED')->sum('actual_disbursed');
        $available = $adminFund->total_funds - $reserved - $disbursed;

        if ($available < $loan->amount) {
            return response()->json([
                'error' => 'Insufficient Funds', 
                'message' => "Cannot approve loan. Available admin funds (₹{$available}) are less than loan amount (₹{$loan->amount}). Please add funds to the pool."
            ], 400);
        }

        DB::transaction(function() use ($loan, $adminFund) {
            $loan->status = 'APPROVED';
            $loan->approved_at = now();
            $loan->approved_by = Auth::id();
            $loan->save();

            // RESERVE FUNDS
            \App\Models\LoanAllocation::create([
                'loan_id' => $loan->id,
                'user_id' => $loan->user_id,
                'allocated_amount' => $loan->amount,
                'status' => 'RESERVED'
            ]);
            
            // Update cached available funds
            // We don't strictly need to do this here if getStats validates it, but good for consistency
            $adminFund->available_funds -= $loan->amount;
            $adminFund->save();

            DB::table('admin_logs')->insert([
                'admin_id' => Auth::id(),
                'action' => 'loan_approved',
                'description' => "Approved loan for ₹{$loan->amount}. Funds reserved.",
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json($loan);
    }

    public function release(Request $request, $id)
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $loan = Loan::with('plan')->findOrFail($id);
        if ($loan->status !== 'APPROVED') {
            return response()->json(['error' => 'Loan must be approved before releasing funds'], 400);
        }

        DB::transaction(function () use ($loan) {
            $loan->status = 'DISBURSED';
            $loan->disbursed_at = now();
            $loan->disbursed_by = Auth::id();
            $loan->save();

            // FINANCIAL CONTROL: Update Allocation to DISBURSED
            // We find the reserved allocation and mark it as disbursed.
            $allocation = \App\Models\LoanAllocation::where('loan_id', $loan->id)
                ->whereIn('status', ['RESERVED', 'ADJUSTED']) // Include ADJUSTED
                ->first();
                
            if ($allocation) {
                $allocation->status = 'DISBURSED';
                $allocation->actual_disbursed = $loan->amount;
                 // If the reserved amount was different (due to admin reduction), we might need logic here.
                 // But typically, we disburse the full loan amount. 
                 // If reserved was 9700 (due to 3000 reduction), and we disburse 10000...
                 // Then we are overspending the pool? 
                 // The requirement says: "if admin enter 103000 ... edited to 100000 ... decreased amount has to be deducted".
                 // This implies the USER gets less money? 
                 // "amount has to be deducted in the proportion"
                 
                 // If allocation was adjusted, the user should receive the ADJUSTED amount.
                 if ($allocation->allocated_amount < $loan->amount) {
                      // Disburse only the allocated amount?
                      // Or do we disburse the full amount and create a deficit?
                      // "decreased amount has to be deducted" implies user gets less.
                      // So we should update the LOAN amount to match the allocation!
                      
                      $loan->amount = $allocation->allocated_amount;
                      $loan->save(); // Update loan principal
                 }
                 
                 $allocation->actual_disbursed = $loan->amount;
                 $allocation->save();
            } else {
                 // Fallback if no allocation exists (legacy loans?)
                 // Create one now for record keeping
                 \App\Models\LoanAllocation::create([
                    'loan_id' => $loan->id,
                    'user_id' => $loan->user_id,
                    'allocated_amount' => $loan->amount,
                    'actual_disbursed' => $loan->amount,
                    'status' => 'DISBURSED'
                 ]);
            }

            // Unlock the transaction in the wallet
            $this->walletService->approveLoanTransaction($loan->id);

            // Debit System Wallet for Disbursal
            $systemWallet = $this->walletService->getSystemWallet();
            $this->walletService->debit(
                $systemWallet->id, 
                $loan->amount, 
                'LOAN_DISBURSAL', 
                $loan->id, 
                "Disbursal for Loan #{$loan->id}"
            );

            // Generate Repayment Schedule
            $this->generateRepaymentSchedule($loan);

            // Check for referral bonus - loan disbursement
            $referralRecord = \App\Models\UserReferral::where('referred_id', $loan->user_id)->first();
            if ($referralRecord && !$referralRecord->loan_bonus_paid) {
                $referralSettings = \App\Models\ReferralSetting::first();
                if ($referralSettings && $referralSettings->is_enabled) {
                    $loanBonus = $referralSettings->loan_disbursement_bonus;
                    
                    // Credit loan disbursement bonus to referrer
                    $referrerWallet = \App\Models\Wallet::where('user_id', $referralRecord->referrer_id)->first();
                    if ($referrerWallet && $loanBonus > 0) {
                        $this->walletService->transferSystemFunds(
                            $referralRecord->referrer_id,
                            $loanBonus,
                            'REFERRAL_LOAN_BONUS',
                            "Loan disbursement bonus for referred user loan #" . $loan->id,
                            'OUT'
                        );
                        
                        // Update referral record
                        $referralRecord->loan_bonus_earned = $loanBonus;
                        $referralRecord->loan_bonus_paid = true;
                        $referralRecord->loan_bonus_paid_at = now();
                        $referralRecord->save();
                    }
                }
            }

            DB::table('admin_logs')->insert([
                'admin_id' => Auth::id(),
                'action' => 'loan_disbursed',
                'description' => "Disbursed funds for loan of ₹{$loan->amount} for User ID: {$loan->user_id}",
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json($loan);
    }
    
    private function generateRepaymentSchedule($loan)
    {
        $amount = $loan->amount;
        $frequency = strtoupper($loan->payout_frequency); 
        $tenureMonths = $loan->tenure;
        
        // Fee Calculation Logic
        $processingFee = 0;
        $loginFee = 0;
        $fieldKycFee = 0;
        $gstAmount = 0; // Will calculate
        
        // Check for Dynamic Plan
        $plan = null;
        if ($loan->loan_plan_id) {
            $plan = \App\Models\LoanPlan::find($loan->loan_plan_id);
        }
        
        if ($plan) {
            // Replicate heuristic: If tenure > 6, it's days.
            $tenureIsDays = $loan->tenure > 6;
            $targetDays = $tenureIsDays ? $loan->tenure : $loan->tenure * 30;

            $config = null;
            if ($plan->configurations) {
                foreach ($plan->configurations as $conf) {
                    if (abs($conf['tenure_days'] - $targetDays) <= 5) {
                        $config = $conf;
                        break;
                    }
                }
            }

            if ($config) {
                // Dynamic Fees from JSON
                $fees = $config['fees'] ?? [];
                $loginFee = 0;
                $fieldKycFee = 0;
                $otherFees = 0;

                foreach ($fees as $fee) {
                    $fAmount = (float)($fee['amount'] ?? 0);
                    $name = strtolower($fee['name'] ?? '');
                    
                    if (strpos($name, 'processing') !== false) {
                        $processingFee += $fAmount;
                    } elseif (strpos($name, 'login') !== false) {
                        $loginFee += $fAmount;
                    } elseif (strpos($name, 'field') !== false || strpos($name, 'kyc') !== false) {
                        $fieldKycFee += $fAmount;
                    } else {
                        $otherFees += $fAmount;
                    }
                }
                
                // Interest
                $interestAmount = 0;
                $effectiveRate = $config['interest_rates'][$frequency] ?? ($config['interest_rate'] ?? 0);
                
                if ($effectiveRate > 0) {
                     $months = $config['tenure_days'] / 30;
                     $interestAmount = ($amount * ($effectiveRate / 100)) * $months;
                }
                
                 
                // Calculate GST based on Processing Fees only
                $gstRate = isset($config['gst_rate']) ? (float)$config['gst_rate'] : 18;
                $gstAmount = round($processingFee * ($gstRate / 100)); 

                $totalFees = $processingFee + $loginFee + $fieldKycFee + $otherFees;
                
                // Fees are ADDED to Repayment Schedule.
                // Repayment = Principal + Fees + GST + Interest
                $totalPayable = $loan->amount + $totalFees + $gstAmount + $interestAmount;

            } else {
                 // Fallback if config not found (shouldn't happen if validated)
                 $totalPayable = $loan->amount; 
            }
            
        } else {
            // Legacy Logic (Hardcoded Fallback)
            // Assuming legacy was also intended to be Principal + Interest (which was 0 for small loans)
            $totalPayable = $loan->amount;
        }

        // Calculate interval days based on frequency
        $intervalDays = 0;

        if ($frequency === 'DAILY') {
            $intervalDays = 1;
        } elseif ($frequency === 'WEEKLY') {
            $intervalDays = 7;
        } elseif ($frequency === 'MONTHLY') {
            $intervalDays = 30;
        } elseif (preg_match('/(\d+)\s*DAYS?/', $frequency, $matches)) {
            $intervalDays = (int)$matches[1];
        } else {
            // Default to monthly if unknown
            $intervalDays = 30;
        }

        // Get total tenure days
        $totalDays = 0;
        if ($plan && isset($config)) {
            // Use config days if available
            $totalDays = $config['tenure_days'];
        } else {
            // Legacy: Based on 30 days per month
            $totalDays = $tenureMonths * 30;
        }

        // ============================================================
        // FIXED CALCULATION: Handle remainder days properly
        // ============================================================
        
        $regularEmis = floor($totalDays / $intervalDays);
        $remainderDays = $totalDays % $intervalDays;
        
        // If there are remainder days, we need one additional EMI
        $totalEmis = $regularEmis;
        if ($remainderDays > 0) {
            $totalEmis = $regularEmis + 1;
        }
        
        // Ensure at least 1 EMI
        if ($totalEmis <= 0) $totalEmis = 1;

        // Integer-based distribution to avoid floating point issues
        $baseEmi = floor($totalPayable / $totalEmis);
        $remainder = $totalPayable % $totalEmis;

        // Create regular EMIs at standard intervals
        for ($i = 1; $i <= $regularEmis; $i++) {
            // Add +1 to the first N installments where N is the remainder
            $currentEmiAmount = $baseEmi + ($i <= $remainder ? 1 : 0);

            LoanRepayment::create([
                'loan_id' => $loan->id,
                'amount' => $currentEmiAmount,
                'due_date' => Carbon::now()->addDays($i * $intervalDays)->toDateString(),
                'status' => 'PENDING'
            ]);
        }

        // If there are remainder days, create a final EMI
        if ($remainderDays > 0) {
            // Calculate remaining amount (this handles cases where distribution might have rounding)
            $paidSoFar = $baseEmi * $regularEmis + min($remainder, $regularEmis);
            $finalEmiAmount = $totalPayable - $paidSoFar;
            
            // If for some reason final amount is 0 or negative, skip it
            if ($finalEmiAmount > 0) {
                LoanRepayment::create([
                    'loan_id' => $loan->id,
                    'amount' => $finalEmiAmount,
                    'due_date' => Carbon::now()->addDays($totalDays)->toDateString(),
                    'status' => 'PENDING'
                ]);
            }
        }
    }

    public function repayments($id)
    {
        $loan = Loan::findOrFail($id);
        if ($loan->user_id !== Auth::id()) return response()->json(['error' => 'Unauthorized'], 403);

        $repayments = LoanRepayment::where('loan_id', $id)->orderBy('due_date', 'asc')->get();
        return response()->json([
            'loan' => $loan,
            'repayments' => $repayments
        ]);
    }

    public function repay(Request $request, $loan_id)
    {
        $request->validate([
            'pin' => 'required|digits:6'
        ]);

        $loan = Loan::findOrFail($loan_id);
        $repayment = LoanRepayment::where('loan_id', $loan_id)
            ->where('status', 'PENDING')
            ->orderBy('due_date', 'asc')
            ->firstOrFail();

        $wallet = $this->walletService->getWallet(Auth::id());

        // Verify PIN
        if (!$this->walletService->verifyPin($wallet->id, $request->pin)) {
            return response()->json(['error' => 'Invalid transaction PIN'], 403);
        }

        $balance = $this->walletService->getBalance($wallet->id);
        if ($balance < $repayment->amount) {
            return response()->json(['error' => 'Insufficient balance in wallet'], 400);
        }

        DB::transaction(function () use ($loan, $repayment, $wallet) {
            // Transfer from User to System
            $this->walletService->transferSystemFunds(
                $wallet->user_id,
                $repayment->amount,
                'LOAN_REPAYMENT',
                "EMI Payment - #{$repayment->id}",
                'IN'
            );

            $repayment->status = 'PAID';
            $repayment->paid_at = now();
            $repayment->save();

            $loan->increment('paid_amount', $repayment->amount);
            
            // Check if all repayments are completed
            $pendingCount = LoanRepayment::where('loan_id', $loan->id)
                ->where('status', 'PENDING')
                ->count();
                
            if ($pendingCount === 0) {
                $loan->status = 'CLOSED';
                $loan->closed_at = now();
                $loan->save();
            }
        });

        return response()->json(['message' => 'Repayment successful', 'repayment' => $repayment, 'ref' => 'REPAY-' . strtoupper(Str::random(10))]);
    }

    public function listAll(Request $request)
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $query = Loan::with(['user', 'plan']);

        // Default: Pending/In-progress loans
        $query->whereNotIn('status', ['DISBURSED', 'REJECTED', 'CLOSED', 'CANCELLED']);

        if ($request->has('status') && $request->status !== 'ALL') {
            $query->where('status', $request->status);
        }

        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('id', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('name', 'like', "%{$search}%")
                         ->orWhere('mobile_number', 'like', "%{$search}%");
                  });
            });
        }

        return response()->json($query->orderBy('amount', 'asc')->paginate($request->get('per_page', 50)));
    }

    public function listHistory(Request $request)
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $query = Loan::with(['user', 'plan']);

        // Default: Finalized loans
        $query->whereIn('status', ['DISBURSED', 'REJECTED', 'CANCELLED', 'CLOSED']);

        if ($request->has('status') && $request->status !== 'ALL') {
             $query->where('status', $request->status);
        }

        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('id', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('name', 'like', "%{$search}%")
                         ->orWhere('mobile_number', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        return response()->json($query->orderBy('amount', 'asc')->paginate($request->get('per_page', 50)));
    }

    public function getDetails($id)
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $loan = Loan::with(['user', 'plan', 'approver', 'disburser'])->findOrFail($id);
        
        $repayments = LoanRepayment::where('loan_id', $id)
            ->with('collector')
            ->orderBy('due_date', 'asc')
            ->get();
            
        return response()->json([
            'loan' => $loan,
            'repayments' => $repayments
        ]);
    }

    public function manualCollect(Request $request, $repaymentId)
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'amount' => 'required|numeric',
            'notes' => 'nullable|string'
        ]);

        $repayment = LoanRepayment::findOrFail($repaymentId);
        
        if ($repayment->status === 'PAID') {
            return response()->json(['error' => 'Already paid'], 400);
        }

        $repayment->payment_mode = 'MANUAL';
        $repayment->collected_by = Auth::id();
        $repayment->notes = $request->notes;
        $repayment->status = 'MANUAL_VERIFICATION'; 
        // We set it to MANUAL_VERIFICATION first so another admin or header can approve? 
        // Or if Admin does it, is it auto approved? 
        // Plan said: "Enter amt -> Submit -> Approved by admin". 
        // If the current user IS admin, maybe we can just mark it paid or keep it as verification state?
        // Let's stick to the plan: "After submit approved by admin" -> implies 2 status.
        
        $repayment->save();

        return response()->json($repayment);
    }

    public function approveManualCollect($repaymentId)
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $repayment = LoanRepayment::findOrFail($repaymentId);

        if ($repayment->status !== 'MANUAL_VERIFICATION') {
            return response()->json(['error' => 'Invalid status for approval'], 400);
        }

        DB::transaction(function () use ($repayment) {
            $repayment->status = 'PAID';
            $repayment->paid_at = now();
            $repayment->save();

            $loan = Loan::findOrFail($repayment->loan_id);
            $loan->increment('paid_amount', $repayment->amount);
            
            // Check if all repayments are completed
            $pendingCount = LoanRepayment::where('loan_id', $loan->id)
                ->where('status', 'PENDING')
                ->where('status', '!=', 'PAID') // Just to be safe
                ->count();
            
            // Actually stricter check:
            $unpaidCount = LoanRepayment::where('loan_id', $loan->id)
                ->where('status', '!=', 'PAID')
                ->count();

            if ($unpaidCount === 0) {
                $loan->status = 'CLOSED';
                $loan->closed_at = now();
                $loan->save();
            }
        });

        return response()->json($repayment);
    }


    public function closeManually($id)
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $loan = Loan::findOrFail($id);
        $loan->status = 'CLOSED';
        $loan->closed_at = now();
        $loan->save();

        DB::table('admin_logs')->insert([
            'admin_id' => Auth::id(),
            'action' => 'loan_closed_manually',
            'description' => "Manually CLOSED loan ID: {$loan->id} for user: {$loan->user_id}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Loan marked as CLOSED successfully']);
    }

    public function destroy($id)
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $loan = Loan::findOrFail($id);
        
        DB::transaction(function() use ($loan) {
            // Delete repayments
            LoanRepayment::where('loan_id', $loan->id)->delete();
            
            // Log before deleting
            DB::table('admin_logs')->insert([
                'admin_id' => Auth::id(),
                'action' => 'loan_deleted',
                'description' => "Deleted loan ID: {$loan->id} (Amount: {$loan->amount}) for user ID: {$loan->user_id}",
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $loan->delete();
        });

        return response()->json(['message' => 'Loan and its records deleted successfully']);
    }
}