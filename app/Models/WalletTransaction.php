<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletTransaction extends Model
{
    protected $fillable = [
        'wallet_id',
        'type',
        'amount',
        'status',
        'source_type',
        'source_id',
        'description'
    ];

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    public function sourceWallet()
    {
        return $this->belongsTo(Wallet::class, 'source_id');
    }
}
