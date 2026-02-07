<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminFund extends Model
{
    protected $fillable = [
        'total_funds',
        'available_funds'
    ];
}
