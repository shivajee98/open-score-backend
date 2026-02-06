<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserReferral extends Model
{
    use HasFactory;

    protected $fillable = [
        'referrer_id',
        'referred_id',
        'referral_code',
        'signup_bonus_earned',
        'signup_bonus_paid',
        'signup_bonus_paid_at',
        'loan_bonus_earned',
        'loan_bonus_paid',
        'loan_bonus_paid_at',
    ];

    protected $casts = [
        'signup_bonus_earned' => 'decimal:2',
        'signup_bonus_paid' => 'boolean',
        'signup_bonus_paid_at' => 'datetime',
        'loan_bonus_earned' => 'decimal:2',
        'loan_bonus_paid' => 'boolean',
        'loan_bonus_paid_at' => 'datetime',
    ];

    public function referrer()
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function referred()
    {
        return $this->belongsTo(User::class, 'referred_id');
    }
}
