<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'mobile_number',
        'role',
        'business_name',
        'profile_image',
        'is_onboarded',
        'status',
        'business_nature',
        'customer_segment',
        'daily_turnover',
        'business_address',
        'otp',
        'otp_expires_at'
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'otp',
        'otp_expires_at'
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'otp_expires_at' => 'datetime',
        ];
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [
            'role' => $this->role,
            'mobile_number' => $this->mobile_number
        ];
    }

    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }
}
