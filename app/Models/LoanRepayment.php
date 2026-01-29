<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanRepayment extends Model
{
    protected $fillable = [
        'loan_id',
        'amount',
        'due_date',
        'paid_at',
        'status'
    ];

    protected $appends = ['display_id'];

    public function getDisplayIdAttribute()
    {
        return 2606900 + $this->id;
    }

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }
}
