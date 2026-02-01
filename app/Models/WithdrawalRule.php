<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WithdrawalRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'loan_plan_id',
        'user_type',
        'min_spend_amount',
        'min_txn_count',
        'daily_limit',
        'target_users',
        'is_active'
    ];

    protected $casts = [
        'target_users' => 'array',
        'is_active' => 'boolean',
        'min_spend_amount' => 'decimal:2',
        'daily_limit' => 'decimal:2',
    ];

    public function loanPlan()
    {
        return $this->belongsTo(LoanPlan::class);
    }
}
