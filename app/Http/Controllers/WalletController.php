<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\WalletService;
use Illuminate\Support\Facades\Auth;
use App\Models\WalletTransaction;

class WalletController extends Controller
{
    protected $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }

    public function getBalance()
    {
        $user = Auth::user();
        
        $wallet = $this->walletService->getWallet($user->id);
        if (!$wallet) {
            $wallet = $this->walletService->createWallet($user->id);
        }

        $balance = $this->walletService->getBalance($wallet->id);
        $lockedBalance = $this->walletService->getLockedBalance($wallet->id);

        return response()->json([
            'wallet_uuid' => $wallet->uuid,
            'balance' => $balance,
            'locked_balance' => $lockedBalance
        ]);
    }

    public function getTransactions()
    {
        $user = Auth::user();
        $wallet = $this->walletService->getWallet($user->id);
        if (!$wallet) return response()->json([]);
        
        $transactions = WalletTransaction::where('wallet_id', $wallet->id)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        $transactions->getCollection()->transform(function ($tx) {
            // Default values
            $tx->counterparty_name = $tx->type === 'CREDIT' ? 'Cashback' : 'OpenScore';
            $tx->counterparty_vpa = 'System';

            if ($tx->source_type === 'QR_PAYMENT' && $tx->source_id) {
                // source_id is now the Counterparty Wallet ID
                $counterpartyWallet = \App\Models\Wallet::find($tx->source_id);
                if ($counterpartyWallet) {
                    $user = \App\Models\User::find($counterpartyWallet->user_id);
                    if ($user) {
                        $tx->counterparty_name = $user->name;
                        $tx->counterparty_vpa = $user->mobile_number . '@openscore';
                    }
                }
            } elseif ($tx->source_type === 'LOAN') {
                $tx->counterparty_name = $tx->type === 'CREDIT' ? 'Loan Disbursed' : 'Loan Repayment';
            }

            return $tx;
        });
            
        return response()->json($transactions);
    }
    public function checkPin()
    {
        $wallet = $this->walletService->getWallet(Auth::id());
        return response()->json(['has_pin' => $wallet && $wallet->wallet_pin ? true : false]);
    }

    public function setPin(Request $request)
    {
        $request->validate(['pin' => 'required|digits:6|confirmed']);
        $wallet = $this->walletService->getWallet(Auth::id());
        if (!$wallet) $wallet = $this->walletService->createWallet(Auth::id());
        
        $this->walletService->setPin($wallet->id, $request->pin);
        return response()->json(['message' => 'PIN set successfully']);
    }

    public function verifyPin(Request $request)
    {
        $request->validate(['pin' => 'required|digits:6']);
        $wallet = $this->walletService->getWallet(Auth::id());
        if (!$wallet) return response()->json(['valid' => false], 404);

        $isValid = $this->walletService->verifyPin($wallet->id, $request->pin);
        return response()->json(['valid' => $isValid]);
    }
}
