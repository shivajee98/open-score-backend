<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('support.ticket.{ticketId}', function ($user, $ticketId) {
    $ticket = \App\Models\SupportTicket::find($ticketId);
    if (!$ticket) return false;
    
    // Allow if user is owner of the ticket OR user is an ADMIN
    return (int) $user->id === (int) $ticket->user_id || $user->role === 'ADMIN';
});
