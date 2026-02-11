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

        $response = [
            'wallet_uuid' => $wallet->uuid,
            'balance' => $balance,
            'locked_balance' => $lockedBalance
        ];

        if ($user->role === 'MERCHANT') {
            $dailyVolume = \App\Models\Payment::where('payee_wallet_id', $wallet->id)
                ->whereDate('created_at', \Carbon\Carbon::today())
                ->sum('amount');
            $response['daily_earnings'] = $dailyVolume;
        }

        return response()->json($response);
    }

    public function getTransactions()
    {
        $user = Auth::user();
        $wallet = $this->walletService->getWallet($user->id);
        if (!$wallet) return response()->json([]);
        
        $transactions = WalletTransaction::where('wallet_id', $wallet->id)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        // Optimization: Pre-fetch related wallets and users to avoid N+1 queries
        $walletIds = [];
        foreach ($transactions->getCollection() as $tx) {
            if ($tx->source_type === 'QR_PAYMENT' && $tx->source_id) {
                $walletIds[] = $tx->source_id;
            }
        }

        $wallets = [];
        $users = [];

        if (!empty($walletIds)) {
            $wallets = \App\Models\Wallet::whereIn('id', array_unique($walletIds))->get()->keyBy('id');
            $userIds = $wallets->pluck('user_id')->unique()->toArray();
            if (!empty($userIds)) {
                $users = \App\Models\User::whereIn('id', $userIds)->get()->keyBy('id');
            }
        }

        $transactions->getCollection()->transform(function ($tx) use ($wallets, $users) {
            // Enhanced labeling based on source_type
            if ($tx->source_type === 'QR_PAYMENT' && $tx->source_id) {
                // Default fallback if counterparty wallet/user not found
                $tx->counterparty_name = $tx->type === 'CREDIT' ? 'Received Payment' : 'Sent Payment';
                $tx->counterparty_vpa = 'Wallet Transfer';
                
                if (isset($wallets[$tx->source_id])) {
                    $counterpartyWallet = $wallets[$tx->source_id];
                    if (isset($users[$counterpartyWallet->user_id])) {
                        $user = $users[$counterpartyWallet->user_id];
                        $tx->counterparty_name = $user->name;
                        $tx->counterparty_vpa = $user->mobile_number . '@openscore';
                    }
                }
            } elseif ($tx->source_type === 'LOAN') {
                if ($tx->type === 'CREDIT') {
                    $tx->counterparty_name = $tx->status === 'COMPLETED' ? 'Loan Disbursed' : 'Loan Processing';
                } else {
                    $tx->counterparty_name = 'Loan Repayment';
                }
                $tx->counterparty_vpa = 'Open Score';
            } elseif ($tx->source_type === 'ADMIN_CREDIT') {
                $tx->counterparty_name = 'System Credit';
                $tx->counterparty_vpa = 'support@openscore';
            } elseif ($tx->source_type === 'TICKET' || $tx->source_type === 'CASHBACK') {
                $tx->counterparty_name = 'Wallet Recharge';
                $tx->counterparty_vpa = 'Open Score';
            } elseif ($tx->source_type === 'PLATFORM_FEE') {
                $tx->counterparty_name = 'Platform Fee';
                $tx->counterparty_vpa = 'Open Score';
            } else {
                // Absolute fallback
                $tx->counterparty_name = $tx->type === 'CREDIT' ? 'Cashback' : 'OpenScore';
                $tx->counterparty_vpa = 'Open Score';
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
