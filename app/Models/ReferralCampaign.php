<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReferralCampaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'cashback_amount',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'cashback_amount' => 'decimal:2',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
