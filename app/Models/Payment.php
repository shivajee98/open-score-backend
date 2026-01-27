<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'payer_wallet_id',
        'payee_wallet_id',
        'amount',
        'status',
        'transaction_ref'
    ];
}
