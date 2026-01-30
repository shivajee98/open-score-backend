<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WithdrawRequest extends Model
{
    protected $fillable = [
        'user_id', 
        'wallet_id', 
        'amount', 
        'status',
        'bank_name',
        'account_number',
        'ifsc_code',
        'account_holder_name',
        'admin_note',
        'processed_by',
        'processed_at'
    ];
}
