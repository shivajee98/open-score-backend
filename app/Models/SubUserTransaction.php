<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubUserTransaction extends Model
{
    protected $fillable = [
        'sub_user_id',
        'amount',
        'type',
        'description',
        'reference_id'
    ];

    public function subUser()
    {
        return $this->belongsTo(SubUser::class);
    }
}
