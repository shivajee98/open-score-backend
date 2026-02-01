<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletService;
use App\Models\Payment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    protected $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }

    public function getMyQr()
    {
        $wallet = $this->walletService->getWallet(Auth::id());
        if (!$wallet) $wallet = $this->walletService->createWallet(Auth::id());
        
        return response()->json(['qr_data' => $wallet->uuid]);
    }

    public function findPayee($id)
    {
        $user = null;
        $wallet = null;

        if (str_contains($id, '@openscore')) {
            // VPA handler
            $mobile = explode('@', $id)[0];
            $user = User::where('mobile_number', $mobile)->first();
        } elseif (is_numeric($id) && strlen($id) >= 10) {
            // Mobile number handler
            $user = User::where('mobile_number', $id)->first();
        } elseif (str_contains($id, '@') && !str_contains($id, '@openscore')) {
            // Email handler
            $user = User::where('email', $id)->first();
        } else {
            // Assume UUID (Wallet or Physical QR)
            $wallet = Wallet::where('uuid', $id)->first();
            
            if (!$wallet) {
                // Check if it's a mapped physical QR
                $qrCode = DB::table('qr_codes')->where('code', $id)->where('status', 'assigned')->first();
                if ($qrCode) {
                    $user = User::find($qrCode->user_id);
                }
            }
        }

        if (!$wallet && $user) {
            $wallet = Wallet::where('user_id', $user->id)->first();
        }

        if (!$wallet) return response()->json(['error' => 'Payee wallet not found'], 404);
        
        $user = User::find($wallet->user_id);
        if (!$user) return response()->json(['error' => 'User not found'], 404);
        
        return response()->json([
            'name' => $user->name,
            'role' => $user->role,
            'payee_wallet_uuid' => $wallet->uuid,
            'vpa' => $user->mobile_number . '@openscore'
        ]);
    }

    public function pay(Request $request)
    {
        $request->validate([
            'payee_wallet_uuid' => 'required',
            'amount' => 'required|numeric|min:0.01',
            'pin' => 'required|digits:6'
        ]);

        return DB::transaction(function () use ($request) {
            $payer = Auth::user();
            $payerWallet = $this->walletService->getWallet($payer->id);
            if (!$payerWallet) $payerWallet = $this->walletService->createWallet($payer->id);

            // Verify PIN
            if (!$this->walletService->verifyPin($payerWallet->id, $request->pin)) {
                throw new \Exception("Invalid PIN");
            }

            $targetId = $request->payee_wallet_uuid;
            $payeeWallet = null;

            if (str_contains($targetId, '@openscore')) {
                $mobile = explode('@', $targetId)[0];
                $payeeUser = User::where('mobile_number', $mobile)->first();
                if ($payeeUser) {
                    $payeeWallet = Wallet::where('user_id', $payeeUser->id)->first();
                }
            } else {
                 $payeeWallet = Wallet::where('uuid', $targetId)->first();
            }

            if (!$payeeWallet) {
                return response()->json(['error' => 'Receiver wallet not found'], 404);
            }

            if ($payeeWallet->id === $payerWallet->id) {
                return response()->json(['error' => 'Cannot transfer to yourself'], 400);
            }

            $payeeUser = User::find($payeeWallet->user_id);
            if (!$payeeUser) {
                return response()->json(['error' => 'Receiver not found'], 404);
            }

            $amount = $request->amount;
            $ref = Str::uuid();

            try {
                $this->walletService->transfer($payer->id, $payeeUser->id, $amount, $ref);
                
                $payment = Payment::create([
                    'payer_wallet_id' => $payerWallet->id,
                    'payee_wallet_id' => $payeeWallet->id,
                    'amount' => $amount,
                    'status' => 'COMPLETED',
                    'transaction_ref' => $ref
                ]);

                // --- Guaranteed Cashback Logic ---
                // Priority: Admin Configured Settings > Random Tiered Logic
                $cashbackAmount = 0;
                
                // Refresh payer to get latest settings
                $freshPayer = User::find($payer->id);

                if ($freshPayer->cashback_percentage > 0 || $freshPayer->cashback_flat_amount > 0) {
                     // Admin Configured Logic
                     $percentageAmount = ($amount * $freshPayer->cashback_percentage) / 100;
                     $cashbackAmount = $percentageAmount + $freshPayer->cashback_flat_amount;
                } else {
                    // Default Random Logic
                    if ($amount < 10) {
                        $cashbackAmount = rand(1, 4);
                    } else {
                        $cashbackAmount = rand(15, 50);
                    }
                }

                // Safety Cap: Cashback never exceeds transaction amount
                $cashbackAmount = min($cashbackAmount, $amount);

                if ($cashbackAmount > 0) {
                    $this->walletService->credit(
                        $payerWallet->id,
                        $cashbackAmount,
                        'CASHBACK',
                        $payment->id, // Link to the payment transaction ID
                        "Cashback for payment to {$payeeUser->name}",
                        'COMPLETED'
                    );
                }
                // ---------------------------------

                return response()->json(['message' => 'Transfer Successful', 'ref' => $ref, 'cashback' => $cashbackAmount]);
            } catch (\Exception $e) {
                return response()->json(['error' => $e->getMessage()], 400);
            }
        });
    }

    public function requestWithdrawal(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'bank_name' => 'required|string',
            'account_number' => 'required|string',
            'ifsc_code' => 'required|string',
            'account_holder_name' => 'required|string',
        ]);
        
        $user = Auth::user();
        $wallet = $this->walletService->getWallet($user->id);
        if (!$wallet) $wallet = $this->walletService->createWallet($user->id);
        
        $balance = $this->walletService->getBalance($wallet->id);

        if ($balance < $request->amount) {
            return response()->json(['error' => 'Insufficient balance'], 400);
        }

        // --- RESTRICTIONS START ---
        if ($user->role === 'MERCHANT') {
            // Merchant Restriction: Daily Volume Check AND Cap
            $dailyVolume = \App\Models\Payment::where('payee_wallet_id', $wallet->id)
                ->whereDate('created_at', \Carbon\Carbon::today())
                ->sum('amount');

            // 1. Min Volume Requirement (Eligibility)
            $minVolume = 5000;
            if ($dailyVolume < $minVolume) {
                return response()->json([
                    'error' => "Daily transaction volume of ₹{$minVolume} required to withdraw. Today's volume: ₹{$dailyVolume}"
                ], 400);
            }

            // 2. Withdrawal Amount Cap (Cannot withdraw more than earned today)
            // Note: This logic implies if they have old balance, they can't withdraw it unless they earn today?
            // "he can request that much amount only for the withdrawl that he earned in a day"
            if ($request->amount > $dailyVolume) {
                return response()->json([
                    'error' => "You can only withdraw up to your today's earnings (₹{$dailyVolume})."
                ], 400);
            }
        } else {
            // Customer Restriction: Loan Spend Check
            $activeLoan = \App\Models\Loan::where('user_id', $user->id)
                ->whereIn('status', ['ACTIVE', 'DISBURSED', 'APPROVED']) // Cover all potentially active states
                ->orderBy('created_at', 'desc')
                ->first();

            if ($activeLoan) {
                // Feature Restriction: Withdrawals only for loans >= 50k
                if ($activeLoan->amount < 50000) {
                    return response()->json([
                        'error' => "Bank withdrawals are only available for Premium Loans of ₹50,000 and above. Please use your wallet for scan & pay."
                    ], 403);
                }

                $loanAmount = $activeLoan->amount;
                // Calculate spend since loan disbursal (or approval if disbursal missing)
                $startDate = $activeLoan->disbursed_at ?? $activeLoan->approved_at ?? $activeLoan->created_at;
                
                $totalSpend = \App\Models\Payment::where('payer_wallet_id', $wallet->id)
                    ->where('created_at', '>=', $startDate)
                    ->sum('amount');

                $requiredSpend = $loanAmount * 0.30; 
                
                if ($totalSpend < $requiredSpend) {
                    $remaining = $requiredSpend - $totalSpend;
                    return response()->json([
                        'error' => "You must spend at least 30% of your loan (₹{$requiredSpend}) on app transactions before withdrawing. Remaining: ₹{$remaining}"
                    ], 400);
                }
            } else {
                 return response()->json([
                    'error' => "No active loan found. Withdrawals are only available for active Premium Loans (₹50,000+)."
                ], 403);
            }
        }
        // --- RESTRICTIONS END ---

        $withdraw = \App\Models\WithdrawRequest::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'amount' => $request->amount,
            'status' => 'PENDING',
            'bank_name' => $request->bank_name,
            'account_number' => $request->account_number,
            'ifsc_code' => $request->ifsc_code,
            'account_holder_name' => $request->account_holder_name,
        ]);

        return response()->json($withdraw, 201);
    }

    public function listWithdrawals()
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        return response()->json(\App\Models\WithdrawRequest::with('user')->orderBy('created_at', 'desc')->get());
    }

    public function approveWithdrawal(Request $request, $id)
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $withdraw = \App\Models\WithdrawRequest::findOrFail($id);
        if ($withdraw->status !== 'PENDING') {
            return response()->json(['error' => 'Request is already processed'], 400);
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($withdraw, $request) {
            $withdraw->status = 'PAID';
            $withdraw->processed_by = Auth::id();
            $withdraw->processed_at = now();
            $withdraw->admin_note = $request->admin_note;
            $withdraw->save();

            $wallet = $this->walletService->getWallet($withdraw->user_id);
            $this->walletService->debit($wallet->id, $withdraw->amount, 'WITHDRAWAL', $withdraw->id, "Bank Settlement Completed");
            
            \Illuminate\Support\Facades\DB::table('admin_logs')->insert([
                'admin_id' => Auth::id(),
                'action' => 'payout_approved',
                'description' => "Approved and Paid payout of ₹{$withdraw->amount} for User ID: {$withdraw->user_id}",
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json(['message' => 'Payout processed and marked as PAID']);
        });
    }

    public function rejectWithdrawal(Request $request, $id)
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $withdraw = \App\Models\WithdrawRequest::findOrFail($id);
        if ($withdraw->status !== 'PENDING') {
            return response()->json(['error' => 'Request is already processed'], 400);
        }

        $withdraw->status = 'REJECTED';
        $withdraw->processed_by = Auth::id();
        $withdraw->processed_at = now();
        $withdraw->admin_note = $request->admin_note;
        $withdraw->save();

        \Illuminate\Support\Facades\DB::table('admin_logs')->insert([
            'admin_id' => Auth::id(),
            'action' => 'payout_rejected',
            'description' => "Rejected payout of ₹{$withdraw->amount} for User ID: {$withdraw->user_id}. Reason: {$request->admin_note}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Payout request rejected']);
    }

    public function searchPayee(Request $request)
    {
        $query = $request->query('query');
        if (strlen($query) < 3) return response()->json([]);

        $users = User::where('id', '!=', Auth::id())
            ->where(function ($q) use ($query) {
                $q->where('mobile_number', 'LIKE', $query . '%')
                  ->orWhere('name', 'LIKE', '%' . $query . '%');
            })
            ->limit(10)
            ->get();

        $results = $users->map(function ($p) {
            $wallet = Wallet::where('user_id', $p->id)->first();
            return [
                'name' => $p->name,
                'role' => $p->role,
                'wallet_uuid' => $wallet ? $wallet->uuid : null,
                'vpa' => $p->mobile_number . '@openscore',
                'id' => $p->mobile_number // Use mobile as ID for frontend search consistency
            ];
        });

        return response()->json($results);
    }
}
