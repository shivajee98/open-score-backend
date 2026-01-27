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
        // Check if ID is a VPA
        if (str_contains($id, '@openscore')) {
            $mobile = explode('@', $id)[0];
            $user = User::where('mobile_number', $mobile)->first();
            if (!$user) return response()->json(['error' => 'User not found'], 404);
            $wallet = Wallet::where('user_id', $user->id)->first();
        } else {
            // Assume UUID
            $wallet = Wallet::where('uuid', $id)->first();
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
                
                Payment::create([
                    'payer_wallet_id' => $payerWallet->id,
                    'payee_wallet_id' => $payeeWallet->id,
                    'amount' => $amount,
                    'status' => 'COMPLETED',
                    'transaction_ref' => $ref
                ]);

                return response()->json(['message' => 'Transfer Successful', 'ref' => $ref]);
            } catch (\Exception $e) {
                return response()->json(['error' => $e->getMessage()], 400);
            }
        });
    }

    public function requestWithdrawal(Request $request)
    {
        $request->validate(['amount' => 'required|numeric|min:1']);
        
        $merchant = Auth::user();
        if ($merchant->role !== 'MERCHANT') {
            return response()->json(['error' => 'Only merchants can withdraw'], 403);
        }

        $wallet = $this->walletService->getWallet($merchant->id);
        $balance = $this->walletService->getBalance($wallet->id);

        if ($balance < $request->amount) {
            return response()->json(['error' => 'Insufficient balance'], 400);
        }

        $withdraw = \App\Models\WithdrawRequest::create([
            'user_id' => $merchant->id,
            'amount' => $request->amount,
            'status' => 'PENDING'
        ]);

        return response()->json($withdraw, 201);
    }

    public function listWithdrawals()
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        return response()->json(\App\Models\WithdrawRequest::with('user')->where('status', 'PENDING')->get());
    }

    public function approveWithdrawal($id)
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $withdraw = \App\Models\WithdrawRequest::findOrFail($id);
        if ($withdraw->status !== 'PENDING') {
            return response()->json(['error' => 'Not pending'], 400);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($withdraw) {
            $withdraw->status = 'COMPLETED';
            $withdraw->save();

            $wallet = $this->walletService->getWallet($withdraw->user_id);
            $this->walletService->debit($wallet->id, $withdraw->amount, 'WITHDRAWAL', $withdraw->id, "Bank Settlement Completed");
            
            \Illuminate\Support\Facades\DB::table('admin_logs')->insert([
                'admin_id' => Auth::id(),
                'action' => 'payout_approved',
                'description' => "Approved payout of ₹{$withdraw->amount} for Merchant ID: {$withdraw->user_id}",
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json(['message' => 'Payout disbursed']);
    }
}
