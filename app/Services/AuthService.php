<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Tymon\JWTAuth\Facades\JWTAuth;
use Exception;
use Illuminate\Support\Facades\Log;

class AuthService
{
    public function requestOtp(string $mobile): string
    {
        // 1. Find User
        $user = User::where('mobile_number', $mobile)->first();
        
        // 2. Generate OTP
        $otp = (string) rand(100000, 999999);
        
        if ($user) {
            // 3. Save to user if exists
            $user->otp = $otp; 
            $user->otp_expires_at = Carbon::now()->addMinutes(10);
            $user->save();
        } else {
            // 3b. Store in Cache for new users
            \Illuminate\Support\Facades\Cache::put('otp_'.$mobile, $otp, now()->addMinutes(10));
        }
        
        // 4. Log OTP (Simulating SMS)
        Log::info("OTP for {$mobile}: {$otp}");
        
        return $otp; 
    }

    public function verifyOtp(string $mobile, string $otp): ?string
    {
        $user = User::where('mobile_number', $mobile)->first();
        
        if (!$user) return null;
        
        if ($user->otp !== $otp) {
            return null;
        }
        
        if (Carbon::now()->greaterThan($user->otp_expires_at)) {
            return null;
        }
        
        // Clear OTP
        $user->otp = null;
        $user->otp_expires_at = null;
        $user->save();
        
        // Ensure wallet exists for this user
        $walletService = new WalletService();
        $walletService->createWallet($user->id);

        // Generate Token
        $token = JWTAuth::fromUser($user);
        
        return $token;
    }
}
