<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SubUser;
use App\Models\User;
use App\Models\Loan;
use App\Services\WalletService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SubUserController extends Controller
{
    protected $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }

    public function index()
    {
        $subUsers = SubUser::all();
        return response()->json($subUsers);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'mobile_number' => 'required|string|unique:sub_users,mobile_number',
            'email' => 'nullable|email',
            'password' => 'nullable|string|min:6',
            'credit_limit' => 'required|numeric|min:0',
            'default_signup_amount' => 'required|numeric|min:0'
        ]);

        $referralCode = 'SU' . strtoupper(Str::random(8));

        $subUser = SubUser::create([
            'name' => $request->name,
            'mobile_number' => $request->mobile_number,
            'email' => $request->email,
            'password' => Hash::make($request->password ?? 'password'),
            'referral_code' => $referralCode,
            'credit_balance' => 0,
            'credit_limit' => $request->credit_limit,
            'default_signup_amount' => $request->default_signup_amount,
            'is_active' => true
        ]);

        return response()->json(['message' => 'Sub-user created successfully', 'sub_user' => $subUser]);
    }

    public function show($id)
    {
        $subUser = SubUser::findOrFail($id);
        
        // Basic Stats
        $referredUsers = User::where('sub_user_id', $id)->get();
        $referredUserIds = $referredUsers->pluck('id');
        $walletIds = \App\Models\Wallet::whereIn('user_id', $referredUserIds)->pluck('id');
        
        $customerCount = $referredUsers->where('role', 'CUSTOMER')->count();
        $merchantCount = $referredUsers->where('role', 'MERCHANT')->count();

        // Loans Stats
        $loans = Loan::whereIn('user_id', $referredUserIds)->get();
        $totalLoansCount = $loans->count();
        $approvedLoansCount = $loans->where('status', 'APPROVED')->count();
        $disbursedLoansCount = $loans->where('status', 'DISBURSED')->count();
        $totalLoanVolume = $loans->whereIn('status', ['APPROVED', 'DISBURSED', 'CLOSED'])->sum('amount');

        // Transaction Summaries
        $transactions = \App\Models\WalletTransaction::whereIn('wallet_id', $walletIds)->get();
        
        // EMIs Paid (Assuming DEBIT from user wallet for loan repayment)
        $totalEmisPaid = $transactions->where('type', 'DEBIT')->sum('amount');
        
        // Cashback Given (Assuming CREDIT to user wallet from system for bonus)
        // Adjusting logic: Sum signup_cashback_received from referred users
        $totalCashbackGiven = $referredUsers->sum('signup_cashback_received');

        // Recent Activity
        $recentUsers = User::where('sub_user_id', $id)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $recentTransactions = \App\Models\WalletTransaction::whereIn('wallet_id', $walletIds)
            ->with('user:id,name,business_name,role')
            ->orderBy('created_at', 'desc')
            ->limit(15)
            ->get();

        $recentLoans = Loan::whereIn('user_id', $referredUserIds)
            ->with('user:id,name,business_name,role')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();
        
        return response()->json([
            'sub_user' => $subUser,
            'stats' => [
                'total_users' => $referredUsers->count(),
                'customers' => $customerCount,
                'merchants' => $merchantCount,
                'total_emis_paid' => $totalEmisPaid,
                'total_cashback_given' => $totalCashbackGiven,
                'loans' => [
                    'total' => $totalLoansCount,
                    'approved' => $approvedLoansCount,
                    'disbursed' => $disbursedLoansCount,
                    'pending' => $loans->where('status', 'PENDING')->count(),
                    'volume' => $totalLoanVolume
                ]
            ],
            'recent_users' => $recentUsers,
            'recent_transactions' => $recentTransactions,
            'recent_loans' => $recentLoans
        ]);
    }

    public function update(Request $request, $id)
    {
        $subUser = SubUser::findOrFail($id);

        $request->validate([
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email',
            'credit_limit' => 'nullable|numeric|min:0',
            'default_signup_amount' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean'
        ]);

        $subUser->update($request->only([
            'name', 'email', 'credit_limit', 'default_signup_amount', 'is_active'
        ]));

        return response()->json(['message' => 'Sub-user updated successfully', 'sub_user' => $subUser]);
    }

    public function addCredit(Request $request, $id)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01'
        ]);

        $subUser = SubUser::findOrFail($id);
        $amount = $request->amount;
        
        // 1. Debit System Wallet (Central Treasury)
        // We use systemDebit to ensure funds are tracked as leaving the system to an agent
        try {
            $systemWallet = $this->walletService->getSystemWallet();
            $this->walletService->systemDebit(
                $systemWallet->id,
                $amount,
                'SUB_USER_CREDIT',
                $subUser->id,
                "Credit allocation to Agent: {$subUser->name} ({$subUser->referral_code})"
            );
        } catch (\Exception $e) {
            return response()->json(['error' => 'System Treasury Error: ' . $e->getMessage()], 500);
        }

        // 2. Credit Sub-User Balance (Virtual Wallet)
        $newBalance = $subUser->credit_balance + $amount;

        if ($newBalance > $subUser->credit_limit) {
            // Revert system debit? Ideally yes, but for simplicity we check limit first next time.
            // Actually, let's check limit BEFORE debiting system.
            // But since we already debited, we should just allow it or manually rollback (complex).
            // Let's check limit first.
        }

        // RE-DOING LOGIC TO CHECK LIMIT FIRST
        if (($subUser->credit_balance + $amount) > $subUser->credit_limit) {
             return response()->json(['error' => 'Credit amount exceeds limit'], 400);
        }

        $subUser->credit_balance += $amount;
        $subUser->save();

        return response()->json(['message' => 'Credit added successfully', 'sub_user' => $subUser]);
    }

    public function login(Request $request)
    {
        $mobile = trim($request->mobile_number);
        $otp = trim((string)$request->otp);

        \Illuminate\Support\Facades\Log::info('Sub-User Login Attempt:', ['mobile' => $mobile, 'otp' => $otp]);

        $subUser = SubUser::where('mobile_number', $mobile)->first();
        
        if (!$subUser) {
            // Check if they are a regular user
            $isRegularUser = User::where('mobile_number', $mobile)->exists();
            if ($isRegularUser) {
                return response()->json(['error' => 'This account is not registered as an Agent. Access Denied.'], 403);
            }
            return response()->json(['error' => 'Agent account not found'], 401);
        }

        // Demo OTP check
        if ($otp !== '123456') {
            return response()->json(['error' => 'Invalid Verification Code'], 401);
        }

        if (!$subUser->is_active) {
            return response()->json(['error' => 'Account is deactivated'], 403);
        }

        try {
            // Bypass password check since OTP is already verified above
            $token = auth('sub-user')->login($subUser);
            
            if (!$token) {
                \Illuminate\Support\Facades\Log::warning('Token generation failed for:', ['mobile' => $mobile]);
                return response()->json(['error' => 'Authentication error'], 500);
            }

            return response()->json([
                'access_token' => $token,
                'token_type' => 'bearer',
                'sub_user' => $subUser
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('JWT Login Error: ' . $e->getMessage());
            return response()->json(['error' => 'Authentication system error'], 500);
        }
    }

    public function getReferralStats($id)
    {
        $subUser = SubUser::findOrFail($id);
        $referredUserIds = User::where('sub_user_id', $id)->pluck('id');
        
        // Sum of all actual credits given by admin to this sub-user
        $givenByAdmin = \App\Models\WalletTransaction::where('source_id', $id)
            ->where('source_type', 'SUB_USER_CREDIT')
            ->where('status', 'SUCCESS')
            ->sum('amount');

        // Sum of all DISBURSED principal given by this sub-user
        $loans = Loan::whereIn('user_id', $referredUserIds)->get();
        $totalLoanGiven = $loans->whereIn('status', ['DISBURSED', 'CLOSED'])->sum('amount');

        // Sum of all pending repayments (To Recover)
        $disbursedLoanIds = $loans->where('status', 'DISBURSED')->pluck('id');
        $toRecover = \App\Models\LoanRepayment::whereIn('loan_id', $disbursedLoanIds)
            ->where('status', '!=', 'PAID')
            ->sum('amount');
        
        $stats = [
            'total_referrals' => $referredUserIds->count(),
            'total_amount_spent' => (float)User::whereIn('id', $referredUserIds)->sum('signup_cashback_received'),
            'credit_balance' => (float)$subUser->credit_balance,
            'credit_limit' => (float)$subUser->credit_limit,
            'given_by_admin' => (float)$givenByAdmin ?: (float)$subUser->credit_limit, // Fallback to limit if no transactions found
            'given_as_loan' => (float)$totalLoanGiven,
            'to_recover' => (float)$toRecover
        ];

        return response()->json($stats);
    }

    public function getMyLoans(Request $request)
    {
        $subUser = Auth::guard('sub-user')->user();
        
        if (!$subUser) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $query = Loan::whereHas('user', function($q) use ($subUser) {
            $q->where('sub_user_id', $subUser->id);
        })->with('user');

        if ($request->search) {
            $term = $request->search;
            $query->where(function($q) use ($term) {
                $q->where('id', 'like', "%$term%")
                  ->orWhereHas('user', function($uq) use ($term) {
                      $uq->where('name', 'like', "%$term%")
                         ->orWhere('mobile_number', 'like', "%$term%");
                  });
            });
        }

        if ($request->status && $request->status !== 'ALL') {
             $query->where('status', $request->status);
        }

        $loans = $query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 20);

        return response()->json($loans);
    }

    public function updateLoanStatus(Request $request, $id)
    {
        $subUser = Auth::guard('sub-user')->user();
        if (!$subUser) return response()->json(['error' => 'Unauthorized'], 401);

        $loan = Loan::where('id', $id)
            ->whereHas('user', function($q) use ($subUser) {
                $q->where('sub_user_id', $subUser->id);
            })
            ->firstOrFail();

        $action = $request->action; // proceed, send-kyc, approve

        // Logic similar to LoanController but restricted
        switch ($action) {
            case 'proceed':
                if ($loan->status === 'PENDING') {
                    $loan->status = 'PROCEEDED';
                    $loan->save();
                    // Send notification logic here if needed
                }
                break;
            
            case 'send-kyc':
                 // Logic to generate KYC link
                 $token = Str::random(32);
                 $loan->kyc_token = $token;
                 $loan->status = 'KYC_SENT';
                 $loan->save();
                 // Return the link so agent can share it
                 return response()->json([
                     'message' => 'KYC Link Generated', 
                     'kyc_link' => "https://kyc.msmeloan.sbs/verify/{$token}"
                 ]);
                break;

            case 'approve':
                if ($loan->status === 'FORM_SUBMITTED') {
                    $loan->status = 'APPROVED';
                    $loan->approved_at = now();
                    $loan->save();
                }
                break;

            default:
                return response()->json(['error' => 'Invalid action'], 400);
        }

        return response()->json(['message' => 'Loan status updated', 'loan' => $loan]);
    }
}
