<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SubUserPayout;
use App\Models\SubUser;
use App\Services\WalletService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Services\FcmService; // Assuming FcmService can handle sub-users eventually or we just skip FCM for now

class SubUserPayoutController extends Controller
{
    protected $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }

    // Sub-User: Request Cashout
    public function store(Request $request)
    {
        $subUser = Auth::guard('sub-user')->user();
        if (!$subUser) return response()->json(['error' => 'Unauthorized'], 401);

        $request->validate([
            'amount' => 'required|numeric|min:1',
            'bank_details' => 'required|string', // Simple string for now
        ]);

        if ($subUser->earnings_balance < $request->amount) {
            return response()->json(['error' => 'Insufficient earnings balance'], 400);
        }

        $payout = DB::transaction(function () use ($subUser, $request) {
            // Deduct immediately
            $subUser->decrement('earnings_balance', $request->amount);

            return SubUserPayout::create([
                'sub_user_id' => $subUser->id,
                'amount' => $request->amount,
                'bank_details' => $request->bank_details,
                'status' => 'PENDING'
            ]);
        });
        
        // Log transaction? 
        // Maybe creating a DEBIT specific for Payout Request would be good?
        // But we already decremented balance. Let's record it.
        // There isn't a debit methods for SubUser in WalletService, only creditSubUser.
        // Let's rely on Payout table as the record for now, or add a stub in transactions if needed.

        return response()->json(['message' => 'Cashout request submitted', 'payout' => $payout]);
    }

    // Sub-User: List My Requests
    public function index()
    {
        $subUser = Auth::guard('sub-user')->user();
        if (!$subUser) return response()->json(['error' => 'Unauthorized'], 401);

        $payouts = SubUserPayout::where('sub_user_id', $subUser->id)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($payouts);
    }

    // Admin: List All Requests
    public function adminIndex()
    {
        $payouts = SubUserPayout::with('subUser')
            ->orderBy('created_at', 'desc')
            ->paginate(20);
            
        return response()->json($payouts);
    }

    // Admin: Approve
    public function approve(Request $request, $id)
    {
        $request->validate([
            'admin_message' => 'required|string',
            'proof_image' => 'nullable|image|max:2048' // Optional uploaded file
        ]);

        $payout = SubUserPayout::findOrFail($id);
        if ($payout->status !== 'PENDING') {
            return response()->json(['error' => 'Request already processed'], 400);
        }

        $proofPath = null;
        if ($request->hasFile('proof_image')) {
            $path = $request->file('proof_image')->store('payout_proofs', 'public');
            $proofPath = url("storage/{$path}"); // Simple URL generation
        }

        $payout->status = 'APPROVED';
        $payout->admin_message = $request->admin_message;
        $payout->proof_image = $proofPath;
        $payout->processed_by = Auth::id();
        $payout->processed_at = now();
        $payout->save();

        // Create a DEBIT transaction record for tracking (Optional but good for history)
        \App\Models\SubUserTransaction::create([
            'sub_user_id' => $payout->sub_user_id,
            'amount' => $payout->amount,
            'type' => 'DEBIT',
            'description' => "Cashout Approved: " . $request->admin_message,
            'reference_id' => 'PAYOUT_' . $payout->id
        ]);

        return response()->json(['message' => 'Payout approved', 'payout' => $payout]);
    }

    // Admin: Reject
    public function reject(Request $request, $id)
    {
        $request->validate([
            'admin_message' => 'required|string',
        ]);

        $payout = SubUserPayout::findOrFail($id);
        if ($payout->status !== 'PENDING') {
            return response()->json(['error' => 'Request already processed'], 400);
        }

        DB::transaction(function () use ($payout, $request) {
            $payout->status = 'REJECTED';
            $payout->admin_message = $request->admin_message;
            $payout->processed_by = Auth::id();
            $payout->processed_at = now();
            $payout->save();

            // Refund balance
            $subUser = SubUser::lockForUpdate()->find($payout->sub_user_id);
            $subUser->increment('earnings_balance', $payout->amount);
        });

        return response()->json(['message' => 'Payout rejected and refunded', 'payout' => $payout]);
    }
}
