<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubUserPayout extends Model
{
    use HasFactory;

    protected $fillable = [
        'sub_user_id',
        'amount',
        'status',
        'bank_details',
        'admin_message',
        'proof_image',
        'processed_by',
        'processed_at'
    ];

    protected $casts = [
        'processed_at' => 'datetime',
        'amount' => 'decimal:2'
    ];

    public function subUser()
    {
        return $this->belongsTo(SubUser::class);
    }

    public function processor()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
