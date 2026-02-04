<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SubUser;
use App\Models\User;
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
        
        $customerCount = $referredUsers->where('role', 'CUSTOMER')->count();
        $merchantCount = $referredUsers->where('role', 'MERCHANT')->count();

        // Loans Stats
        $loans = Loan::whereIn('user_id', $referredUserIds)->get();
        $totalLoansCount = $loans->count();
        $approvedLoansCount = $loans->where('status', 'APPROVED')->count();
        $disbursedLoansCount = $loans->where('status', 'DISBURSED')->count();
        $totalLoanVolume = $loans->whereIn('status', ['APPROVED', 'DISBURSED', 'CLOSED'])->sum('amount');

        // Recent Activity
        $recentUsers = User::where('sub_user_id', $id)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $recentTransactions = \App\Models\WalletTransaction::whereIn('user_id', $referredUserIds)
            ->with('user:id,name,business_name,role')
            ->orderBy('created_at', 'desc')
            ->limit(15)
            ->get();

        $recentLoans = Loan::whereIn('user_id', $referredUserIds)
            ->with('user:id,name,business_name,role')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();
        
        return response()->json([
            'sub_user' => $subUser,
            'stats' => [
                'total_users' => $referredUsers->count(),
                'customers' => $customerCount,
                'merchants' => $merchantCount,
                'loans' => [
                    'total' => $totalLoansCount,
                    'approved' => $approvedLoansCount,
                    'disbursed' => $disbursedLoansCount,
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
        $newBalance = $subUser->credit_balance + $request->amount;

        if ($newBalance > $subUser->credit_limit) {
            return response()->json(['error' => 'Credit amount exceeds limit'], 400);
        }

        $subUser->credit_balance = $newBalance;
        $subUser->save();

        return response()->json(['message' => 'Credit added successfully', 'sub_user' => $subUser]);
    }

    public function login(Request $request)
    {
        $request->validate([
            'mobile_number' => 'required|string',
            'otp' => 'required|string'
        ]);

        $subUser = SubUser::where('mobile_number', $request->mobile_number)->first();

        // Allow generic OTP '123456' for any Agent for demo
        if (!$subUser || (string)$request->otp !== '123456') {
            return response()->json(['error' => 'Invalid Agent Credentials'], 401);
        }

        if (!$subUser->is_active) {
            return response()->json(['error' => 'Account is inactive'], 403);
        }

        $token = Auth::guard('sub-user')->login($subUser);

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'sub_user' => $subUser
        ]);
    }

    public function getReferralStats($id)
    {
        $subUser = SubUser::findOrFail($id);
        $referredUsers = User::where('sub_user_id', $id)->get();
        
        $stats = [
            'total_referrals' => $referredUsers->count(),
            'total_amount_spent' => $referredUsers->sum('signup_cashback_received'),
            'credit_balance' => $subUser->credit_balance,
            'credit_limit' => $subUser->credit_limit
        ];

        return response()->json($stats);
    }
}
