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
        'pincode',
        'city',
        'otp',
        'otp_expires_at',
        'bank_name',
        'ifsc_code',
        'account_holder_name',
        'account_number',
        'cashback_percentage',
        'cashback_flat_amount',
        'referral_campaign_id',
        'location_url',
        'description'
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
            'mobile_number' => $this->mobile_number,
            'is_onboarded' => (bool)$this->is_onboarded
        ];
    }

    protected $appends = ['active_locked_balance'];

    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }

    public function loans()
    {
        return $this->hasMany(Loan::class);
    }

    public function getActiveLockedBalanceAttribute()
    {
        // Sum of all loans that are in a "Pre-Disbursal" but "Active" state
        return (float) $this->loans()
            ->whereIn('status', ['PENDING', 'PROCEEDED', 'KYC_SENT', 'FORM_SUBMITTED', 'APPROVED'])
            ->sum('amount');
    }

    public function referralCampaign()
    {
        return $this->belongsTo(ReferralCampaign::class);
    }
}
