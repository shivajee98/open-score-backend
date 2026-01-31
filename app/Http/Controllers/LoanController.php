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

        $loan->load('plan');

        return response()->json($loan, 201);
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

        $loan->status = 'PENDING';
        $loan->save();

        return response()->json($loan);
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
        return response()->json(Loan::with('plan')->where('user_id', Auth::id())->orderBy('created_at', 'desc')->get());
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

        // Always generate a token if it's missing or if we want to refresh it
        // For now, let's only generate if missing or explicitly asked (though here we just always ensure it exists)
        if (!$loan->kyc_token) {
            $loan->kyc_token = (string) Str::uuid();
        }
        
        $loan->status = 'KYC_SENT';
        $loan->kyc_sent_by = Auth::id(); // Capture who sent it
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
                'account_number'
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

        $loan->status = 'APPROVED';
        $loan->approved_at = now();
        $loan->approved_by = Auth::id();
        $loan->save();

        DB::table('admin_logs')->insert([
            'admin_id' => Auth::id(),
            'action' => 'loan_approved',
            'description' => "Approved loan stage (pre-disbursal) for ₹{$loan->amount}, User ID: {$loan->user_id}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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

            // Unlock the transaction in the wallet
            $this->walletService->approveLoanTransaction($loan->id);

            // Generate Repayment Schedule
            $this->generateRepaymentSchedule($loan);

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
                foreach ($fees as $fee) {
                    // We sum up all fees for now into a generic pile, or map them?
                    // The logic below just sums them.
                    $processingFee += $fee['amount']; 
                    // Note: original logic had separte fields. Now we just care about total payable?
                    // Or do we need to store them separately? 
                    // For now, let's just add to total fees.
                }
                
                // Interest
                $interestAmount = 0;
                $effectiveRate = $config['interest_rates'][$frequency] ?? ($config['interest_rate'] ?? 0);
                
                if ($effectiveRate > 0) {
                     $months = $config['tenure_days'] / 30;
                     $interestAmount = ($amount * ($effectiveRate / 100)) * $months;
                }
                
                $gstAmount = round($amount * 0.18); // Keep 18% rule?
                
                // Fees are taken UPFRONT, so do not add to Repayment Schedule.
                // Repayment = Principal + Interest
                $totalPayable = $loan->amount + $interestAmount;

            } else {
                 // Fallback if config not found (shouldn't happen if validated)
                 $totalPayable = $loan->amount; 
            }
            
        } else {
            // Legacy Logic (Hardcoded Fallback)
            // Assuming legacy was also intended to be Principal + Interest (which was 0 for small loans)
            $totalPayable = $loan->amount;
        }

        $totalEmis = 0;
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

        // Calculate total EMIs
        if ($plan && isset($config)) {
            // Use config days if available
            $totalDays = $config['tenure_days'];
            $totalEmis = floor($totalDays / $intervalDays);
        } else {
            // Legacy: Based on 30 days per month
            $totalEmis = floor(($tenureMonths * 30) / $intervalDays);
        }
        
        if ($totalEmis <= 0) $totalEmis = 1;

        // Integer-based distribution to avoid floating point issues
        $baseEmi = floor($totalPayable / $totalEmis);
        $remainder = $totalPayable % $totalEmis;

        for ($i = 1; $i <= $totalEmis; $i++) {
            // Add +1 to the first N installments where N is the remainder
            $currentEmiAmount = $baseEmi + ($i <= $remainder ? 1 : 0);

            LoanRepayment::create([
                'loan_id' => $loan->id,
                'amount' => $currentEmiAmount,
                'due_date' => Carbon::now()->addDays($i * $intervalDays)->toDateString(),
                'status' => 'PENDING'
            ]);
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

        // $balance = $this->walletService->getBalance($wallet->id);
        // if ($balance < $repayment->amount) {
        //     return response()->json(['error' => 'Insufficient balance in wallet'], 400);
        // }

        DB::transaction(function () use ($loan, $repayment, $wallet) {
            // TRIAL MODE: Bypass wallet deduction
            // $this->walletService->debit($wallet->id, $repayment->amount, 'LOAN_REPAYMENT', $repayment->id, "EMI Payment - #{$repayment->id}");

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

    public function listAll()
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        // Include everything that is not yet fully finalized (DISBURSED or REJECTED)
        return response()->json(Loan::with(['user', 'plan'])->whereNotIn('status', ['DISBURSED', 'REJECTED'])->orderBy('created_at', 'desc')->get());
    }

    public function listHistory()
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        return response()->json(Loan::with(['user', 'plan'])->whereIn('status', ['DISBURSED', 'REJECTED', 'CANCELLED', 'CLOSED'])->orderBy('created_at', 'desc')->get());
    }
}
