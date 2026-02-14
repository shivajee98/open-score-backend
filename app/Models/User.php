<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

use Illuminate\Database\Eloquent\Casts\Attribute;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'mobile_number',
        'fcm_token',
        'role',
        'my_referral_code',
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
        'assigned_user_ids',
        'sub_user_id',
        'business_segment',
        'business_type',
        'shop_images',
        'map_location_url',
        'location_url',
        'description',
        'aadhar_number',
        'pan_number',
        'support_category_id',
        'app_pin'
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'otp',
        'otp_expires_at',
        'app_pin'
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'app_pin' => 'hashed',
            'otp_expires_at' => 'datetime',
            'is_onboarded' => 'boolean',
            'shop_images' => 'array',
        ];
    }

    protected function profileImage(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if (!$value) return null;
                if (filter_var($value, FILTER_VALIDATE_URL)) return $value;
                return asset('storage/' . $value);
            },
        );
    }

    protected function shopImages(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if (!$value) return [];
                $images = is_array($value) ? $value : json_decode($value, true);
                if (!$images) return [];
                
                return array_map(function($path) {
                    if (filter_var($path, FILTER_VALIDATE_URL)) return $path;
                    return asset('storage/' . $path);
                }, $images);
            },
        );
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

    protected $appends = [];

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

    // Users I have referred
    public function referredUsers()
    {
        return $this->hasMany(UserReferral::class, 'referrer_id');
    }

    // My referral record (who referred me)
    public function referredBy()
    {
        return $this->hasOne(UserReferral::class, 'referred_id');
    }

    public function subUser()
    {
        return $this->belongsTo(SubUser::class);
    }

    public function supportCategory()
    {
        return $this->belongsTo(SupportCategory::class);
    }
}
