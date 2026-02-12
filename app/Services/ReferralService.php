<?php

namespace App\Services;

use App\Models\User;
use App\Models\SubUser;
use App\Models\UserReferral;
use App\Models\ReferralSetting;
use App\Models\SubUserTransaction;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Log;

class ReferralService
{
    protected $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }

    /**
     * Handle referral linking that happens after initial registration
     * (e.g. during KYC or form submission)
     */
    public function processLateReferralLinking(User $user, string $code)
    {
        $code = strtoupper(trim($code));
        
        // 1. Check if it's an Agent (SubUser) Referral Code
        $subUser = SubUser::where('referral_code', $code)->where('is_active', true)->first();
        if ($subUser) {
            // Only link if not already linked to an agent or referrer
            if (!$user->sub_user_id && !UserReferral::where('referred_id', $user->id)->exists()) {
                $user->sub_user_id = $subUser->id;
                $user->save();
                Log::info("Late Linking: User {$user->id} linked to Agent {$subUser->id}");
                
                // If user is already onboarded, grant agent bonus now
                if ($user->is_onboarded) {
                    $this->grantAgentSignupBonus($subUser, $user);
                }
                return true;
            }
        }

        // 2. Check if it's a regular User Referral Code
        $referrer = User::where('my_referral_code', $code)->first();
        if ($referrer && $referrer->id !== $user->id) {
            // Only link if not already linked to an agent or referrer
            if (!$user->sub_user_id && !UserReferral::where('referred_id', $user->id)->exists()) {
                UserReferral::create([
                    'referrer_id' => $referrer->id,
                    'referred_id' => $user->id,
                    'referral_code' => $code,
                    'signup_bonus_earned' => 0, 
                    'signup_bonus_paid' => false,
                ]);
                Log::info("Late Linking: User {$user->id} linked to Referrer {$referrer->id}");

                // If user is already onboarded, grant referrer bonus now
                if ($user->is_onboarded) {
                    $this->grantUserSignupBonus($referrer, $user);
                }
                return true;
            }
        }

        return false;
    }

    /**
     * Grant signup reward to an Agent (SubUser)
     */
    public function grantAgentSignupBonus(SubUser $subUser, User $user)
    {
        // Check if agent already received bonus for this user
        $alreadyBonus = SubUserTransaction::where('sub_user_id', $subUser->id)
            ->where('reference_id', 'USER_' . $user->id)
            ->exists();

        if (!$alreadyBonus) {
            $settings = ReferralSetting::first();
            
            // Use agent-specific reward if set, else global default
            $agentBonus = (float)($subUser->default_signup_amount > 0 ? $subUser->default_signup_amount : ($settings->agent_signup_bonus ?? 50.00));

            if ($agentBonus > 0) {
                try {
                    $this->walletService->creditSubUser(
                        $subUser->id,
                        $agentBonus,
                        "Signup Reward for user: {$user->name} (#{$user->id})",
                        'USER_' . $user->id
                    );
                    Log::info("Agent Bonus Granted: Agent {$subUser->id} for User {$user->id}");
                } catch (\Exception $e) {
                    Log::error("Failed to grant Agent Bonus: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Grant signup reward to a regular User (Referrer)
     */
    public function grantUserSignupBonus(User $referrer, User $referred)
    {
        $referralRecord = UserReferral::where('referrer_id', $referrer->id)
            ->where('referred_id', $referred->id)
            ->first();

        if ($referralRecord && !$referralRecord->signup_bonus_paid) {
            $settings = ReferralSetting::first();
            $bonusAmount = (float)($settings ? $settings->signup_bonus : 100.00);

            if ($bonusAmount > 0) {
                try {
                    $this->walletService->transferSystemFunds(
                        $referrer->id,
                        $bonusAmount,
                        'REFERRAL_BONUS',
                        "Referral signup bonus for inviting {$referred->name}",
                        'OUT'
                    );

                    $referralRecord->signup_bonus_earned = $bonusAmount;
                    $referralRecord->signup_bonus_paid = true;
                    $referralRecord->signup_bonus_paid_at = now();
                    $referralRecord->save();
                    
                    Log::info("User Bonus Granted: Referrer {$referrer->id} for User {$referred->id}");
                } catch (\Exception $e) {
                    Log::error("Failed to grant User Bonus: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Grant loan disbursement reward to a regular User (Referrer)
     */
    public function grantLoanDisbursementBonus(User $referrer, User $referred, $loanId)
    {
        $referralRecord = UserReferral::where('referrer_id', $referrer->id)
            ->where('referred_id', $referred->id)
            ->first();

        if ($referralRecord && !$referralRecord->loan_bonus_paid) {
            $settings = ReferralSetting::first();
            $bonusAmount = (float)($settings ? $settings->loan_disbursement_bonus : 250.00);

            if ($bonusAmount > 0) {
                try {
                    $this->walletService->transferSystemFunds(
                        $referrer->id,
                        $bonusAmount,
                        'LOAN_REFERRAL_BONUS',
                        "Referral bonus for loan disbursement of {$referred->name} (#Loan: {$loanId})",
                        'OUT'
                    );

                    $referralRecord->loan_bonus_earned = $bonusAmount;
                    $referralRecord->loan_bonus_paid = true;
                    $referralRecord->loan_bonus_paid_at = now();
                    $referralRecord->save();
                    
                    Log::info("Loan Bonus Granted: Referrer {$referrer->id} for User {$referred->id} (Loan {$loanId})");
                } catch (\Exception $e) {
                    Log::error("Failed to grant Loan Bonus: " . $e->getMessage());
                }
            }
        }
    }
}
