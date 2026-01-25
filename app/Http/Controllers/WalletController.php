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

        return response()->json([
            'wallet_uuid' => $wallet->uuid,
            'balance' => $balance
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
            
        return response()->json($transactions);
    }
}
