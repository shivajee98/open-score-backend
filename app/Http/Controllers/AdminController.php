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

    public function getLogs(Request $request)
    {
        $query = DB::table('admin_logs as al')
            ->join('users as u', 'al.admin_id', '=', 'u.id')
            ->select('al.*', 'u.name as admin_name', 'u.mobile_number as admin_mobile')
            ->orderBy('al.created_at', 'desc');

        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('u.name', 'like', "%{$search}%")
                  ->orWhere('u.mobile_number', 'like', "%{$search}%")
                  ->orWhere('al.action', 'like', "%{$search}%")
                  ->orWhere('al.description', 'like', "%{$search}%");
            });
        }

        if ($request->has('action') && $request->action !== 'ALL') {
             $query->where('al.action', $request->action);
        }

        $logs = $query->paginate($request->get('per_page', 50));
            
        return response()->json($logs);
    }

    public function getUsers()
    {
        $users = \App\Models\User::with('wallet')->get();
        
        $walletIds = $users->pluck('wallet.id')->filter()->toArray();
        $credits = [];
        $debits = [];

        if (!empty($walletIds)) {
            $credits = \App\Models\WalletTransaction::whereIn('wallet_id', $walletIds)
                ->where('type', 'CREDIT')
                ->where('status', 'COMPLETED')
                ->selectRaw('wallet_id, SUM(amount) as total')
                ->groupBy('wallet_id')
                ->pluck('total', 'wallet_id')
                ->toArray();

            $debits = \App\Models\WalletTransaction::whereIn('wallet_id', $walletIds)
                ->where('type', 'DEBIT')
                ->where('status', 'COMPLETED')
                ->selectRaw('wallet_id, SUM(amount) as total')
                ->groupBy('wallet_id')
                ->pluck('total', 'wallet_id')
                ->toArray();
        }

        $mapped = $users->map(function ($user) use ($credits, $debits) {
            $walletId = $user->wallet ? $user->wallet->id : null;
            $balance = 0;
            if ($walletId) {
                // Determine balance from bulk fetched data
                $c = $credits[$walletId] ?? 0;
                $d = $debits[$walletId] ?? 0;
                $balance = round($c - $d, 2);
            }
            
            return [
                'id' => $user->id,
                'name' => $user->name,
                'mobile_number' => $user->mobile_number,
                'role' => $user->role,
                'status' => $user->status ?? 'ACTIVE',
                'wallet_balance' => $balance,
                'cashback_percentage' => (float)$user->cashback_percentage,
                'cashback_flat_amount' => (float)$user->cashback_flat_amount
            ];
        });

        return response()->json($mapped);
    }

    public function deleteUser($id)
    {
        $user = \App\Models\User::findOrFail($id);
        
        // Prevent self-deletion
        if ($user->id === \Illuminate\Support\Facades\Auth::id()) {
            return response()->json(['error' => 'Cannot delete yourself'], 400);
        }

        DB::transaction(function() use ($user) {
            // Delete associated data to satisfy FK constraints
            $wallets = \App\Models\Wallet::where('user_id', $user->id)->get();
            foreach ($wallets as $wallet) {
                \App\Models\WalletTransaction::where('wallet_id', $wallet->id)->delete();
                \App\Models\Payment::where('payer_wallet_id', $wallet->id)
                    ->orWhere('payee_wallet_id', $wallet->id)
                    ->delete();
                $wallet->delete();
            }

            $loans = \App\Models\Loan::where('user_id', $user->id)->get();
            foreach ($loans as $loan) {
                \App\Models\LoanRepayment::where('loan_id', $loan->id)->delete();
                $loan->delete();
            }

            $user->delete();
        });
        
        DB::table('admin_logs')->insert([
            'admin_id' => \Illuminate\Support\Facades\Auth::id(),
            'action' => 'delete_user',
            'description' => "Deleted user {$user->name} ({$user->mobile_number}) and all associated data.",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'User and all associated records deleted successfully']);
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

    public function getTargetableUsers(Request $request)
    {
        if (\Illuminate\Support\Facades\Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $query = \App\Models\User::where('role', 'CUSTOMER');

        // Filter: Search by name or mobile
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('mobile_number', 'LIKE', "%{$search}%")
                  ->orWhere('business_name', 'LIKE', "%{$search}%");
            });
        }

        // Filter: Users who have completed at least one loan of specific amount or more
        if ($request->has('min_loan_completed')) {
            $amount = $request->min_loan_completed;
            $query->whereHas('loans', function($q) use ($amount) {
                $q->where(function($sq) {
                    $sq->where('status', 'CLOSED')
                      ->orWhere(function($ssq) {
                          $ssq->where('status', 'DISBURSED')
                             ->whereColumn('paid_amount', '>=', 'amount');
                      });
                })->where('amount', '>=', $amount);
            });
        }

        // Filter: Users who have completed a specific number of loans
        if ($request->has('min_loans_count')) {
            $count = $request->min_loans_count;
            $query->whereHas('loans', function($q) {
                $q->where('status', 'CLOSED')
                  ->orWhere(function($sq) {
                      $sq->where('status', 'DISBURSED')
                         ->whereColumn('paid_amount', '>=', 'amount');
                  });
            }, '>=', $count);
        }

        return response()->json($query->select('id', 'name', 'mobile_number', 'business_name')
            ->withCount(['loans as loans_count' => function($q) {
                $q->where('status', 'CLOSED')
                  ->orWhere(function($sq) {
                      $sq->where('status', 'DISBURSED')
                         ->whereColumn('paid_amount', '>=', 'amount');
                  });
            }])
            ->withMax(['loans as max_loan_completed' => function($q) {
                $q->where('status', 'CLOSED')
                  ->orWhere(function($sq) {
                      $sq->where('status', 'DISBURSED')
                         ->whereColumn('paid_amount', '>=', 'amount');
                  });
            }], 'amount')
            ->get());
    }

    public function getUserTransactions($userId)
    {
        $wallet = $this->walletService->getWallet($userId);
        if (!$wallet) return response()->json([]);

        $transactions = \App\Models\WalletTransaction::where('wallet_id', $wallet->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($transactions);
    }
    public function bulkUpdateCashback(Request $request)
    {
        if (\Illuminate\Support\Facades\Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'user_ids' => 'required|array',
            'user_ids.*' => 'exists:users,id',
            'cashback_percentage' => 'required|numeric|min:0|max:100',
            'cashback_flat_amount' => 'required|numeric|min:0'
        ]);

        \App\Models\User::whereIn('id', $request->user_ids)->update([
            'cashback_percentage' => $request->cashback_percentage,
            'cashback_flat_amount' => $request->cashback_flat_amount
        ]);

        return response()->json(['message' => 'Cashback settings updated successfully for selected users.']);
    }
    
    public function getCashbackSettings()
    {
        $settings = \App\Models\SignupCashbackSetting::all();
        return response()->json($settings);
    }
    
    public function updateCashbackSetting(Request $request, $role)
    {
        $request->validate([
            'cashback_amount' => 'required|numeric|min:0',
            'is_active' => 'nullable|boolean'
        ]);
        
        $setting = \App\Models\SignupCashbackSetting::where('role', strtoupper($role))->first();
        
        if (!$setting) {
            return response()->json(['error' => 'Setting not found'], 404);
        }
        
        $setting->cashback_amount = $request->cashback_amount;
        if ($request->has('is_active')) {
            $setting->is_active = $request->is_active;
        }
        $setting->save();
        
        DB::table('admin_logs')->insert([
            'admin_id' => \Illuminate\Support\Facades\Auth::id(),
            'action' => 'update_signup_cashback',
            'description' => "Updated {$role} signup cashback to ₹{$request->cashback_amount}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        return response()->json(['message' => 'Cashback setting updated successfully', 'setting' => $setting]);
    }

    public function getUserFullDetails($id)
    {
        $user = \App\Models\User::with(['wallet'])->findOrFail($id);
        
        $walletId = $user->wallet ? $user->wallet->id : null;
        
        // Loans
        $loans = \App\Models\Loan::where('user_id', $user->id)
            ->withCount(['repayments as completed_repayments_count' => function($q) {
                $q->where('status', 'PAID');
            }])
            ->orderBy('created_at', 'desc')
            ->get();
            
        // Ongoing (Active) vs Past (Closed/Defaulted)
        $ongoingLoans = $loans->whereIn('status', ['APPROVED', 'DISBURSED', 'OVERDUE']);
        $pastLoans = $loans->whereIn('status', ['CLOSED', 'DEFAULTED', 'CANCELLED', 'REJECTED']);
        
        // Transaction History (Paid to whom)
        $transactions = [];
        if ($walletId) {
            $transactions = \App\Models\WalletTransaction::where('wallet_id', $walletId)
                ->with(['payment.payee_wallet.user']) // To see who they paid to
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function($tx) {
                    $paidTo = null;
                    if ($tx->payment && $tx->payment->payee_wallet) {
                        $paidTo = [
                            'name' => $tx->payment->payee_wallet->user->name ?? 'Unknown',
                            'business_name' => $tx->payment->payee_wallet->user->business_name ?? null,
                            'mobile' => $tx->payment->payee_wallet->user->mobile_number ?? null,
                        ];
                    }
                    return [
                        'id' => $tx->id,
                        'amount' => $tx->amount,
                        'type' => $tx->type,
                        'source_type' => $tx->source_type,
                        'description' => $tx->description,
                        'status' => $tx->status,
                        'created_at' => $tx->created_at,
                        'paid_to' => $paidTo
                    ];
                });
        }
        
        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'mobile_number' => $user->mobile_number,
                'email' => $user->email,
                'role' => $user->role,
                'status' => $user->status,
                'business_name' => $user->business_name,
                'aadhaar_number' => $user->aadhaar_number,
                'pan_number' => $user->pan_number,
                'created_at' => $user->created_at,
                'wallet_balance' => $walletId ? $this->walletService->getBalance($user->id) : 0
            ],
            'loans' => [
                'ongoing' => $ongoingLoans->values(),
                'past' => $pastLoans->values(),
                'total_count' => $loans->count()
            ],
            'transactions' => $transactions
        ]);
    }
}
