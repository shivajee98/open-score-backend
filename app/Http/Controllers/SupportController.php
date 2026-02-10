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
            ->when($request->status, function($q, $status) {
                if ($status === 'active') {
                    return $q->where('status', '!=', 'closed');
                }
                return $q->where('status', $status);
            })
            ->latest()
            ->paginate(15);

        return response()->json($tickets);
    }

    // List all tickets for Admin/Support
    public function adminIndex(Request $request)
    {
        $user = Auth::user();

        // Allow SUPPORT role
        if (!in_array($user->role, ['ADMIN', 'SUPPORT', 'SUPPORT_AGENT'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $tickets = SupportTicket::with(['user', 'messages' => function($q) {
                $q->latest()->limit(1);
            }, 'assignedAgent']) // meaningful relationship
            ->when($request->status, function($q, $status) {
                if ($status === 'active') {
                    return $q->where('status', '!=', 'closed');
                }
                return $q->where('status', $status);
            })
            ->when(in_array($user->role, ['SUPPORT', 'SUPPORT_AGENT']), function($q) use ($user) {
                return $q->where(function($sq) use ($user) {
                    $sq->where('assigned_to', $user->id)
                       ->orWhere(function($ssq) use ($user) {
                           $ssq->whereNull('assigned_to');
                           if ($user->role === 'SUPPORT_AGENT' && $user->support_category_id) {
                               $ssq->where('category_id', $user->support_category_id);
                           }
                       });
                });
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
        if (!in_array($user->role, ['ADMIN', 'SUPPORT', 'SUPPORT_AGENT'])) {
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
            'issue_type' => 'required|string',
            'message' => 'required|string',
            'priority' => 'in:low,medium,high',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Routing Logic
        $assignedTo = null;
        $categoryId = null;
        $issueType = $request->issue_type;

        $slugMap = [
            'cashback_not_received' => 'cashback_issue',
            'unable_to_transfer'    => 'transfer_emi_issue',
            'loan'                  => 'loan_kyc_other',
            'general'               => 'loan_kyc_other', // Default
        ];

        $targetSlug = $slugMap[$issueType] ?? $issueType;

        if (is_numeric($issueType)) {
            $cat = \App\Models\SupportCategory::find($issueType);
        } else {
            $cat = \App\Models\SupportCategory::where('slug', $targetSlug)->first();
        }

        if ($cat) {
             $categoryId = $cat->id;
        }

        $ticket = SupportTicket::create([
            'user_id' => Auth::id(),
            'subject' => $request->subject,
            'issue_type' => $issueType,
            'status' => 'open',
            'priority' => $request->priority ?? 'medium',
            'assigned_to' => $assignedTo,
            'category_id' => $categoryId,
        ]);

        // Create initial message
        $msg = TicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'user_id' => Auth::id(),
            'message' => $request->message,
            'is_admin_reply' => false,
        ]);
        
        // Broadcast event
        try {
            event(new \App\Events\MessageSent($msg));
        } catch (\Exception $e) {
            // Log the error but don't fail the request
            \Illuminate\Support\Facades\Log::error('Support ticket broadcast failed: ' . $e->getMessage());
        }

        return response()->json($ticket->load(['messages', 'assignedAgent']), 201);
    }

    // Show a specific ticket with messages
    public function show($id)
    {
        $user = Auth::user();
        $ticket = SupportTicket::with(['messages.user', 'user'])->findOrFail($id);

        // Authorization check
        if (!in_array($user->role, ['ADMIN', 'SUPPORT', 'SUPPORT_AGENT']) && $ticket->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($ticket);
    }

    // Get messages for polling (Fetch new messages only)
    public function getMessages(Request $request, $id)
    {
        $user = Auth::user();
        $ticket = SupportTicket::findOrFail($id);

        if (!in_array($user->role, ['ADMIN', 'SUPPORT', 'SUPPORT_AGENT']) && $ticket->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = TicketMessage::where('support_ticket_id', $id)->with('user');

        if ($request->has('after_id')) {
            $query->where('id', '>', $request->after_id);
        }

        $messages = $query->get();

        return response()->json($messages);
    }

    // Send a message in a ticket
    public function sendMessage(Request $request, $id)
    {
        $user = Auth::user();
        $ticket = SupportTicket::findOrFail($id);

        if (!in_array($user->role, ['ADMIN', 'SUPPORT', 'SUPPORT_AGENT']) && $ticket->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'message' => 'required|string',
            'attachment' => 'nullable|file|max:16384', // 16MB file
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $attachmentUrl = null;
        if ($request->hasFile('attachment')) {
            $attachmentUrl = $request->file('attachment')->store('attachments', 'public');
        }

        $message = TicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'message' => $request->message,
            'attachment_url' => $attachmentUrl,
            'is_admin_reply' => in_array($user->role, ['ADMIN', 'SUPPORT', 'SUPPORT_AGENT']),
        ]);
        
        // Broadcast event
        try {
            event(new \App\Events\MessageSent($message));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Support message broadcast failed: ' . $e->getMessage());
        }

        // If user replies, status maybe open/in_progress? 
        // If admin replies, we assume in_progress or resolved if they updated status separately.
        // For now, let's auto-update ticket updated_at
        $ticket->touch();

        if (in_array($user->role, ['ADMIN', 'SUPPORT', 'SUPPORT_AGENT']) && $ticket->status === 'open') {
            $ticket->update(['status' => 'in_progress']);
        }

        return response()->json($message, 201);
    }

    // Update ticket status/priority (Admin/Support or User closing their own ticket)
    public function updateStatus(Request $request, $id)
    {
        $user = Auth::user();
        $ticket = SupportTicket::findOrFail($id);

        if (!in_array($user->role, ['ADMIN', 'SUPPORT', 'SUPPORT_AGENT']) && $ticket->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Users can only close tickets
        if (!in_array($user->role, ['ADMIN', 'SUPPORT', 'SUPPORT_AGENT']) && $request->status !== 'closed') {
             return response()->json(['message' => 'Unauthorized status change'], 403);
        }

        // If support replies/updates, set is_admin_reply implicitly for next messages logic if needed,
        // but here we just update ticket fields.
        
        $updateData = [
            'status' => $request->status ?? $ticket->status,
        ];

        // Allow Admin/Support to update priority
        if (in_array($user->role, ['ADMIN', 'SUPPORT', 'SUPPORT_AGENT']) && $request->priority) {
            $updateData['priority'] = $request->priority;
        }

        $ticket->update($updateData);

        return response()->json($ticket);
    }
}
