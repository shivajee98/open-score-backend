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
        'is_public',
        'is_locked',
        'tenure_type',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_public' => 'boolean',
        'is_locked' => 'boolean',
        'amount' => 'decimal:2',
        'configurations' => 'array',
    ];

    protected $appends = [];

    public function getAssignedUserIdsAttribute()
    {
        return $this->users()->pluck('users.id')->toArray();
    }

    public function loans()
    {
        return $this->hasMany(Loan::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'loan_plan_user');
    }
}
