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

        // FOR DEMO: Bypass verification
        $user = \App\Models\User::where('mobile_number', $request->mobile_number)->first();
        $selectedRole = $request->role ?? 'CUSTOMER';

        if (!$user) {
            // Create a demo user on the fly
            $user = \App\Models\User::create([
                'name' => 'Demo ' . ucfirst(strtolower($selectedRole)) . ' (' . $request->mobile_number . ')',
                'mobile_number' => $request->mobile_number,
                'role' => $selectedRole,
                'password' => bcrypt('password'),
            ]);
        } else {
            // Update role to match selection for demo flexibility
            $user->role = $selectedRole;
            $user->save();
        }

        // Ensure wallet exists for Transactional roles
        if (in_array($user->role, ['CUSTOMER', 'MERCHANT'])) {
            $walletService = app(\App\Services\WalletService::class);
            if (!$walletService->getWallet($user->id)) {
                $walletService->createWallet($user->id);
            }
        }

        $token = Auth::guard('api')->login($user);

        return $this->respondWithToken($token);
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

    public function listMerchants()
    {
        $merchants = \App\Models\User::where('role', 'MERCHANT')->get();
        $data = $merchants->map(function ($m) {
            $wallet = \App\Models\Wallet::where('user_id', $m->id)->first();
            return [
                'name' => $m->name,
                'wallet_uuid' => $wallet ? $wallet->uuid : null
            ];
        });
        return response()->json($data);
    }

    protected function respondWithToken($token)
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'user' => Auth::guard('api')->user()
        ]);
    }
}
