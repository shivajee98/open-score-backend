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
        'disbursed_by'
    ];

    protected $casts = [
        'form_data' => 'array',
        'kyc_submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'disbursed_at' => 'datetime'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
