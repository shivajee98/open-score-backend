<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LoanPlan extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'amount',
        'configurations', // JSON: tenure, rate, fees, frequencies, cashback
        'plan_color',
        'tag_text',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'amount' => 'decimal:2',
        'configurations' => 'array',
    ];
}
