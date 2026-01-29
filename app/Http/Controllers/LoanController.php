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
                $amount = $plan->amount;
                // Tenure in plan is days, loan table stores months usually.
                // Let's keep storing whatever the frontend sent for 'tenure' column to avoid breaking view logic,
                // but rely on 'loan_plan_id' for calculations later.
                // Actually, let's trust the plan values for the critical parts.
                // If the frontend sends 3 months but plan is 90 days, it matches.
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

        return response()->json($loan, 201);
    }

    public function confirm(Request $request, $id)
    {
        $loan = Loan::findOrFail($id);
        
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
        $loan = Loan::findOrFail($id);

        if ($loan->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Can only cancel if not yet DISBURSED and not already REJECTED/CANCELLED
        if (in_array($loan->status, ['DISBURSED', 'REJECTED', 'CANCELLED'])) {
            return response()->json(['error' => 'Cannot cancel loan in current status'], 400);
        }

        $loan->status = 'CANCELLED';
        $loan->save();

        return response()->json(['message' => 'Loan application cancelled successfully']);
    }

    public function index()
    {
        return response()->json(Loan::where('user_id', Auth::id())->orderBy('created_at', 'desc')->get());
    }

    public function proceed(Request $request, $id)
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $loan = Loan::findOrFail($id);
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

        $loan = Loan::findOrFail($id);
        
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
        $loan = Loan::findOrFail($id);
        
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

        $loan = Loan::findOrFail($id);
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

        $loan = Loan::findOrFail($id);
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
            // Dynamic Logic
            $processingFee = $plan->processing_fee;
            $loginFee = $plan->application_fee;
            $fieldKycFee = $plan->other_fee;
            // Interest... currently ignored in repayment breakdown of fees, but might affect total payable?
            // The original logic didn't seem to apply interest rate to the fees, but total payable.
            // Original logic: Total Payable = Loan Amount + Fees + GST.
            // Wait, does interest rate increase the total payable?
            // The original code: $totalPayable = $loan->amount + $totalFees;
            // It treated the loan as 0% interest effectively or included interest in the "Fees" implicitly for some?
            // "12% Monthly" plan in seeder would imply significant interest.
            
            // If plan has interest > 0, we should add it.
            // Simple interest for now as per "Total Payable" concept often used here.
            $interestAmount = 0;
            if ($plan->interest_rate > 0) {
                // Monthly interest
                $months = $plan->tenure_days / 30;
                $interestAmount = ($amount * ($plan->interest_rate / 100)) * $months;
            }
            
            $gstAmount = round($amount * 0.18); // Kept as per original logic (18% of Principal?!)
            
            $totalFees = $processingFee + $loginFee + $fieldKycFee + $gstAmount + $interestAmount;
            $totalPayable = $loan->amount + $totalFees;
            
        } else {
            // Legacy Logic (Hardcoded Fallback)
            $processingFee = $loan->amount == 10000 ? 0 : 1200;
            $loginFee = $loan->amount == 10000 ? 300 : 200;
            $fieldKycFee = $loan->amount == 10000 ? 500 : 600;
            $gstAmount = round($loan->amount * 0.18);
            
            $totalFees = $processingFee + $loginFee + $fieldKycFee + $gstAmount;
            $totalPayable = $loan->amount + $totalFees;
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
        if ($plan) {
            // Use plan days if available
            $totalDays = $plan->tenure_days;
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
        return response()->json(Loan::with('user')->whereNotIn('status', ['DISBURSED', 'REJECTED'])->orderBy('created_at', 'desc')->get());
    }

    public function listHistory()
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        return response()->json(Loan::with('user')->whereIn('status', ['DISBURSED', 'REJECTED', 'CANCELLED', 'CLOSED'])->orderBy('created_at', 'desc')->get());
    }
}
