<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantCashback extends Model
{
    protected $fillable = [
        'merchant_id',
        'tier_id',
        'daily_turnover',
        'cashback_amount',
        'cashback_date',
        'status',
        'notes',
        'approved_by',
        'approved_at'
    ];

    protected $casts = [
        'daily_turnover' => 'decimal:2',
        'cashback_amount' => 'decimal:2',
        'cashback_date' => 'date',
        'approved_at' => 'datetime'
    ];

    public function merchant()
    {
        return $this->belongsTo(User::class, 'merchant_id');
    }

    public function tier()
    {
        return $this->belongsTo(MerchantCashbackTier::class, 'tier_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
