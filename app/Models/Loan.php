<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Loan extends Model
{
    protected $fillable = ['user_id', 'amount', 'tenure', 'payout_frequency', 'payout_option_id', 'status'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
