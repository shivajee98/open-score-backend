<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class SupportController extends Controller
{
    protected $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }

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
            'attachment' => 'nullable|file|max:16384', // 16MB
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Routing Logic — fully dynamic, no hardcoded category values
        $assignedTo = null;
        $categoryId = null;
        $issueType = $request->issue_type;

        $cat = null;
        if (is_numeric($issueType)) {
            $cat = \App\Models\SupportCategory::find($issueType);
            \Log::info("Ticket Routing: Received numeric ID: $issueType. Found Category: " . ($cat ? $cat->name : 'NONE'));
        } else {
            // Attempt to find by slug as provided
            $cat = \App\Models\SupportCategory::where('slug', $issueType)->first();
            
            // Fallback: If not found, try replacing hyphens with underscores or vice versa
            if (!$cat) {
                 $altSlug = str_contains($issueType, '-') ? str_replace('-', '_', $issueType) : str_replace('_', '-', $issueType);
                 $cat = \App\Models\SupportCategory::where('slug', $altSlug)->first();
            }
            
            \Log::info("Ticket Routing: Received slug: $issueType. Found Category: " . ($cat ? $cat->name : 'NONE'));
        }

        if ($cat) {
            $categoryId = $cat->id;

            // Auto-assign to the support agent responsible for this category
            $agent = \App\Models\User::where('role', 'SUPPORT_AGENT')
                ->where('support_category_id', $cat->id)
                ->where('status', 'ACTIVE')
                ->first();
            
            if ($agent) {
                $assignedTo = $agent->id;
                \Log::info("Ticket Routing: Successfully assigned to Agent #$assignedTo ({$agent->name}) for Category #$categoryId");
            } else {
                \Log::warning("Ticket Routing: No active agent found for Category #$categoryId ({$cat->name})");
            }
        } else {
            \Log::error("Ticket Routing: Critical - No category found for issue_type: $issueType. Defaulting assignment to null.");
        }

        // Determine if this is a payment ticket
        // Use both issueType slug and category slug for checking
        $paymentKeywords = ['emi', 'wallet', 'transfer', 'recharge', 'payment', 'fee'];
        $isPaymentTicket = false;
        
        $checkString = strtolower($issueType . ($cat ? $cat->slug : ''));
        foreach ($paymentKeywords as $kw) {
            if (str_contains($checkString, $kw)) {
                $isPaymentTicket = true;
                break;
            }
        }
        
        \Log::info("Ticket Routing: isPaymentTicket=" . ($isPaymentTicket ? 'true' : 'false'));

        $ticket = SupportTicket::create([
            'user_id' => Auth::id(),
            'subject' => $request->subject,
            'issue_type' => $issueType,
            'status' => 'open',
            'payment_status' => $isPaymentTicket ? 'PENDING_VERIFICATION' : null,
            'payment_amount' => $isPaymentTicket ? $request->payment_amount : null,
            'priority' => $request->priority ?? 'medium',
            'assigned_to' => $assignedTo,
            'category_id' => $categoryId,
            'sub_action' => $request->sub_action,
            'target_id' => $request->target_id,
        ]);

        // Auto-generate unique_ticket_id for payment tickets
        if ($isPaymentTicket) {
            $prefixMap = ['emi_payment' => 'EMI', 'wallet_topup' => 'WAL', 'services' => 'SVC'];
            $prefix = $prefixMap[$issueType] ?? 'GEN';
            $ticket->unique_ticket_id = "TKT-{$prefix}-{$ticket->id}";
            $ticket->save();
        }

        // Handle attachment upload (screenshot)
        $attachmentUrl = null;
        if ($request->hasFile('attachment')) {
            $attachmentUrl = $request->file('attachment')->store('attachments', 'public');
        }

        // Create initial message
        $msg = TicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'user_id' => Auth::id(),
            'message' => $request->message,
            'attachment_url' => $attachmentUrl,
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

        return response()->json([
            'status' => 'success',
            'messages' => $messages
        ]);
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
            'attachment_label' => 'nullable|string|max:255',
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
            'attachment_label' => $request->attachment_label,
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

    // Approve a payment ticket (Two-tier: Agent -> Admin)
    public function approveTicketPayment(Request $request, $id)
    {
        $user = Auth::user();

        if (!in_array($user->role, ['ADMIN', 'SUPPORT', 'SUPPORT_AGENT'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $ticket = SupportTicket::findOrFail($id);

        if (!$ticket->payment_status) {
            return response()->json(['message' => 'This ticket is not a payment ticket'], 400);
        }

        if (!in_array($ticket->payment_status, ['PENDING_VERIFICATION', 'AGENT_APPROVED'])) {
            return response()->json(['message' => 'Ticket is not in an approvable state'], 400);
        }

        if (in_array($user->role, ['SUPPORT_AGENT', 'SUPPORT'])) {
            // Agent-level approval
            if ($ticket->payment_status !== 'PENDING_VERIFICATION') {
                return response()->json(['message' => 'Already approved by agent'], 400);
            }

            $ticket->payment_status = 'AGENT_APPROVED';
            $ticket->agent_approved_at = now();
            $ticket->agent_approved_by = $user->id;
            $ticket->save();

            // Automatic Loan Repayment status update
            if ($ticket->sub_action === 'emi' && $ticket->target_id) {
                $repayment = \App\Models\LoanRepayment::find($ticket->target_id);
                if ($repayment) {
                    $repayment->update([
                        'agent_approved_at' => now(),
                        'agent_approved_by' => $user->id,
                        // Not changing status to PAID yet, just marking as verified
                    ]);
                }
            }

            \DB::table('admin_logs')->insert([
                'admin_id' => $user->id,
                'action' => 'ticket_payment_agent_approved',
                'description' => "Agent pre-approved payment ticket #{$ticket->unique_ticket_id} for ₹{$ticket->payment_amount}",
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        } else {
            // Admin-level final approval
            $ticket->payment_status = 'ADMIN_APPROVED';
            $ticket->admin_approved_at = now();
            $ticket->admin_approved_by = $user->id;

            // If admin approves directly without agent step, record both
            if (!$ticket->agent_approved_at) {
                $ticket->agent_approved_at = now();
                $ticket->agent_approved_by = $user->id;
            }

            $ticket->status = 'closed';
            $ticket->save();

            // Execute Actions based on sub_action
            // 1. EMI Repayment
            if ($ticket->sub_action === 'emi' && $ticket->target_id) {
                $repayment = \App\Models\LoanRepayment::find($ticket->target_id);
                if ($repayment && $repayment->status !== 'PAID') {
                    $repayment->update([
                        'status' => 'PAID',
                        'paid_at' => now(),
                        'collected_by' => $user->id,
                    ]);

                    $loan = $repayment->loan;
                    if ($loan) {
                        $loan->increment('paid_amount', $repayment->amount);
                        // Closure check
                        if ($loan->repayments()->where('status', '!=', 'PAID')->count() === 0) {
                            $loan->update(['status' => 'CLOSED', 'closed_at' => now()]);
                        }
                    }
                }
            } 
            // 2. Wallet Recharge (Topup) — via Central Treasury
            elseif ($ticket->sub_action === 'recharge' || $ticket->sub_action === 'wallet_topup') {
                $customer = $ticket->user;
                // Ensure wallet exists
                $wallet = $this->walletService->getWallet($customer->id);
                if (!$wallet) {
                    $wallet = $this->walletService->createWallet($customer->id);
                }

                $this->walletService->transferSystemFunds(
                    $customer->id,
                    $ticket->payment_amount,
                    'TICKET',
                    "Wallet recharge (₹{$ticket->payment_amount}) via ticket #{$ticket->unique_ticket_id}",
                    'OUT'
                );
            }
            // 3. Platform/Service Fee — via Central Treasury
            elseif ($ticket->sub_action === 'platform_fee' || $ticket->sub_action === 'service_fee') {
                $customer = $ticket->user;
                $wallet = $this->walletService->getWallet($customer->id);
                if (!$wallet) {
                    $wallet = $this->walletService->createWallet($customer->id);
                }

                $this->walletService->transferSystemFunds(
                    $customer->id,
                    $ticket->payment_amount,
                    'PLATFORM_FEE',
                    "Platform fee payment (₹{$ticket->payment_amount}) via ticket #{$ticket->unique_ticket_id}",
                    'OUT'
                );
            }

            \DB::table('admin_logs')->insert([
                'admin_id' => $user->id,
                'action' => 'ticket_payment_admin_approved',
                'description' => "Admin approved payment ticket #{$ticket->unique_ticket_id} for ₹{$ticket->payment_amount} ({$ticket->sub_action})",
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json($ticket->load(['user', 'approvedByAgent', 'approvedByAdmin']));
    }

    // Reject a payment ticket
    public function rejectTicketPayment(Request $request, $id)
    {
        $user = Auth::user();

        if (!in_array($user->role, ['ADMIN', 'SUPPORT', 'SUPPORT_AGENT'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate(['reason' => 'required|string']);

        $ticket = SupportTicket::findOrFail($id);

        if (!$ticket->payment_status) {
            return response()->json(['message' => 'This ticket is not a payment ticket'], 400);
        }

        if (!in_array($ticket->payment_status, ['PENDING_VERIFICATION', 'AGENT_APPROVED'])) {
            return response()->json(['message' => 'Ticket is not in a rejectable state'], 400);
        }

        $ticket->payment_status = 'REJECTED';
        $ticket->rejection_reason = $request->reason;
        $ticket->save();

        \DB::table('admin_logs')->insert([
            'admin_id' => $user->id,
            'action' => 'ticket_payment_rejected',
            'description' => "Rejected payment ticket #{$ticket->unique_ticket_id}: {$request->reason}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Notify user via FCM
        try {
            $customer = \App\Models\User::find($ticket->user_id);
            if ($customer) {
                \App\Services\FcmService::sendToUser(
                    $customer,
                    "Payment Rejected ❌",
                    "Your payment of ₹" . number_format($ticket->payment_amount) . " was rejected. Reason: " . $request->reason,
                );
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('FCM notification failed: ' . $e->getMessage());
        }

        return response()->json(['message' => 'Payment rejected', 'ticket' => $ticket]);
    }

    // Get payment tickets for approval dashboard
    public function getPaymentTickets(Request $request)
    {
        $user = Auth::user();

        if (!in_array($user->role, ['ADMIN', 'SUPPORT', 'SUPPORT_AGENT'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $tickets = SupportTicket::with(['user', 'assignedAgent', 'approvedByAgent', 'approvedByAdmin', 'messages' => function($q) {
                $q->latest()->limit(1);
            }])
            ->whereNotNull('payment_status')
            ->when($request->status, function($q, $status) {
                return $q->where('payment_status', $status);
            })
            ->when(in_array($user->role, ['SUPPORT_AGENT']), function($q) use ($user) {
                if ($user->support_category_id) {
                    return $q->where('category_id', $user->support_category_id);
                }
            })
            ->latest()
            ->paginate(20);

        return response()->json($tickets);
    }
    // Process specific actions from a support ticket (Wallet, EMI, Fee)
    public function processTicketAction(Request $request, $id)
    {
        $user = Auth::user();
        if (!in_array($user->role, ['ADMIN', 'SUPPORT', 'SUPPORT_AGENT'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $ticket = SupportTicket::findOrFail($id);
        $action = $request->action; // recharge, emi, platform_fee
        $amount = $request->amount;
        $targetId = $request->target_id; // e.g. repayment_id

        if ($user->role === 'SUPPORT_AGENT') {
            // Agent level: just mark the ticket with intended action and set AGENT_APPROVED
            $ticket->update([
                'payment_status' => 'AGENT_APPROVED',
                'payment_amount' => $amount,
                'sub_action' => $action, // We'll assume the column will be added soon or use metadata
                'target_id' => $targetId,
                'agent_approved_at' => now(),
                'agent_approved_by' => $user->id,
            ]);

            return response()->json(['message' => 'Action pre-approved by agent', 'ticket' => $ticket]);
        } else {
            // Admin level: Finalize and perform the action
            return \DB::transaction(function () use ($ticket, $action, $amount, $targetId, $user) {
                if ($action === 'recharge') {
                    $customer = $ticket->user;
                    // Ensure wallet exists
                    $wallet = $this->walletService->getWallet($customer->id);
                    if (!$wallet) {
                        $wallet = $this->walletService->createWallet($customer->id);
                    }
                    
                    // Credit via Central Treasury
                    $this->walletService->transferSystemFunds(
                        $customer->id,
                        $amount,
                        'TICKET',
                        "Wallet recharge (₹{$amount}) via ticket #{$ticket->unique_ticket_id}",
                        'OUT'
                    );

                } elseif ($action === 'emi') {
                    $repayment = \App\Models\LoanRepayment::findOrFail($targetId);
                    $repayment->update([
                        'status' => 'PAID',
                        'paid_at' => now(),
                        'collected_by' => $user->id,
                    ]);

                    $loan = $repayment->loan;
                    if ($loan) {
                        $loan->increment('paid_amount', $repayment->amount);
                        // Closure check
                        if ($loan->repayments()->where('status', '!=', 'PAID')->count() === 0) {
                            $loan->update(['status' => 'CLOSED', 'closed_at' => now()]);
                        }
                    }
                } elseif ($action === 'platform_fee') {
                    $customer = $ticket->user;
                    // Ensure wallet exists
                    $wallet = $this->walletService->getWallet($customer->id);
                    if (!$wallet) {
                        $wallet = $this->walletService->createWallet($customer->id);
                    }

                    // Credit via Central Treasury
                    $this->walletService->transferSystemFunds(
                        $customer->id,
                        $amount,
                        'PLATFORM_FEE',
                        "Platform fee payment (₹{$amount}) via ticket #{$ticket->unique_ticket_id}",
                        'OUT'
                    );
                }

                $ticket->update([
                    'payment_status' => 'ADMIN_APPROVED',
                    'admin_approved_at' => now(),
                    'admin_approved_by' => $user->id,
                    'status' => 'closed',
                    'sub_action' => $action,
                    'payment_amount' => $amount,
                    'target_id' => $targetId
                ]);

                return response()->json(['message' => 'Action finalized by admin', 'ticket' => $ticket]);
            });
        }
    }
}
