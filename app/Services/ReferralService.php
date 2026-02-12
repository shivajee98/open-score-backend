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
            // If already linked to THIS agent, nothing more to do
            if ($user->sub_user_id == $subUser->id) {
                return true;
            }
            
            // Only link if not already linked to an agent or referrer
            if (!$user->sub_user_id && !UserReferral::where('referred_id', $user->id)->exists()) {
                $user->sub_user_id = $subUser->id;
                $user->save();
                Log::info("Late Linking: User {$user->id} linked to Agent {$subUser->id}");
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
     * Grant cashback to an Agent (SubUser) when a loan is DISBURSED.
     * Cashback amount is determined by the loan plan's configuration.
     */
    public function grantAgentDisbursementCashback(SubUser $subUser, User $user, $loan)
    {
        // Check if agent already received cashback for this specific loan
        $alreadyCashback = SubUserTransaction::where('sub_user_id', $subUser->id)
            ->where('reference_id', 'LOAN_' . $loan->id)
            ->exists();

        if ($alreadyCashback) {
            Log::info("Agent Cashback already granted: Agent {$subUser->id} for Loan {$loan->id}");
            return;
        }

        // Determine cashback from loan plan configuration
        $cashbackAmount = 0;
        $plan = $loan->plan;
        
        if ($plan && is_array($plan->configurations)) {
            $tenure = $loan->tenure;
            $tenureIsDays = $tenure > 6;
            $targetDays = $tenureIsDays ? $tenure : $tenure * 30;
            
            foreach ($plan->configurations as $conf) {
                if (abs(($conf['tenure_days'] ?? 0) - $targetDays) <= 5) {
                    // Found matching config — get cashback for this frequency
                    $cashbacks = $conf['cashback'] ?? [];
                    $cashbackAmount = (float)($cashbacks[$loan->payout_frequency] ?? 0);
                    break;
                }
            }
        }

        // Fallback to agent's default_signup_amount if no plan config cashback
        if ($cashbackAmount <= 0) {
            $settings = ReferralSetting::first();
            $cashbackAmount = (float)($subUser->default_signup_amount > 0 
                ? $subUser->default_signup_amount 
                : ($settings->agent_signup_bonus ?? 50.00));
        }

        if ($cashbackAmount > 0) {
            try {
                $this->walletService->creditSubUser(
                    $subUser->id,
                    $cashbackAmount,
                    "Loan Disbursement Cashback: {$user->name} (Loan #{$loan->id}, ₹" . number_format($loan->amount) . ")",
                    'LOAN_' . $loan->id
                );
                Log::info("Agent Disbursement Cashback Granted: Agent {$subUser->id}, ₹{$cashbackAmount} for Loan {$loan->id}");
            } catch (\Exception $e) {
                Log::error("Failed to grant Agent Disbursement Cashback: " . $e->getMessage());
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
