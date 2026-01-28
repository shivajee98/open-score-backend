<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantCashbackTier extends Model
{
    protected $fillable = [
        'tier_name',
        'min_turnover',
        'max_turnover',
        'cashback_min',
        'cashback_max',
        'is_active'
    ];

    protected $casts = [
        'min_turnover' => 'decimal:2',
        'max_turnover' => 'decimal:2',
        'cashback_min' => 'decimal:2',
        'cashback_max' => 'decimal:2',
        'is_active' => 'boolean'
    ];

    public function cashbacks()
    {
        return $this->hasMany(MerchantCashback::class, 'tier_id');
    }
}
