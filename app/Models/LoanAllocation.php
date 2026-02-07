<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanAllocation extends Model
{
    protected $fillable = [
        'loan_id',
        'user_id',
        'allocated_amount',
        'actual_disbursed',
        'status'
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
