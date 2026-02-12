<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ReferralCampaign;
use App\Models\ReferralSetting;
use App\Models\UserReferral;
use App\Models\User;
use Illuminate\Support\Str;

class ReferralController extends Controller
{
    // Admin: Get referral settings
    public function getSettings()
    {
        $settings = ReferralSetting::first();
        if (!$settings) {
            $settings = ReferralSetting::create([
                'is_enabled' => true,
                'signup_bonus' => 100.00,
                'loan_disbursement_bonus' => 250.00,
                'agent_signup_bonus' => 50.00,
            ]);
        }
        return response()->json($settings);
    }

    // Admin: Update referral settings
    public function updateSettings(Request $request)
    {
        $request->validate([
            'is_enabled' => 'required|boolean',
            'signup_bonus' => 'required|numeric|min:0',
            'loan_disbursement_bonus' => 'required|numeric|min:0',
            'agent_signup_bonus' => 'required|numeric|min:0',
        ]);

        $settings = ReferralSetting::first();
        if (!$settings) {
            $settings = ReferralSetting::create($request->all());
        } else {
            $settings->update($request->all());
        }

        return response()->json([
            'message' => 'Settings updated successfully',
            'settings' => $settings
        ]);
    }

    // User: Get my referral code
    public function getMyReferralCode()
    {
        $user = auth()->user();
        
        // Generate referral code if doesn't exist
        if (!$user->my_referral_code) {
            $code = $this->generateUniqueReferralCode();
            $user->my_referral_code = $code;
            $user->save();
        }

        return response()->json([
            'referral_code' => $user->my_referral_code,
            'referral_link' => url('/?ref=' . $user->my_referral_code)
        ]);
    }

    // User: Get my referral stats
    public function getMyReferralStats()
    {
        $user = auth()->user();
        
        $referrals = UserReferral::where('referrer_id', $user->id)
            ->with('referred:id,name,mobile_number,created_at')
            ->get();

        $totalSignupBonus = $referrals->sum('signup_bonus_earned');
        $totalLoanBonus = $referrals->sum('loan_bonus_earned');
        $totalEarnings = $totalSignupBonus + $totalLoanBonus;

        return response()->json([
            'total_referrals' => $referrals->count(),
            'total_signup_bonus' => $totalSignupBonus,
            'total_loan_bonus' => $totalLoanBonus,
            'total_earnings' => $totalEarnings,
            'referrals' => $referrals
        ]);
    }

    // Admin: Get all referrals
    public function getAllReferrals()
    {
        $referrals = UserReferral::with(['referrer:id,name,mobile_number', 'referred:id,name,mobile_number'])
            ->latest()
            ->paginate(50);

        return response()->json($referrals);
    }

    // Verify referral code
    public function verifyCode(Request $request)
    {
        $request->validate([
            'code' => 'required|string'
        ]);

        $user = User::where('my_referral_code', strtoupper($request->code))->first();

        if (!$user) {
            return response()->json([
                'valid' => false,
                'message' => 'Invalid referral code'
            ], 404);
        }

        $settings = ReferralSetting::first();
        
        return response()->json([
            'valid' => true,
            'referrer_name' => $user->name,
            'signup_bonus' => $settings ? $settings->signup_bonus : 100
        ]);
    }

    private function generateUniqueReferralCode()
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (User::where('my_referral_code', $code)->exists());
        
        return $code;
    }

    // Old methods - keeping for backward compatibility
    public function index()
    {
        $campaigns = ReferralCampaign::withCount('users')->latest()->get();
        return response()->json($campaigns);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'code' => 'required|string|unique:referral_campaigns,code',
            'cashback_amount' => 'required|numeric|min:0'
        ]);

        $campaign = ReferralCampaign::create([
            'name' => $request->name,
            'code' => strtoupper($request->code),
            'cashback_amount' => $request->cashback_amount,
            'is_active' => true
        ]);

        return response()->json($campaign, 201);
    }

    public function show($id)
    {
        $campaign = ReferralCampaign::with('users')->findOrFail($id);
        return response()->json($campaign);
    }
}
