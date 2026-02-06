<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReferralSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'is_enabled',
        'signup_bonus',
        'loan_disbursement_bonus',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'signup_bonus' => 'decimal:2',
        'loan_disbursement_bonus' => 'decimal:2',
    ];
}
