<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Loan extends Model
{
    protected $fillable = [
        'user_id', 
        'amount', 
        'tenure', 
        'payout_frequency', 
        'payout_option_id', 
        'status',
        'form_data',
        'kyc_token',
        'kyc_submitted_at',
        'paid_amount',
        'approved_at',
        'approved_by',
        'disbursed_at',
        'disbursed_by',
        'closed_at',
        'loan_plan_id',
        'kyc_sent_by'
    ];

    protected $casts = [
        'form_data' => 'array',
        'kyc_submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'disbursed_at' => 'datetime',
        'closed_at' => 'datetime'
    ];

    protected $appends = ['display_id', 'calculations'];

    public function getCalculationsAttribute()
    {
        $amount = (float) $this->amount;
        $plan = $this->relationLoaded('plan') ? $this->plan : null;
        
        // Defaults
        $processingFee = 0;
        $loginFee = 0;
        $fieldKycFee = 0;
        $otherFees = 0;
        $gst = 0;
        $interestRate = 0;
        $totalInterest = 0;
        $frequency = $this->payout_frequency;

        if ($plan) {
            $tenure = $this->tenure;
            // Heuristic from LoanController
            $tenureIsDays = $tenure > 6; // Basic heuristic
            $targetDays = $tenureIsDays ? $tenure : $tenure * 30;

            // Try to find exact config
            $config = null;
            if (is_array($plan->configurations)) {
                foreach ($plan->configurations as $conf) {
                    // Match tenure days with loose buffer
                    if (abs(($conf['tenure_days'] ?? 0) - $targetDays) <= 5) {
                        $config = $conf;
                        break;
                    }
                }
            }

            if ($config) {
                // Extract GST Rate
                $gstRate = isset($config['gst_rate']) ? (float)$config['gst_rate'] : 18;

                // FEES
                $fees = $config['fees'] ?? [];
                foreach ($fees as $fee) {
                    $fAmount = (float)($fee['amount'] ?? 0);
                    $name = strtolower($fee['name'] ?? '');
                    
                    if (strpos($name, 'processing') !== false) {
                        $processingFee += $fAmount;
                    } elseif (strpos($name, 'login') !== false) {
                        $loginFee += $fAmount;
                    } elseif (strpos($name, 'field') !== false) {
                        $fieldKycFee += $fAmount;
                    } elseif (strpos($name, 'gst') !== false) {
                        $gst += $fAmount;
                    } else {
                        $otherFees += $fAmount;
                    }
                }

                // GST Calculation based on Processing Fees
                if ($gst == 0) {
                    $gst = round($processingFee * ($gstRate / 100));
                }

                // INTEREST
                // Prioritize specific rate for frequency, then general rate, then model rate
                if (isset($config['interest_rates']) && is_array($config['interest_rates']) && isset($config['interest_rates'][$frequency])) {
                    $interestRate = (float)$config['interest_rates'][$frequency];
                } elseif (isset($config['interest_rate'])) {
                    $interestRate = (float)$config['interest_rate'];
                }

                // Interest Calculation: Principal * Rate * Months
                // We need 'months' for the rate application. 
                // Assumption: Interest Rate provided is "Per Month" unless specified otherwise? 
                // Usually these apps use monthly flat rate.
                // If frequency is '15_DAYS', rate might be 'per 15 days'?
                // Let's assume rate is per 'Tenure Unit' or standard Monthly.
                
                // If the config has specific rates for frequencies (like 15_DAYS -> 0.6%), 
                // it implies that rate applies for that period? 
                // Or is it a monthly rate adjusted?
                // Given the user said "15_DAYS": 0.6 and "MONTHLY": 0.3, it seems rate varies by freq.
                // Let's assume it IS the specific rate for the tenure duration OR monthly.
                
                $days = (int)($config['tenure_days'] ?? 30);
                $months = $days / 30;
                
                // If the rate is small (like 0.6), it's likely per month or per period.
                // Let's calculate simple interest: P * R% * T(months)
                $totalInterest = round(($amount * $interestRate / 100) * $months);
            }
        }
        
        $totalFees = $processingFee + $loginFee + $fieldKycFee + $otherFees;
        // Total Deductions = Fees + GST + Interest (if interest is deducted upfront? User said "interests missing")
        // Usually Interest is ADDED to repayment, not deducted from disbursal.
        // Fees are usually deducted.
        
        // Logic Update: Fees are financed (added to repayment), not deducted.
        // Disbursal = Amount (User gets full money)
        // Repayment = Amount + Fees + GST + Interest

        $totalDeductions = 0; // No upfront deductions
        $disbursalAmount = $amount;
        
        $netPayableByCustomer = $amount + $totalFees + $gst + $totalInterest;

        return [
            'principal' => $amount,
            'gst' => $gst,
            'gst_rate' => $gstRate ?? 18,
            'processing_fee' => $processingFee,
            'login_fee' => $loginFee,
            'field_kyc_fee' => $fieldKycFee,
            'other_fees' => $otherFees,
            'interest_rate' => $interestRate,
            'total_interest' => $totalInterest,
            'total_deductions' => $totalDeductions, // Fees + GST
            'disbursal_amount' => $disbursalAmount,
            'net_payable_amount' => $netPayableByCustomer
        ];
    }

    public function getDisplayIdAttribute()
    {
        return 2606900 + $this->id;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
    public function plan()
    {
        return $this->belongsTo(LoanPlan::class, 'loan_plan_id');
    }
    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function disburser()
    {
        return $this->belongsTo(User::class, 'disbursed_by');
    }

    public function repayments()
    {
        return $this->hasMany(LoanRepayment::class);
    }
}
