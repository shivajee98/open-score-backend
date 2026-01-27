<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\AuthService;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    protected $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    public function requestOtp(Request $request)
    {
        $request->validate(['mobile_number' => 'required|string']);
        $otp = $this->authService->requestOtp($request->mobile_number);
        return response()->json(['message' => 'OTP sent.', 'otp_debug' => $otp]);
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'mobile_number' => 'required|string',
            'otp' => 'required|string',
            'role' => 'nullable|string|in:CUSTOMER,MERCHANT,ADMIN'
        ]);

        $mobile = $request->mobile_number;
        $otp = $request->otp;
        $role = $request->role; // REMOVE DEFAULT CUSTOMER

        // Static Admin Check
        if ($role === 'ADMIN') {
            if ($mobile === '9478563245' && $otp === '849645') {
                 $user = \App\Models\User::where('mobile_number', $mobile)->first();
                 if (!$user || $user->role !== 'ADMIN') {
                     return response()->json(['error' => 'Admin user not found or invalid role.'], 403);
                 }
            } else {
                 return response()->json(['error' => 'Invalid Admin Credentials'], 401);
            }
        } else {
             // Normal User OTP Bypass (Demo)
             $user = \App\Models\User::where('mobile_number', $mobile)->first();
             
             if (!$user) {
                 if (!$role) {
                     return response()->json(['status' => 'NEW_USER', 'onboarding_status' => 'NEW_USER']);
                 }
                 
                 // Create new user (Customer/Merchant)
                 $user = \App\Models\User::create([
                     'mobile_number' => $mobile,
                     'role' => $role,
                     'status' => 'ACTIVE',
                     'is_onboarded' => false,
                     'password' => bcrypt('password'),
                 ]);

                 // Create Wallet for new user
                 $walletService = app(\App\Services\WalletService::class);
                 $walletService->createWallet($user->id);
             } else {
                 // If user exists but is not onboarded, update the role if provided
                 if (!$user->is_onboarded && $role) {
                     $user->role = $role;
                     $user->save();
                 }
             }
        }

        // Final Suspension Check
        if (($user->status ?? 'ACTIVE') === 'SUSPENDED') {
            return response()->json(['error' => 'Your account has been suspended. Please contact support.'], 403);
        }

        $token = Auth::guard('api')->login($user);

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'user' => $user,
            'onboarding_status' => $user->is_onboarded ? 'COMPLETED' : 'REQUIRED'
        ]);
    }

    public function completeOnboarding(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . Auth::id(),
            'business_name' => 'nullable|string|max:255',
        ]);

        $user = \App\Models\User::find(Auth::id());
        $user->name = $request->name;
        $user->email = $request->email;
        if ($request->business_name) {
            $user->business_name = $request->business_name;
        }
        $user->is_onboarded = true;
        $user->save();

        // Create Wallet NOW (not at login)
        $walletService = app(\App\Services\WalletService::class);
        if (!$walletService->getWallet($user->id)) {
            $walletService->createWallet($user->id);
        }

        return response()->json(['message' => 'Onboarding completed', 'user' => $user]);
    }

    public function me()
    {
        return response()->json(Auth::guard('api')->user());
    }

    public function logout()
    {
        Auth::guard('api')->logout();
        return response()->json(['message' => 'Successfully logged out']);
    }

    public function listPayees()
    {
        $payees = \App\Models\User::whereIn('role', ['MERCHANT', 'CUSTOMER'])
            ->where('id', '!=', Auth::id())
            ->get();
            
        $data = $payees->map(function ($p) {
            $wallet = \App\Models\Wallet::where('user_id', $p->id)->first();
            return [
                'name' => $p->name,
                'role' => $p->role,
                'wallet_uuid' => $wallet ? $wallet->uuid : null,
                'vpa' => $p->mobile_number . '@openscore'
            ];
        });
        return response()->json($data);
    }
    public function updateProfile(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . Auth::id(),
            // business_name only if merchant? Let's keep it simple for now or usage same logic as onboarding
        ]);

        $user = \App\Models\User::find(Auth::id());
        $user->name = $request->name;
        $user->email = $request->email;
        $user->save();

        return response()->json(['message' => 'Profile updated successfully', 'user' => $user]);
    }
}
