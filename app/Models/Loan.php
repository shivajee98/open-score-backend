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
        'approved_at',
        'approved_by',
        'disbursed_at',
        'disbursed_by'
    ];

    protected $casts = [
        'form_data' => 'array',
        'approved_at' => 'datetime',
        'disbursed_at' => 'datetime'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
