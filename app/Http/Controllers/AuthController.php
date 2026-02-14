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
            'role' => 'nullable|string|in:CUSTOMER,MERCHANT,ADMIN,SUPPORT,STUDENT',
            'referral_code' => 'nullable|string'
        ]);

        $mobile = $request->mobile_number;
        $otp = $request->otp;
        $role = $request->role; // REMOVE DEFAULT CUSTOMER
        $referralCode = $request->referral_code;
        
        \Log::info("Verify OTP Entry - Mobile: {$mobile}, Role: {$role}, Ref: {$referralCode}");

        // Normalize referral code
        if ($referralCode === 'null' || $referralCode === 'undefined' || !is_string($referralCode) || empty(trim($referralCode))) {
            $referralCode = null;
        } else {
            $referralCode = strtoupper(trim($referralCode));
        }
        
        \Log::info("Normalized Ref: " . ($referralCode ?? 'NONE'));

        // Static Admin/Support Check
        if (in_array($role, ['ADMIN', 'SUPPORT'])) {
            $user = \App\Models\User::where('mobile_number', $mobile)->where('role', $role)->first();

            // Allow generic OTP '123456' for any ADMIN/SUPPORT user
            if ($user && $otp === '123456') {
                 // Valid Admin
            } else {
                 return response()->json(['error' => 'Invalid Admin Credentials'], 401);
            }
        } else {
             // Normal User OTP Bypass (Demo)
             $user = \App\Models\User::where('mobile_number', $mobile)->first();
             
             $isNewUserSignup = !$user || (!$user->is_onboarded && !$user->referredBy()->exists());

            if (!$user || $isNewUserSignup) {
                if (!$role) {
                    return response()->json(['status' => 'NEW_USER', 'onboarding_status' => 'NEW_USER']);
                }
                                  
                // Handle Referral Logic for New User
                $subUserId = null;
                $referrerId = null;

                if ($referralCode) {
                    // Check Agent (SubUser)
                    $subUser = \App\Models\SubUser::where('referral_code', $referralCode)->where('is_active', true)->first();
                    if ($subUser) {
                        $subUserId = $subUser->id;
                        \Log::info("Referral Matched: Agent {$subUserId}");
                    } else {
                        // Check User
                        $referrer = \App\Models\User::where('my_referral_code', $referralCode)->first();
                        if ($referrer) {
                           $referrerId = $referrer->id;
                           \Log::info("Referral Matched: User {$referrerId}");
                        }
                    }
                }
                  
                // Get or Create
                $user = \App\Models\User::where('mobile_number', $mobile)->first() ?: new \App\Models\User(['mobile_number' => $mobile]);
                $user->role = $role;
                $user->status = 'ACTIVE';
                $user->is_onboarded = false;
                $user->password = bcrypt('password');
                
                if ($subUserId) $user->sub_user_id = $subUserId; 
                if (empty($user->my_referral_code)) {
                    $user->my_referral_code = strtoupper(\Illuminate\Support\Str::random(8));
                }
                $user->save();

                \Log::info("User created with ID: {$user->id}, is_onboarded: " . ($user->is_onboarded ? 'true' : 'false'));

                // Create Wallet for new user
                $walletService = app(\App\Services\WalletService::class);
                $walletService->createWallet($user->id);

                // Handle personal referral - Create UserReferral record
                if ($referrerId && $referrerId !== $user->id) {
                    \App\Models\UserReferral::firstOrCreate(
                        ['referrer_id' => $referrerId, 'referred_id' => $user->id],
                        ['referral_code' => $referralCode, 'signup_bonus_earned' => 0, 'signup_bonus_paid' => false]
                    );
                }

            } else {
                
                 // Ensure existing user has a referral code
                 if (empty($user->my_referral_code)) {
                     $user->my_referral_code = strtoupper(\Illuminate\Support\Str::random(8));
                     $user->save();
                 }

                 // If user exists but is not onboarded, update the role if provided
                 if (!$user->is_onboarded && $role) {
                     $user->role = $role;
                     $user->save();
                 }

                 // LINK EXISTING USER TO AGENT IF NOT LINKED
                 if ($referralCode && !$user->sub_user_id && !$user->referredBy()->exists()) {
                      // Check Agent (SubUser)
                      $subUser = \App\Models\SubUser::where('referral_code', $referralCode)->where('is_active', true)->first();
                      if ($subUser) {
                          $user->sub_user_id = $subUser->id;
                          $user->save();
                          \Log::info("Referral Matched (Existing): Agent {$subUser->id}");
                      } else {
                          // Check User
                          $referrer = \App\Models\User::where('my_referral_code', $referralCode)->first();
                          if ($referrer && $referrer->id !== $user->id) {
                              \App\Models\UserReferral::firstOrCreate(
                                  ['referrer_id' => $referrer->id, 'referred_id' => $user->id],
                                  ['referral_code' => $referralCode, 'signup_bonus_earned' => 0, 'signup_bonus_paid' => false]
                              );
                              \Log::info("Referral Matched (Existing): User {$referrer->id}");
                          }
                      }
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

        $user->has_app_pin = !empty($user->app_pin);

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
        
        \Log::info("Onboarding attempt for user {$user->id}", ['has_profile_image' => $request->hasFile('profile_image')]);
        
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . Auth::id(),
            'business_name' => 'nullable|string|max:255',
            'app_pin' => 'required|string|digits:4|confirmed',
            'pin' => 'nullable|string|digits:6|confirmed'
        ], [
            'email.unique' => 'This Email Address is already registered with another account.',
            'mobile_number.unique' => 'This Mobile Number is already registered with another account.'
        ]);

        $user->name = $request->name;
        $user->email = $request->email;
        
        if ($request->has('business_name')) {
            $user->business_name = $request->business_name;
        }

        // Handle profile image upload
        $imagePath = null;
        if ($request->hasFile('profile_image')) {
            $file = $request->file('profile_image');
            $mime = $file->getMimeType();
            
            if (str_starts_with($mime, 'image/')) {
                $imagePath = $file->store('profiles', 'public');
            } else {
                return response()->json([
                    'message' => 'The profile image field must be an image (JPEG, PNG, etc).',
                    'errors' => ['profile_image' => ["Invalid file type: $mime"]]
                ], 422);
            }
        } 
        elseif ($request->hasFile('shop_image')) {
            $file = $request->file('shop_image');
            $mime = $file->getMimeType();
            
            if (str_starts_with($mime, 'image/')) {
                $imagePath = $file->store('merchants', 'public');
            }
        }

        if ($imagePath) {
            $user->profile_image = $imagePath;
        }

        $user->is_onboarded = true;
        
        // Only set to MERCHANT if explicitly requested or if current process implies it
        // Do NOT force if user is already CUSTOMER/ADMIN
        if ($user->role !== 'MERCHANT' && $request->has('business_name')) {
             $user->role = 'MERCHANT';
        }
        
        if ($request->has('app_pin')) {
            $user->app_pin = $request->app_pin;
        }
        $user->save();

        // Ensure wallet exists
        $walletService = app(\App\Services\WalletService::class);
        $wallet = $walletService->getWallet($user->id);
        
        if (!$wallet) {
            $wallet = $walletService->createWallet($user->id);
        }

        if ($request->has('pin')) {
            $walletService->setPin($wallet->id, $request->pin);
        }

        // Generate fresh token with updated is_onboarded claim
        $token = Auth::guard('api')->login($user);

        // CREDIT SIGNUP BONUS FOR CUSTOMER/STUDENT (Auto-Transfer)
        if ($user->role === 'CUSTOMER' || $user->role === 'STUDENT') {
            // Fetch settings - New Logic: Always use SignupCashbackSetting for the NEW USER
            // ReferralSetting->signup_bonus is for the REFERRER, not the new user.
            $cashbackSetting = \App\Models\SignupCashbackSetting::where('role', $user->role)
                ->where('is_active', true)
                ->first();
            
            // Use SignupCashbackSetting as primary source for the user's welcome bonus
            $bonusAmount = ($cashbackSetting) ? $cashbackSetting->cashback_amount : 50;
            
            // Only use ReferralSetting if we absolutely want to override for referred users, 
            // but the user requirement is "both will get set amount as set by admin", meaning
            // New User gets "Signup Bonus" (10rs), Referrer gets "Referral Bonus" (90rs).
            // So we strictly stick to $cashbackSetting for the new user.
            
            if ($bonusAmount > 0) {
                // Check if already credited
                $exists = \App\Models\WalletTransaction::where('wallet_id', $wallet->id)
                    ->where('type', 'CREDIT')
                    ->whereIn('source_type', ['SIGNUP_BONUS', 'ONBOARDING_BONUS'])
                    ->exists();

                if (!$exists) {
                    $walletService->transferSystemFunds(
                        $user->id,
                        $bonusAmount,
                        'SIGNUP_BONUS',
                        'Welcome bonus for New Customer',
                        'OUT'
                    );
                }
            }

            // REFERRAL BONUS (User-to-User only)
            $referralService = app(\App\Services\ReferralService::class);

            // 2. Referrer User Bonus
            $referralRecord = \App\Models\UserReferral::where('referred_id', $user->id)->first();
            if ($referralRecord) {
                $referrer = \App\Models\User::find($referralRecord->referrer_id);
                if ($referrer) {
                    $referralService->grantUserSignupBonus($referrer, $user);
                }
            }
        }

        // MERCHANT BONUS IS DEFERRED TO 'completeMerchantProfile' (Claim Action)
        // Previously acted here, now removed.

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
            'pincode' => 'required|string|max:10'
        ], [
            'email.unique' => 'This Email Address is already registered with another account.',
            'mobile_number.unique' => 'This Mobile Number is already registered with another account.'
        ]);

        $user->business_name = $request->business_name;
        $user->business_nature = $request->business_nature;
        $user->customer_segment = $request->customer_segment;
        $user->daily_turnover = $request->daily_turnover;
        $user->business_address = $request->business_address;
        $user->pincode = $request->pincode;
        $user->is_onboarded = true;
        $user->save();

        // Ensure wallet exists
        $walletService = app(\App\Services\WalletService::class);
        $wallet = $walletService->getWallet($user->id);
        
        if (!$wallet) {
            $wallet = $walletService->createWallet($user->id);
        }

        // Credit merchant onboarding bonus from settings
        $cashbackSetting = \App\Models\SignupCashbackSetting::where('role', 'MERCHANT')
            ->where('is_active', true)
            ->first();
        
        $bonusAmount = $cashbackSetting ? $cashbackSetting->cashback_amount : 250;
        
        if ($bonusAmount > 0) {
            // Check if already credited to avoid double-dip
            $exists = \App\Models\WalletTransaction::where('wallet_id', $wallet->id)
                ->where('type', 'CREDIT')
                ->whereIn('source_type', ['SIGNUP_BONUS', 'ONBOARDING_BONUS', 'REFERRAL_BONUS', 'REFERRAL_WELCOME_BONUS'])
                ->exists();

            if (!$exists) {
                $walletService->transferSystemFunds(
                    $user->id,
                    $bonusAmount,
                    'ONBOARDING_BONUS',
                    'Welcome bonus for Merchant Profile Completion',
                    'OUT'
                );
            }
        }

        // REFERRAL BONUS (User-to-User only, Agent cashback is on loan disbursement)
        $referralService = app(\App\Services\ReferralService::class);

        // 2. Referrer User Bonus
        $referralRecord = \App\Models\UserReferral::where('referred_id', $user->id)->first();
        if ($referralRecord) {
            $referrer = \App\Models\User::find($referralRecord->referrer_id);
            if ($referrer) {
                $referralService->grantUserSignupBonus($referrer, $user);
            }
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
        $user = Auth::guard('api')->user();

        if (empty($user->my_referral_code)) {
            $user->my_referral_code = strtoupper(\Illuminate\Support\Str::random(8));
            $user->save();
        }
        
        $user->append('active_locked_balance');
        $user->has_app_pin = !empty($user->app_pin);
        
        if (in_array($user->role, ['SUPPORT', 'SUPPORT_AGENT'])) {
            $user->load('supportCategory');
        }

        return response()->json($user);
    }

    public function setAppPin(Request $request)
    {
        $request->validate([
            'pin' => 'required|string|digits:4|confirmed'
        ]);

        $user = Auth::user();
        $user->app_pin = $request->pin; // Model cast hashed
        $user->save();

        return response()->json(['message' => 'App lock PIN set successfully']);
    }

    public function verifyAppPin(Request $request)
    {
        $request->validate([
            'pin' => 'required|string|digits:4'
        ]);

        $user = Auth::user();
        
        if (\Illuminate\Support\Facades\Hash::check($request->pin, $user->app_pin)) {
            return response()->json(['valid' => true]);
        }

        return response()->json(['valid' => false, 'message' => 'Invalid PIN'], 401);
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
            'shop_images' => 'nullable|array',
            'app_pin' => 'nullable|string|digits:4|confirmed'
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
        
        if ($request->hasFile('shop_images')) {
            $paths = [];
            foreach ($request->file('shop_images') as $image) {
                $mime = $image->getMimeType();
                if (str_starts_with($mime, 'image/')) {
                    $paths[] = $image->store('merchants', 'public');
                }
            }
            $user->shop_images = $paths; // Model cast handles array to JSON
        }

        if ($request->app_pin) {
            $user->app_pin = $request->app_pin;
        }

        // Only allow bank details update if not already set
        // Bank Details Update
        // Check uniqueness if changing account number
        if ($request->account_number || $request->ifsc_code) {
             $checkAcc = $request->account_number ?: $user->account_number;
             $checkIfsc = strtoupper($request->ifsc_code ?: $user->ifsc_code);

             if ($checkAcc && $checkIfsc) {
                 $exists = \App\Models\User::where('account_number', $checkAcc)
                     ->where('ifsc_code', $checkIfsc)
                     ->where('id', '!=', Auth::id())
                     ->exists();
                 
                 if ($exists) {
                     return response()->json([
                         'error' => 'This bank account is already associated with another account. Please use your own unique bank details.'
                     ], 422);
                 }
             }
        }

        if ($request->bank_name) $user->bank_name = $request->bank_name;
        if ($request->account_number) $user->account_number = $request->account_number;
        if ($request->ifsc_code) $user->ifsc_code = strtoupper($request->ifsc_code);
        if ($request->account_holder_name) $user->account_holder_name = $request->account_holder_name;
        
        $user->save();

        return response()->json(['message' => 'Profile updated successfully', 'user' => $user]);
    }
    public function agentLogin(Request $request)
    {
        $request->validate([
            'mobile_number' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = \App\Models\User::where('mobile_number', $request->mobile_number)
        ->whereIn('role', ['SUPPORT', 'SUPPORT_AGENT'])
        ->first();

        if (!$user || !\Illuminate\Support\Facades\Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        if ($user->status === 'INACTIVE') {
            return response()->json(['message' => 'Account is inactive'], 403);
        }

        $token = Auth::guard('api')->login($user);

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'user' => $user->load('supportCategory'),
        ]);
    }

    public function markWelcomeBonusSeen()
    {
        $user = Auth::guard('api')->user();
        $user->has_seen_welcome_bonus = true;
        $user->save();
        return response()->json(['message' => 'Marked as seen.']);
    }
}
