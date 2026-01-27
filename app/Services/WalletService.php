<?php

namespace App\Services;

use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Exception;

class WalletService
{
    public function getWallet(int $userId): ?Wallet
    {
        return Wallet::where('user_id', $userId)->first();
    }

    public function createWallet(int $userId): Wallet
    {
        $existing = $this->getWallet($userId);
        if ($existing) return $existing;

        return Wallet::create([
            'user_id' => $userId,
            'uuid' => Str::uuid(),
            'status' => 'ACTIVE'
        ]);
    }

    public function getBalance(int $walletId): float
    {
        $credits = WalletTransaction::where('wallet_id', $walletId)
            ->where('type', 'CREDIT')
            ->where('status', 'COMPLETED')
            ->sum('amount');
            
        $debits = WalletTransaction::where('wallet_id', $walletId)
            ->where('type', 'DEBIT')
            ->where('status', 'COMPLETED')
            ->sum('amount');
            
        return round($credits - $debits, 2);
    }

    public function credit(int $walletId, float $amount, string $sourceType, int $sourceId, ?string $description = null, string $status = 'COMPLETED'): WalletTransaction
    {
        return DB::transaction(function () use ($walletId, $amount, $sourceType, $sourceId, $description, $status) {
            return WalletTransaction::create([
                'wallet_id' => $walletId,
                'type' => 'CREDIT',
                'amount' => $amount,
                'status' => $status,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'description' => $description
            ]);
        });
    }

    public function debit(int $walletId, float $amount, string $sourceType, int $sourceId, ?string $description = null): WalletTransaction
    {
        return DB::transaction(function () use ($walletId, $amount, $sourceType, $sourceId, $description) {
            // Determine balance atomically
            $credits = WalletTransaction::where('wallet_id', $walletId)->lockForUpdate()
                ->where('type', 'CREDIT')
                ->where('status', 'COMPLETED')
                ->sum('amount');
            
            $debits = WalletTransaction::where('wallet_id', $walletId)->lockForUpdate()
                ->where('type', 'DEBIT')
                ->where('status', 'COMPLETED')
                ->sum('amount');

            $balance = $credits - $debits;

            if ($balance < $amount) {
                throw new Exception("Insufficient funds.");
            }

            return WalletTransaction::create([
                'wallet_id' => $walletId,
                'type' => 'DEBIT',
                'amount' => $amount,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'description' => $description
            ]);
        });
    }

    public function transfer(int $payerUserId, int $payeeUserId, float $amount, string $reference): void
    {
        DB::transaction(function () use ($payerUserId, $payeeUserId, $amount, $reference) {
            $payerWallet = $this->getWallet($payerUserId);
            $payeeWallet = $this->getWallet($payeeUserId);

            if (!$payerWallet || !$payeeWallet) {
                throw new Exception("Wallet not found.");
            }

            // Debit Payer (Source = Payee Wallet)
            $this->debit($payerWallet->id, $amount, 'QR_PAYMENT', $payeeWallet->id, "Payment to User ID: {$payeeUserId}. Ref: {$reference}");

            // Credit Payee (Source = Payer Wallet)
            $this->credit($payeeWallet->id, $amount, 'QR_PAYMENT', $payerWallet->id, "Payment from User ID: {$payerUserId}. Ref: {$reference}");
        });
    }

    public function setPin(int $walletId, string $pin): void
    {
        $wallet = Wallet::find($walletId);
        if (!$wallet) throw new Exception("Wallet not found.");
        
        $wallet->wallet_pin = bcrypt($pin);
        $wallet->save();
    }

    public function verifyPin(int $walletId, string $pin): bool
    {
        $wallet = Wallet::find($walletId);
        if (!$wallet || !$wallet->wallet_pin) return false;
        
        return \Illuminate\Support\Facades\Hash::check($pin, $wallet->wallet_pin);
    }

    public function approveTransaction(int $transactionId): void
    {
        DB::transaction(function () use ($transactionId) {
            $tx = WalletTransaction::lockForUpdate()->find($transactionId);
            if (!$tx) throw new Exception("Transaction not found");
            if ($tx->status !== 'PENDING') throw new Exception("Transaction is not pending");
            
            $tx->status = 'COMPLETED';
            $tx->save();
        });
    }

    public function rejectTransaction(int $transactionId): void
    {
        $tx = WalletTransaction::find($transactionId);
        if ($tx && $tx->status === 'PENDING') {
            $tx->status = 'REJECTED';
            $tx->save();
        }
    }
}
