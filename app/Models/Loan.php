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

    protected $appends = ['display_id'];

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
