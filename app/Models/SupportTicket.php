<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupportTicket extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'unique_ticket_id',
        'subject',
        'issue_type',
        'status',
        'payment_status',
        'payment_amount',
        'priority',
        'assigned_to',
        'category_id',
        'agent_approved_at',
        'agent_approved_by',
        'admin_approved_at',
        'admin_approved_by',
        'rejection_reason',
    ];

    protected $appends = ['display_id'];

    public function getDisplayIdAttribute()
    {
        if ($this->unique_ticket_id) {
            return $this->unique_ticket_id;
        }

        $prefixMap = [
            'emi_payment' => 'EMI',
            'wallet_topup' => 'WAL',
            'services' => 'SVC',
        ];
        $prefix = $prefixMap[$this->issue_type] ?? 'GEN';
        return "TKT-{$prefix}-{$this->id}";
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function assignedAgent()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function approvedByAgent()
    {
        return $this->belongsTo(User::class, 'agent_approved_by');
    }

    public function approvedByAdmin()
    {
        return $this->belongsTo(User::class, 'admin_approved_by');
    }

    public function messages()
    {
        return $this->hasMany(TicketMessage::class);
    }

    public function category()
    {
        return $this->belongsTo(SupportCategory::class);
    }
}
