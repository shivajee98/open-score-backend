<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanPlan extends Model
{
    protected $fillable = [
        'name',
        'amount',
        'tenure_days',
        'interest_rate',
        'processing_fee',
        'application_fee',
        'other_fee',
        'repayment_frequency',
        'cashback_amount',
        'plan_color',
        'tag_text',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'amount' => 'decimal:2',
        'interest_rate' => 'decimal:2',
        'processing_fee' => 'decimal:2',
        'application_fee' => 'decimal:2',
        'other_fee' => 'decimal:2',
        'cashback_amount' => 'decimal:2',
        'tenure_days' => 'integer',
    ];
}
