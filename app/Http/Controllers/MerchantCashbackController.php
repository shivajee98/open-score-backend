<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\MerchantCashback;
use App\Models\MerchantCashbackTier;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class MerchantCashbackController extends Controller
{
    protected $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }

    // Get all merchants with filters
    public function getMerchants(Request $request)
    {
        $query = User::where('role', 'MERCHANT')
            ->with(['wallet']);

        // Filter by daily turnover tier
        if ($request->has('turnover_tier') && $request->turnover_tier !== 'ALL') {
            $tier = MerchantCashbackTier::find($request->turnover_tier);
            if ($tier) {
                $query->whereBetween('daily_turnover', [$tier->min_turnover, $tier->max_turnover]);
            }
        }

        // Search by name or mobile
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('mobile_number', 'like', "%{$search}%")
                  ->orWhere('business_name', 'like', "%{$search}%");
            });
        }

        // Filter by business nature
        if ($request->has('business_nature') && $request->business_nature) {
            $query->where('business_nature', $request->business_nature);
        }

        $merchants = $query->paginate($request->get('per_page', 50));

        // Optimizing with batch queries
        $merchantIds = $merchants->pluck('id')->toArray();
        $walletIds = $merchants->pluck('wallet.id')->filter()->toArray();
        
        // 1. Batch Calculate Today's Turnover
        $turnovers = [];
        if (!empty($walletIds)) {
            $turnovers = WalletTransaction::whereIn('wallet_id', $walletIds)
                ->where('type', 'CREDIT')
                ->where('source_type', 'QR_PAYMENT')
                ->whereDate('created_at', today())
                ->selectRaw('wallet_id, SUM(amount) as total')
                ->groupBy('wallet_id')
                ->pluck('total', 'wallet_id')
                ->toArray();
        }

        // 2. Batch Fetch Latest Cashback
        // Technique: Subquery to get latest ID per merchant, then fetch objects
        // Or simpler for 50 items: Fetch all cashbacks for these merchants, ordered by date, then group by merchant in PHP (if data volume isn't huge per merchant)
        // Better: Use a subquery to get max created_at per merchant
        $latestCashbacks = [];
        if (!empty($merchantIds)) {
             // Efficient "Latest of Many" via subquery
             $latestIds = MerchantCashback::selectRaw('MAX(id) as id')
                 ->whereIn('merchant_id', $merchantIds)
                 ->groupBy('merchant_id')
                 ->pluck('id');
             
             if ($latestIds->isNotEmpty()) {
                 $latestCashbacks = MerchantCashback::whereIn('id', $latestIds)
                    ->get()
                    ->keyBy('merchant_id');
             }
        }

        $merchants->getCollection()->transform(function ($merchant) use ($turnovers, $latestCashbacks) {
            // Map batch results
            $walletId = $merchant->wallet ? $merchant->wallet->id : null;
            $merchant->calculated_daily_turnover = $walletId ? ($turnovers[$walletId] ?? 0) : 0;
            $merchant->latest_cashback = $latestCashbacks[$merchant->id] ?? null;

            return $merchant;
        });

        return response()->json($merchants);
    }

    // Get all cashback tiers
    public function getTiers()
    {
        $tiers = MerchantCashbackTier::where('is_active', true)->get();
        return response()->json($tiers);
    }

    // Create or update tier
    public function updateTier(Request $request, $id = null)
    {
        $validated = $request->validate([
            'tier_name' => 'required|string',
            'min_turnover' => 'required|numeric|min:0',
            'max_turnover' => 'required|numeric|gt:min_turnover',
            'cashback_min' => 'required|numeric|min:0',
            'cashback_max' => 'required|numeric|gt:cashback_min',
        ]);

        if ($id) {
            $tier = MerchantCashbackTier::findOrFail($id);
            $tier->update($validated);
        } else {
            $tier = MerchantCashbackTier::create($validated);
        }

        return response()->json($tier);
    }

    // Award cashback to merchant
    public function awardCashback(Request $request)
    {
        $validated = $request->validate([
            'merchant_id' => 'required|exists:users,id',
            'cashback_amount' => 'required|numeric|min:0',
            'daily_turnover' => 'required|numeric|min:0',
            'cashback_date' => 'required|date',
            'tier_id' => 'nullable|exists:merchant_cashback_tiers,id',
            'notes' => 'nullable|string'
        ]);

        $cashback = MerchantCashback::create([
            'merchant_id' => $validated['merchant_id'],
            'tier_id' => $validated['tier_id'] ?? null,
            'daily_turnover' => $validated['daily_turnover'],
            'cashback_amount' => $validated['cashback_amount'],
            'cashback_date' => $validated['cashback_date'],
            'status' => 'PENDING',
            'notes' => $validated['notes'] ?? null
        ]);

        return response()->json($cashback);
    }

    // Bulk award cashback
    public function bulkAwardCashback(Request $request)
    {
        $validated = $request->validate([
            'merchant_ids' => 'required|array',
            'merchant_ids.*' => 'exists:users,id',
            'cashback_amounts' => 'required|array',
            'cashback_amounts.*' => 'numeric|min:0',
            'cashback_date' => 'required|date',
            'tier_id' => 'nullable|exists:merchant_cashback_tiers,id',
        ]);

        $cashbacks = [];
        foreach ($validated['merchant_ids'] as $index => $merchantId) {
            // Get merchant's actual turnover
            $merchant = User::find($merchantId);
            $wallet = $merchant->wallet;
            
            $dailyTurnover = 0;
            if ($wallet) {
                $dailyTurnover = WalletTransaction::where('wallet_id', $wallet->id)
                    ->where('type', 'CREDIT')
                    ->where('source_type', 'QR_PAYMENT')
                    ->whereDate('created_at', $validated['cashback_date'])
                    ->sum('amount');
            }

            $cashback = MerchantCashback::create([
                'merchant_id' => $merchantId,
                'tier_id' => $validated['tier_id'] ?? null,
                'daily_turnover' => $dailyTurnover,
                'cashback_amount' => $validated['cashback_amounts'][$index],
                'cashback_date' => $validated['cashback_date'],
                'status' => 'PENDING'
            ]);

            $cashbacks[] = $cashback;
        }

        return response()->json([
            'message' => 'Bulk cashback created successfully',
            'count' => count($cashbacks),
            'cashbacks' => $cashbacks
        ]);
    }

    // Get all cashback records
    public function getCashbacks(Request $request)
    {
        $query = MerchantCashback::with(['merchant', 'tier', 'approver']);

        if ($request->has('status') && $request->status !== 'ALL') {
            $query->where('status', $request->status);
        }

        if ($request->has('merchant_id')) {
            $query->where('merchant_id', $request->merchant_id);
        }

        if ($request->has('date_from')) {
            $query->where('cashback_date', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->where('cashback_date', '<=', $request->date_to);
        }

        $cashbacks = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 50));

        return response()->json($cashbacks);
    }

    // Approve cashback and credit wallet
    public function approveCashback(Request $request, $id)
    {
        $cashback = MerchantCashback::findOrFail($id);

        if ($cashback->status !== 'PENDING') {
            return response()->json(['error' => 'Cashback already processed'], 400);
        }

        DB::transaction(function () use ($cashback, $request) {
            // Get merchant wallet (ensure it exists)
            $wallet = $this->walletService->getWallet($cashback->merchant_id);
            if (!$wallet) {
                $wallet = $this->walletService->createWallet($cashback->merchant_id);
            }

            // Credit wallet via Central Treasury
            $this->walletService->transferSystemFunds(
                $cashback->merchant_id,
                $cashback->cashback_amount,
                'CASHBACK',
                "Merchant Cashback for " . $cashback->cashback_date->format('d M Y'),
                'OUT'
            );

            // Update cashback status
            $cashback->status = 'APPROVED';
            $cashback->approved_by = Auth::id();
            $cashback->approved_at = now();
            $cashback->save();
        });

        return response()->json([
            'message' => 'Cashback approved and credited',
            'cashback' => $cashback->fresh(['merchant', 'tier', 'approver'])
        ]);
    }

    // Reject cashback
    public function rejectCashback(Request $request, $id)
    {
        $validated = $request->validate([
            'notes' => 'nullable|string'
        ]);

        $cashback = MerchantCashback::findOrFail($id);

        if ($cashback->status !== 'PENDING') {
            return response()->json(['error' => 'Cashback already processed'], 400);
        }

        $cashback->status = 'REJECTED';
        $cashback->notes = $validated['notes'] ?? $cashback->notes;
        $cashback->save();

        return response()->json([
            'message' => 'Cashback rejected',
            'cashback' => $cashback
        ]);
    }

    // Get merchant statistics
    public function getMerchantStats($merchantId)
    {
        $merchant = User::where('role', 'MERCHANT')->findOrFail($merchantId);
        $wallet = $merchant->wallet;

        $stats = [
            'total_cashback_earned' => MerchantCashback::where('merchant_id', $merchantId)
                ->where('status', 'APPROVED')
                ->sum('cashback_amount'),
            'pending_cashback' => MerchantCashback::where('merchant_id', $merchantId)
                ->where('status', 'PENDING')
                ->sum('cashback_amount'),
            'total_transactions' => 0,
            'today_turnover' => 0,
            'month_turnover' => 0,
        ];

        if ($wallet) {
            $stats['total_transactions'] = WalletTransaction::where('wallet_id', $wallet->id)
                ->where('type', 'CREDIT')
                ->where('source_type', 'QR_PAYMENT')
                ->count();

            $stats['today_turnover'] = WalletTransaction::where('wallet_id', $wallet->id)
                ->where('type', 'CREDIT')
                ->where('source_type', 'QR_PAYMENT')
                ->whereDate('created_at', today())
                ->sum('amount');

            $stats['month_turnover'] = WalletTransaction::where('wallet_id', $wallet->id)
                ->where('type', 'CREDIT')
                ->where('source_type', 'QR_PAYMENT')
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->sum('amount');
        }

        return response()->json($stats);
    }
}
