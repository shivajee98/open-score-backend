<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SignupCashbackSetting extends Model
{
    protected $fillable = [
        'role',
        'cashback_amount',
        'is_active'
    ];

    protected $casts = [
        'cashback_amount' => 'decimal:2',
        'is_active' => 'boolean'
    ];
}
