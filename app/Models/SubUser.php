<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class SubUser extends Authenticatable implements JWTSubject
{
    use Notifiable;

    protected $fillable = [
        'name',
        'mobile_number',
        'email',
        'password',
        'referral_code',
        'credit_balance',
        'credit_limit',
        'default_signup_amount',
        'is_active'
    ];

    protected $hidden = [
        'password'
    ];

    protected $casts = [
        'credit_balance' => 'decimal:2',
        'credit_limit' => 'decimal:2',
        'default_signup_amount' => 'decimal:2',
        'is_active' => 'boolean'
    ];

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [
            'role' => 'SUB_USER',
            'mobile_number' => $this->mobile_number,
            'referral_code' => $this->referral_code
        ];
    }

    public function referredUsers()
    {
        return $this->hasMany(User::class, 'sub_user_id', 'id');
    }
}
