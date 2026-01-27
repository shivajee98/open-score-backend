<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    protected $walletService;

    public function __construct(\App\Services\WalletService $walletService)
    {
        $this->walletService = $walletService;
    }

    public function getLogs()
    {
        $logs = DB::table('admin_logs')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();
            
        return response()->json($logs);
    }

    public function getUsers()
    {
        $users = \App\Models\User::all()->map(function ($user) {
            $wallet = \App\Models\Wallet::where('user_id', $user->id)->first();
            return [
                'id' => $user->id,
                'name' => $user->name,
                'mobile_number' => $user->mobile_number,
                'role' => $user->role,
                'status' => $user->status ?? 'ACTIVE',
                'wallet_balance' => $wallet ? $this->walletService->getBalance($wallet->id) : 0
            ];
        });
        return response()->json($users);
    }

    public function deleteUser($id)
    {
        $user = \App\Models\User::findOrFail($id);
        
        // Prevent self-deletion
        if ($user->id === \Illuminate\Support\Facades\Auth::id()) {
            return response()->json(['error' => 'Cannot delete yourself'], 400);
        }

        $user->delete();
        
        DB::table('admin_logs')->insert([
            'admin_id' => \Illuminate\Support\Facades\Auth::id(),
            'action' => 'delete_user',
            'description' => "Deleted user {$user->name} ({$user->mobile_number})",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'User deleted successfully']);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate(['status' => 'required|string|in:ACTIVE,SUSPENDED']);
        $user = \App\Models\User::findOrFail($id);
        
        $user->status = $request->status;
        $user->save();

        DB::table('admin_logs')->insert([
            'admin_id' => \Illuminate\Support\Facades\Auth::id(),
            'action' => 'update_status',
            'description' => "Updated status of {$user->name} to {$request->status}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => "User {$request->status} successfully"]);
    }

    public function creditUser(Request $request, $id)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1'
        ]);

        $user = \App\Models\User::findOrFail($id);
        $wallet = $this->walletService->getWallet($user->id);
        
        if (!$wallet) {
            $wallet = $this->walletService->createWallet($user->id);
        }

        // Create PENDING transaction
        $this->walletService->credit(
            $wallet->id,
            $request->amount,
            'ADMIN_CREDIT',
            \Illuminate\Support\Facades\Auth::id(),
            "Admin Manual Credit (Pending Approval)",
            'PENDING'
        );

        DB::table('admin_logs')->insert([
            'admin_id' => \Illuminate\Support\Facades\Auth::id(),
            'action' => 'manual_credit_request',
            'description' => "Requested credit of ₹{$request->amount} for {$user->name}. awaiting approval.",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Credit request submitted for approval']);
    }

    public function getPendingTransactions()
    {
        $pending = \App\Models\WalletTransaction::with('wallet.user')
            ->where('status', 'PENDING')
            ->where('source_type', 'ADMIN_CREDIT')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($tx) {
                return [
                    'id' => $tx->id,
                    'amount' => $tx->amount,
                    'user_name' => $tx->wallet->user->name ?? 'Unknown',
                    'user_mobile' => $tx->wallet->user->mobile_number ?? '',
                    'created_at' => $tx->created_at,
                ];
            });

        return response()->json($pending);
    }

    public function approveFund($id)
    {
        try {
            $this->walletService->approveTransaction($id);
            
            DB::table('admin_logs')->insert([
                'admin_id' => \Illuminate\Support\Facades\Auth::id(),
                'action' => 'fund_approved',
                'description' => "Approved fund transaction ID: {$id}",
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json(['message' => 'Funds approved successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function rejectFund($id)
    {
        $this->walletService->rejectTransaction($id);
        return response()->json(['message' => 'Fund request rejected']);
    }
}
