<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\AuthService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
            'role' => 'nullable|string|in:CUSTOMER,MERCHANT,ADMIN',
            'referral_code' => 'nullable|string'
        ]);

        $mobile = $request->mobile_number;
        $otp = $request->otp;
        $role = $request->role; // REMOVE DEFAULT CUSTOMER
        $referralCode = $request->referral_code;

        // Static Admin Check
        if ($role === 'ADMIN') {
            $user = \App\Models\User::where('mobile_number', $mobile)->where('role', 'ADMIN')->first();

            // Allow generic OTP '123456' for any ADMIN user
            if ($user && $otp === '123456') {
                 // Valid Admin
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
                                  
                  // Handle Referral Logic for New User
                  $referralCampaignId = null;
                  $subUserId = null;
                  $cashbackAmount = 0;
                  $referrerId = null; // Track who referred this user
                  
                  if ($referralCode) {
                     // First priority: Check if it's a user's personal referral code
                     $referrer = \App\Models\User::where('my_referral_code', strtoupper($referralCode))->first();
                     
                     if ($referrer) {
                         // This is a personal referral - will create UserReferral record after user creation
                         $referrerId = $referrer->id;
                         
                         // Get signup bonus from referral settings
                         $referralSettings = \App\Models\ReferralSetting::first();
                         if ($referralSettings && $referralSettings->is_enabled) {
                             $cashbackAmount = $referralSettings->signup_bonus;
                         }
                     } else {
                         // Check if it's a sub-user referral code
                         $subUser = \App\Models\SubUser::where('referral_code', $referralCode)
                             ->where('is_active', true)
                             ->first();
                         
                         if ($subUser) {
                             $subUserId = $subUser->id;
                             $cashbackAmount = $subUser->default_signup_amount;
                         } else {
                             // Check regular referral campaign
                             $campaign = \App\Models\ReferralCampaign::where('code', $referralCode)
                                 ->where('is_active', true)
                                 ->first();
                             if ($campaign) {
                                 $referralCampaignId = $campaign->id;
                                 $cashbackAmount = $campaign->cashback_amount;
                             }
                         }
                     }
                  } else {
                      // Get default signup cashback from settings
                      $cashbackSetting = \App\Models\SignupCashbackSetting::where('role', $role)
                          ->where('is_active', true)
                          ->first();
                      if ($cashbackSetting) {
                          $cashbackAmount = $cashbackSetting->cashback_amount;
                      }
                  }

                  // Create new user (Customer/Merchant)
                  $user = \App\Models\User::create([
                      'mobile_number' => $mobile,
                      'role' => $role,
                      'status' => 'ACTIVE',
                      'is_onboarded' => false,
                      'password' => bcrypt('password'),
                      'referral_campaign_id' => $referralCampaignId,
                      'sub_user_id' => $subUserId
                  ]);

                  // Create Wallet for new user
                  $walletService = app(\App\Services\WalletService::class);
                  $walletService->createWallet($user->id);

                  // Handle personal referral - Create UserReferral record
                  if ($referrerId) {
                      $referralSettings = \App\Models\ReferralSetting::first();
                      $signupBonus = $referralSettings ? $referralSettings->signup_bonus : 100;
                      
                      \App\Models\UserReferral::create([
                          'referrer_id' => $referrerId,
                          'referred_id' => $user->id,
                          'referral_code' => strtoupper($referralCode),
                          'signup_bonus_earned' => $signupBonus,
                          'signup_bonus_paid' => false
                      ]);
                      
                      // Credit signup bonus to referrer immediately
                      $referrerWallet = \App\Models\Wallet::where('user_id', $referrerId)->first();
                      if ($referrerWallet) {
                          $walletService->credit(
                              $referrerWallet->id,
                              $signupBonus,
                              'REFERRAL_SIGNUP_BONUS',
                              $user->id,
                              "Referral bonus for {$user->mobile_number} signup"
                          );
                          
                          // Mark as paid
                          \App\Models\UserReferral::where('referred_id', $user->id)->update([
                              'signup_bonus_paid' => true,
                              'signup_bonus_paid_at' => now()
                          ]);
                      }
                  }

                  // Credit Referral/Signup Bonus to new user
                  if ($cashbackAmount > 0) {
                      $wallet = \App\Models\Wallet::where('user_id', $user->id)->first();
                      
                      // If sub-user referral, deduct from sub-user credit
                      if ($subUserId && isset($subUser)) {
                          if ($subUser->credit_balance >= $cashbackAmount) {
                              $subUser->credit_balance -= $cashbackAmount;
                              $subUser->save();
                              
                              $walletService->credit(
                                  $wallet->id,
                                  $cashbackAmount,
                                  'SUB_USER_REFERRAL_BONUS',
                                  $user->id,
                                  "Welcome Bonus from sub-user: {$subUser->name}"
                              );
                          }
                      } else {
                          $walletService->credit(
                              $wallet->id,
                              $cashbackAmount,
                              $referralCampaignId ? 'REFERRAL_BONUS' : ($referrerId ? 'REFERRAL_WELCOME_BONUS' : 'SIGNUP_BONUS'),
                              $user->id,
                              $referralCampaignId ? "Welcome Bonus from code: {$referralCode}" : ($referrerId ? 'Welcome Bonus via Referral' : 'Signup Welcome Bonus')
                          );
                      }
                  }


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

        if ($user->role === 'ADMIN') {
            DB::table('admin_logs')->insert([
                'admin_id' => $user->id,
                'action' => 'login',
                'description' => "Agent Logged In",
                'created_at' => now(),
                'updated_at' => now(),
                'ip_address' => $request->ip()
            ]);
        }

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'user' => $user,
            'onboarding_status' => $user->is_onboarded ? 'COMPLETED' : 'REQUIRED'
        ]);
    }

    public function completeOnboarding(Request $request)
    {
        $user = \App\Models\User::find(Auth::id());
        
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . Auth::id(),
            'business_name' => 'nullable|string|max:255',
            'profile_image' => 'nullable|string',
            'shop_image' => 'nullable|image|max:10240', // 10MB
        ]);

        $user->name = $request->name;
        $user->email = $request->email;
        
        if ($request->has('business_name')) {
            $user->business_name = $request->business_name;
        }

        // Support direct Cloudinary URL from frontend
        if ($request->filled('profile_image')) {
            $user->profile_image = $request->profile_image;
        } 
        // Fallback to direct file upload handling
        elseif ($request->hasFile('shop_image')) {
            $path = $request->file('shop_image')->store('merchants', 'public');
            $user->profile_image = asset('storage/' . $path);
        }

        $user->is_onboarded = true;
        $user->save();

        // Ensure wallet exists
        $walletService = app(\App\Services\WalletService::class);
        $wallet = $walletService->getWallet($user->id);
        
        if (!$wallet) {
            $wallet = $walletService->createWallet($user->id);
        }

        // Generate fresh token with updated is_onboarded claim
        $token = Auth::guard('api')->login($user);

        return response()->json([
            'message' => 'Onboarding completed',
            'user' => $user,
            'access_token' => $token
        ]);
    }

    public function completeMerchantProfile(Request $request)
    {
        $user = \App\Models\User::find(Auth::id());
        
        if ($user->role !== 'MERCHANT') {
            return response()->json(['error' => 'Only merchants can update business profile.'], 403);
        }

        $request->validate([
            'business_name' => 'required|string|max:255',
            'business_nature' => 'required|string|max:255',
            'customer_segment' => 'required|string|max:255',
            'daily_turnover' => 'required|string|max:255',
            'business_address' => 'required|string',
            'pincode' => 'required|string|max:10',
            'pin' => 'required|string|digits:6',
            'pin_confirmation' => 'required|same:pin'
        ]);

        $user->business_name = $request->business_name;
        $user->business_nature = $request->business_nature;
        $user->customer_segment = $request->customer_segment;
        $user->daily_turnover = $request->daily_turnover;
        $user->business_address = $request->business_address;
        $user->pincode = $request->pincode;
        $user->is_onboarded = true;
        $user->save();

        // Set Transaction PIN
        $walletService = app(\App\Services\WalletService::class);
        $wallet = $walletService->getWallet($user->id);
        
        if (!$wallet) {
            $wallet = $walletService->createWallet($user->id);
        }

        if ($request->pin) {
            $walletService->setPin($wallet->id, $request->pin);
        }

        // Credit merchant onboarding bonus from settings
        $cashbackSetting = \App\Models\SignupCashbackSetting::where('role', 'MERCHANT')
            ->where('is_active', true)
            ->first();
        
        $bonusAmount = $cashbackSetting ? $cashbackSetting->cashback_amount : 250;
        
        if ($bonusAmount > 0) {
            $walletService->credit(
                $wallet->id, 
                $bonusAmount, 
                'ONBOARDING_BONUS', 
                $user->id, 
                'Welcome bonus for Merchant Profile Completion'
            );
        }

        // Generate fresh token with updated is_onboarded claim
        $token = Auth::guard('api')->login($user);

        return response()->json([
            'message' => 'Merchant profile completed',
            'user' => $user,
            'access_token' => $token
        ]);
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
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|unique:users,email,' . Auth::id(),
            'pincode' => 'nullable|string|max:10',
            'city' => 'nullable|string|max:255',
            'business_address' => 'nullable|string|max:500',
            'bank_name' => 'nullable|string|max:255',
            'account_number' => 'nullable|string|max:50',
            'ifsc_code' => 'nullable|string|max:20',
            'account_holder_name' => 'nullable|string|max:255',
            'business_segment' => 'nullable|string|max:255',
            'business_type' => 'nullable|string|max:255',
            'map_location_url' => 'nullable|string|max:500',
            'shop_images' => 'nullable|string', // JSON string from frontend
        ]);

        $user = \App\Models\User::find(Auth::id());
        if ($request->name) $user->name = $request->name;
        if ($request->email) $user->email = $request->email;
        if ($request->pincode) $user->pincode = $request->pincode;
        if ($request->city) $user->city = $request->city;
        if ($request->business_address) $user->business_address = $request->business_address;
        
        // Merchant Specific Fields
        if ($request->has('business_segment')) $user->business_segment = $request->business_segment;
        if ($request->has('business_type')) $user->business_type = $request->business_type;
        if ($request->has('map_location_url')) $user->map_location_url = $request->map_location_url;
        if ($request->has('shop_images')) $user->shop_images = $request->shop_images;

        // Only allow bank details update if not already set
        if (!$user->account_number) {
            if ($request->bank_name) $user->bank_name = $request->bank_name;
            if ($request->account_number) $user->account_number = $request->account_number;
            if ($request->ifsc_code) $user->ifsc_code = strtoupper($request->ifsc_code);
            if ($request->account_holder_name) $user->account_holder_name = $request->account_holder_name;
        }
        
        $user->save();

        return response()->json(['message' => 'Profile updated successfully', 'user' => $user]);
    }
}
