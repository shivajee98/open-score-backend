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
        'loan_plan_id'
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
        $interestRate = 0;
        $totalInterest = 0;
        
        // GST is always 18% of principal in current logic
        $gst = round($amount * 0.18);

        if ($plan) {
            $tenure = $this->tenure;
            // Heuristic from LoanController
            $tenureIsDays = $tenure > 6;
            $targetDays = $tenureIsDays ? $tenure : $tenure * 30;

            $config = null;
            if (is_array($plan->configurations)) {
                foreach ($plan->configurations as $conf) {
                    if (abs(($conf['tenure_days'] ?? 0) - $targetDays) <= 5) {
                        $config = $conf;
                        break;
                    }
                }
            }

            if ($config) {
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
                    } elseif (strpos($name, 'gst') === false) {
                        $otherFees += $fAmount;
                    }
                }

                $interestRate = (float)($config['interest_rate'] ?? $this->interest_rate ?? 0);
                $days = (int)($config['tenure_days'] ?? ($tenureIsDays ? $tenure : $tenure * 30));
                $months = $days / 30;
                $totalInterest = round(($amount * $interestRate / 100) * $months);
            }
        }

        $totalFees = $processingFee + $loginFee + $fieldKycFee + $otherFees;
        $totalDeductions = $totalFees + $gst + $totalInterest;
        $netPayable = $amount + $totalDeductions;

        return [
            'principal' => $amount,
            'gst' => $gst,
            'processing_fee' => $processingFee,
            'login_fee' => $loginFee,
            'field_kyc_fee' => $fieldKycFee,
            'other_fees' => $otherFees,
            'interest_rate' => $interestRate,
            'total_interest' => $totalInterest,
            'total_deductions' => $totalDeductions,
            'disbursal_amount' => $amount,
            'net_payable_amount' => $netPayable
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
}
