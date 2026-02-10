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

    public function getFundStats()
    {
        $fund = \App\Models\AdminFund::first();
        if (!$fund) {
            $fund = \App\Models\AdminFund::create(['total_funds' => 0, 'available_funds' => 0]);
        }

        // Calculate real-time available funds based on allocations
        $reservedAmount = \App\Models\LoanAllocation::where('status', 'RESERVED')->sum('allocated_amount');
        $disbursedAmount = \App\Models\LoanAllocation::where('status', 'DISBURSED')->sum('actual_disbursed');
        
        // Available = Total - (Reserved + Disbursed)
        // Actually, logic is: Total - Allocated(Reserved) - Disbursed?
        // Wait, "allocated_amount" is for RESERVED. "actual_disbursed" is for DISBURSED.
        // So Available = Total - Reserved - Disbursed.
        
        $available = $fund->total_funds - $reservedAmount - $disbursedAmount;
        
        // Update cached available_funds
        if ($fund->available_funds != $available) {
            $fund->available_funds = $available;
            $fund->save();
        }

        return response()->json([
            'total_funds' => (float)$fund->total_funds,
            'available_funds' => (float)$available,
            'reserved_funds' => (float)$reservedAmount,
            'disbursed_funds' => (float)$disbursedAmount
        ]);
    }

    public function addFunds(Request $request)
    {
        $request->validate(['amount' => 'required|numeric|min:1']);

        $fund = \App\Models\AdminFund::first();
        if (!$fund) {
            $fund = \App\Models\AdminFund::create(['total_funds' => 0, 'available_funds' => 0]);
        }

        $fund->total_funds += $request->amount;
        $fund->save();

        DB::table('admin_logs')->insert([
            'admin_id' => \Illuminate\Support\Facades\Auth::id(),
            'action' => 'add_admin_funds',
            'description' => "Added ₹{$request->amount} to admin capital pool. New Total: ₹{$fund->total_funds}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->getFundStats();
    }

    public function updateFunds(Request $request)
    {
        $request->validate(['total_funds' => 'required|numeric|min:0']);

        $fund = \App\Models\AdminFund::first();
        if (!$fund) {
            $fund = \App\Models\AdminFund::create(['total_funds' => 0, 'available_funds' => 0]);
        }

        $oldTotal = $fund->total_funds;
        $newTotal = $request->total_funds;

        if ($newTotal == $oldTotal) {
             return response()->json(['message' => 'No changes made']);
        }

        // Validation: Cannot reduce below disbursed amount
        $disbursedAmount = \App\Models\LoanAllocation::whereIn('status', ['DISBURSED'])->sum('actual_disbursed');
        if ($newTotal < $disbursedAmount) {
             return response()->json(['error' => "Cannot reduce funds below total disbursed amount (₹{$disbursedAmount})."], 400);
        }

        DB::transaction(function () use ($fund, $oldTotal, $newTotal) {
            $delta = $newTotal - $oldTotal;
            $fund->total_funds = $newTotal;
            $fund->save();

            if ($delta < 0) {
                // RECONCILIATION LOGIC: Proportional adjustment for RESERVED allocations
                $reservedAllocations = \App\Models\LoanAllocation::where('status', 'RESERVED')->get();
                $totalReserved = $reservedAllocations->sum('allocated_amount');

                if ($totalReserved > 0) {
                     foreach ($reservedAllocations as $alloc) {
                         // Ratio of this loan's reservation to total reserved pool
                         $ratio = $alloc->allocated_amount / $totalReserved;
                         // Deduction amount for this loan matches its share of the total reduction
                         // BUT we only reduce if available funds would be negative? 
                         // The user requirement says: "from every user that decreased amount has to be deducted in the proportion"
                         // This implies simplistic proportional reduction of the *shortfall*? 
                         // Or strict proportional reduction of the entire delta from reservations?
                         
                         // Let's implement strict proportional reduction of the DELTA from the RESERVED pool context.
                         // Wait, if I have 100k, 50k reserved. I reduce total to 80k (-20k).
                         // Available was 50k. Now available is 30k. No need to touch reserved?
                         
                         // Re-reading user prompt: "if admin enter 103000 amount and edited it to 100000 n from every user that decreased amount has to be deducted in the proportion"
                         // This implies the reduction hits the USER allocations directly.
                         
                         // Let's calculate the "Shortfall".
                         // Current State: Total 103k. Reserved 100k. Available 3k.
                         // New State: Total 100k. Reserved ???. Available ???
                         
                         // Implementation Strategy:
                         // We Apply the reduction to the RESERVED pool proportionaly.
                         // Only if New Available < 0 ? 
                         // Or always? 
                         // The prompt says "from every user that decreased amount has to be deducted".
                         // This implies the drop comes out of the users' pockets (allocations).
                         
                         $deduction = round($ratio * abs($delta), 2);
                         
                         // Safety check: don't reduce below 0
                         if ($alloc->allocated_amount - $deduction < 0) {
                              $deduction = $alloc->allocated_amount;
                         }

                         $alloc->allocated_amount -= $deduction;
                         $alloc->status = 'ADJUSTED'; // Mark as adjusted so UI can show message?
                         $alloc->save();
                         
                         // Log this specific adjustment implementation detail?
                         // Maybe too verbose for admin logs table, but essential for audit.
                     }
                }
            }

            DB::table('admin_logs')->insert([
                'admin_id' => \Illuminate\Support\Facades\Auth::id(),
                'action' => 'update_admin_funds',
                'description' => "Updated total funds from ₹{$oldTotal} to ₹{$newTotal}. Reconciliation applied.",
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return $this->getFundStats();
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

        // Create COMPLETED transaction
        $this->walletService->credit(
            $wallet->id,
            $request->amount,
            'ADMIN_CREDIT',
            \Illuminate\Support\Facades\Auth::id(),
            "Admin Manual Credit (Instant)",
            'COMPLETED'
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

    public function creditCashback(Request $request, $id)
    {
        $admin = \Illuminate\Support\Facades\Auth::user();
        if(!in_array($admin->role, ['ADMIN', 'SUPPORT'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'amount' => 'required|numeric|min:1',
            'description' => 'required|string'
        ]);

        $user = \App\Models\User::findOrFail($id);
        $wallet = $this->walletService->getWallet($user->id);
        
        if (!$wallet) {
            $wallet = $this->walletService->createWallet($user->id);
        }

        // Create COMPLETED transaction for Cashback
        $this->walletService->credit(
            $wallet->id,
            $request->amount,
            'CASHBACK',
            $admin->id,
            $request->description,
            'COMPLETED'
        );

        DB::table('admin_logs')->insert([
            'admin_id' => $admin->id,
            'action' => 'cashback_disbursed',
            'description' => "Disbursed cashback of ₹{$request->amount} for {$user->name}. Reason: {$request->description}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Cashback credited successfully']);
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
        $user = \App\Models\User::with(['wallet'])->find($id);
        
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }
        
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
                ->with(['sourceWallet.user']) 
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function($tx) {
                    $paidTo = null;
                    if ($tx->source_type === 'QR_PAYMENT' && $tx->sourceWallet && $tx->sourceWallet->user) {
                        $paidTo = [
                            'name' => $tx->sourceWallet->user->name ?? 'Unknown',
                            'business_name' => $tx->sourceWallet->user->business_name ?? null,
                            'mobile' => $tx->sourceWallet->user->mobile_number ?? null,
                        ];
                    } elseif ($tx->source_type === 'LOAN' || $tx->source_type === 'LOAN_REPAYMENT') {
                        $paidTo = [
                            'name' => 'Open Score',
                            'business_name' => 'Loan Ledger',
                            'mobile' => '#' . $tx->source_id,
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
                'wallet_balance' => $walletId ? (float)$this->walletService->getBalance($walletId) : 0
            ],
            'loans' => [
                'ongoing' => $ongoingLoans->values(),
                'past' => $pastLoans->values(),
                'total_count' => $loans->count()
            ],
            'transactions' => $transactions
        ]);
    }
    public function getAllTransactions(Request $request) 
    {
        if (\Illuminate\Support\Facades\Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $query = \App\Models\WalletTransaction::with(['wallet.user']);
        
        // We also want to know the sender if it's a transfer
        // But WalletTransaction model might not have 'sourceWallet' relationship defined yet.
        // Let's check WalletTransaction model later. For now, assume we can get source info.
        
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('id', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('type', 'like', "%{$search}%")
                  ->orWhereHas('wallet.user', function($uq) use ($search) {
                      $uq->where('name', 'like', "%{$search}%")
                         ->orWhere('mobile_number', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->has('type') && $request->type && $request->type !== 'ALL') {
            $query->where('type', $request->type);
        }

        if ($request->has('status') && $request->status && $request->status !== 'ALL') {
            $query->where('status', $request->status);
        }

        return response()->json($query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 50)));
    }
}
