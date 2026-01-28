<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\WalletTransaction;
use App\Models\MerchantCashback;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    public function getDashboardStats()
    {
        // Loan Stats
        $totalDisbursed = Loan::where('status', 'DISBURSED')->sum('amount');
        
        $totalRepaid = LoanRepayment::where('status', 'PAID')->sum('amount');
        
        $activeLoansCount = Loan::whereIn('status', ['DISBURSED', 'APPROVED'])->count();
        $completedLoansCount = Loan::where('status', 'CLOSED')->count(); // Assuming CLOSED status exists or we check repayments
        
        // Money Flow High Level
        $totalMerchantTransfer = WalletTransaction::where('source_type', 'QR_PAYMENT')->sum('amount');
        
        return response()->json([
            'total_disbursed' => $totalDisbursed,
            'total_repaid' => $totalRepaid,
            'active_loans' => $activeLoansCount,
            'completed_loans' => $completedLoansCount,
            'total_merchant_volume' => $totalMerchantTransfer,
            'total_users' => User::where('role', 'CUSTOMER')->count(),
            'total_merchants' => User::where('role', 'MERCHANT')->count(),
        ]);
    }

    public function getDeepAnalytics()
    {
        // 1. Loan Performance
        $loanPerformance = [
            'total_applications' => Loan::count(),
            'approval_rate' => Loan::count() > 0 ? (Loan::whereIn('status', ['APPROVED', 'DISBURSED', 'CLOSED'])->count() / Loan::count()) * 100 : 0,
            'default_rate' => 0, // Placeholder for now
            'average_loan_size' => Loan::where('status', 'DISBURSED')->avg('amount') ?? 0,
        ];

        // 2. Money Flow Map (Sources & Sinks)
        // Admin -> Users (Disbursals + Credits)
        $inflowUser = WalletTransaction::whereIn('source_type', ['LOAN', 'ADMIN_CREDIT'])->sum('amount');
        
        // Users -> Merchants (QR Payments)
        $flowUserToMerchant = WalletTransaction::where('source_type', 'QR_PAYMENT')->sum('amount');
        
        // Users -> Admin (Repayments)
        $flowUserToAdmin = LoanRepayment::where('status', 'PAID')->sum('amount');

        // Merchants -> Out (Withdrawals) - This would be tracked via Payouts/Withdrawals
        $flowMerchantOut = \App\Models\WithdrawRequest::where('status', 'COMPLETED')->sum('amount');

        $moneyFlow = [
            'admin_to_users' => $inflowUser,
            'users_to_merchants' => $flowUserToMerchant,
            'users_to_admin' => $flowUserToAdmin,
            'merchants_out' => $flowMerchantOut,
        ];

        // 3. User Growth (Last 6 Months)
        $userGrowth = User::select(DB::raw('DATE_FORMAT(created_at, "%Y-%m") as month'), DB::raw('count(*) as count'))
            ->where('created_at', '>=', now()->subMonths(6))
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        // 4. Transaction Volume (Last 30 Days)
        $dailyVolume = WalletTransaction::select(DB::raw('DATE(created_at) as date'), DB::raw('sum(amount) as total'))
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return response()->json([
            'loan_performance' => $loanPerformance,
            'money_flow' => $moneyFlow,
            'user_growth' => $userGrowth,
            'daily_volume' => $dailyVolume
        ]);
    }
}
