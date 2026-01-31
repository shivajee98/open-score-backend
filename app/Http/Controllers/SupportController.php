<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Models\TicketMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class SupportController extends Controller
{
    // List tickets for the authenticated user
    public function index(Request $request)
    {
        $user = Auth::user();
        
        $tickets = SupportTicket::with(['user', 'messages' => function($q) {
                $q->latest()->limit(1); // Get latest message for preview
            }])
            ->where('user_id', $user->id)
            ->latest()
            ->paginate(15);

        return response()->json($tickets);
    }

    // List all tickets for Admin
    public function adminIndex(Request $request)
    {
        $user = Auth::user();

        $tickets = SupportTicket::with(['user', 'messages' => function($q) {
                $q->latest()->limit(1);
            }, 'assignedAgent']) // meaningful relationship
            ->when($request->status, function($q, $status) {
                return $q->where('status', $status);
            })
            // Custom Sorting: Assigned to ME first, then Unassigned, then Assigned to Others
            ->orderByRaw("
                CASE 
                    WHEN assigned_to = ? THEN 1 
                    WHEN assigned_to IS NULL THEN 2 
                    ELSE 3 
                END
            ", [$user->id])
            ->latest()
            ->paginate(20);

        return response()->json($tickets);
    }

    // Assign ticket to current agent
    public function assign(Request $request, $id)
    {
        $user = Auth::user();
        if ($user->role !== 'ADMIN') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $ticket = SupportTicket::findOrFail($id);

        if ($ticket->assigned_to && $ticket->assigned_to !== $user->id) {
             return response()->json(['message' => 'Ticket already assigned to another agent'], 409);
        }

        $ticket->assigned_to = $user->id;
        $ticket->save();

        return response()->json($ticket->load(['user', 'assignedAgent']));
    }

    // Create a new ticket
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
            'priority' => 'in:low,medium,high',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $ticket = SupportTicket::create([
            'user_id' => Auth::id(),
            'subject' => $request->subject,
            'status' => 'open',
            'priority' => $request->priority ?? 'medium',
        ]);

        // Create initial message
        $msg = TicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'user_id' => Auth::id(),
            'message' => $request->message,
            'is_admin_reply' => false,
        ]);
        
        // Broadcast event
        event(new \App\Events\MessageSent($msg));

        return response()->json($ticket->load('messages'), 201);
    }

    // Show a specific ticket with messages
    public function show($id)
    {
        $user = Auth::user();
        $ticket = SupportTicket::with(['messages.user', 'user'])->findOrFail($id);

        // Authorization check
        if ($user->role !== 'ADMIN' && $ticket->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($ticket);
    }

    // Send a message in a ticket
    public function sendMessage(Request $request, $id)
    {
        $user = Auth::user();
        $ticket = SupportTicket::findOrFail($id);

        if ($user->role !== 'ADMIN' && $ticket->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'message' => 'required|string',
            'attachment_url' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $message = TicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'message' => $request->message,
            'attachment_url' => $request->attachment_url,
            'is_admin_reply' => $user->role === 'ADMIN',
        ]);
        
        // Broadcast event
        event(new \App\Events\MessageSent($message));

        // If user replies, status maybe open/in_progress? 
        // If admin replies, we assume in_progress or resolved if they updated status separately.
        // For now, let's auto-update ticket updated_at
        $ticket->touch();

        if ($user->role === 'ADMIN' && $ticket->status === 'open') {
            $ticket->update(['status' => 'in_progress']);
        }

        return response()->json($message, 201);
    }

    // Update ticket status/priority (Admin or User closing their own ticket)
    public function updateStatus(Request $request, $id)
    {
        $user = Auth::user();
        $ticket = SupportTicket::findOrFail($id);

        if ($user->role !== 'ADMIN' && $ticket->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Users can only close tickets
        if ($user->role !== 'ADMIN' && $request->status !== 'closed') {
             return response()->json(['message' => 'Unauthorized status change'], 403);
        }

        $ticket->update([
            'status' => $request->status ?? $ticket->status,
            'priority' => ($user->role === 'ADMIN' && $request->priority) ? $request->priority : $ticket->priority,
        ]);

        return response()->json($ticket);
    }
}
