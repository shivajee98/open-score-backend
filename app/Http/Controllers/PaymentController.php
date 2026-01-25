<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletService;
use App\Models\Payment;
use Illuminate\Support\Facades\Auth;
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
        if (Auth::user()->role !== 'MERCHANT') {
            return response()->json(['error' => 'Not a merchant'], 403);
        }
        $wallet = $this->walletService->getWallet(Auth::id());
        if (!$wallet) $wallet = $this->walletService->createWallet(Auth::id());
        
        return response()->json(['qr_data' => $wallet->uuid]);
    }

    public function findMerchant($uuid)
    {
        $wallet = Wallet::where('uuid', $uuid)->first();
        if (!$wallet) return response()->json(['error' => 'Not found'], 404);
        
        $user = User::find($wallet->user_id);
        if ($user->role !== 'MERCHANT') return response()->json(['error' => 'Invalid merchant'], 400);
        
        return response()->json([
            'name' => $user->name,
            'merchant_wallet_uuid' => $wallet->uuid
        ]);
    }

    public function pay(Request $request)
    {
        $request->validate([
            'merchant_wallet_uuid' => 'required|uuid',
            'amount' => 'required|numeric|min:0.01'
        ]);

        $customer = Auth::user();
        
        $customerWallet = $this->walletService->getWallet($customer->id);
        if (!$customerWallet) $customerWallet = $this->walletService->createWallet($customer->id);

        $payeeWallet = Wallet::where('uuid', $request->merchant_wallet_uuid)->first();

        if (!$payeeWallet) {
            return response()->json(['error' => 'Merchant wallet not found'], 404);
        }

        $payeeUser = User::find($payeeWallet->user_id);
        if (!$payeeUser || $payeeUser->role !== 'MERCHANT') {
            return response()->json(['error' => 'Can only pay to registered merchants'], 400);
        }

        $amount = $request->amount;
        $ref = Str::uuid();

        try {
            $this->walletService->transfer($customer->id, $payeeUser->id, $amount, $ref);
            
            Payment::create([
                'payer_wallet_id' => $customerWallet->id,
                'payee_wallet_id' => $payeeWallet->id,
                'amount' => $amount,
                'status' => 'COMPLETED',
                'transaction_ref' => $ref
            ]);

            return response()->json(['message' => 'Payment Successful', 'ref' => $ref]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
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
