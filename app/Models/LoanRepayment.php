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
        'status',
        'payment_mode',
        'collected_by',
        'notes',
        'proof_image',
        'is_manual_collection'
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
    
    public function collector()
    {
        return $this->belongsTo(User::class, 'collected_by');
    }
}
