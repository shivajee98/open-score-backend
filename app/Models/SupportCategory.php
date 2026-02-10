<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupportCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'permissions', // JSON array of allowed actions
    ];

    protected $casts = [
        'permissions' => 'array',
    ];

    public function agents()
    {
        return $this->hasMany(User::class, 'support_category_id');
    }

    public function tickets()
    {
        return $this->hasMany(SupportTicket::class, 'category_id');
    }
}
