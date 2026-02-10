<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanRepayment extends Model
{
    protected $fillable = [
        'loan_id',
        'emi_number',
        'unique_emi_id',
        'amount',
        'transaction_id',
        'due_date',
        'paid_at',
        'status',
        'agent_approved_at',
        'agent_approved_by',
        'payment_mode',
        'collected_by',
        'notes',
        'proof_image',
        'is_manual_collection'
    ];

    protected $appends = ['display_id'];

    public function getDisplayIdAttribute()
    {
        return $this->unique_emi_id ?? (2606900 + $this->id);
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_approved_by');
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
