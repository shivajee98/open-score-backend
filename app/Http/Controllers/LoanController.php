<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Loan;
use App\Services\WalletService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class LoanController extends Controller
{
    protected $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }

    public function apply(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'tenure' => 'required|integer',
            'payout_frequency' => 'required|string',
            'payout_option_id' => 'required|string'
        ]);
        
        $loan = Loan::create([
            'user_id' => Auth::id(),
            'amount' => $request->amount,
            'tenure' => $request->tenure,
            'payout_frequency' => $request->payout_frequency,
            'payout_option_id' => $request->payout_option_id,
            'status' => 'PENDING'
        ]);

        return response()->json($loan, 201);
    }

    public function index()
    {
        return response()->json(Loan::where('user_id', Auth::id())->orderBy('created_at', 'desc')->get());
    }

    public function approve(Request $request, $id)
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $loan = Loan::findOrFail($id);
        if ($loan->status !== 'PENDING') {
            return response()->json(['error' => 'Loan not pending'], 400);
        }

        DB::transaction(function () use ($loan) {
            $loan->status = 'APPROVED';
            $loan->approved_at = now();
            $loan->approved_by = Auth::id();
            $loan->save();

            $wallet = $this->walletService->getWallet($loan->user_id);
            if (!$wallet) $wallet = $this->walletService->createWallet($loan->user_id);
            
            $this->walletService->credit($wallet->id, $loan->amount, 'LOAN', $loan->id, "Loan Approved");

            DB::table('admin_logs')->insert([
                'admin_id' => Auth::id(),
                'action' => 'loan_approved',
                'description' => "Approved loan of ₹{$loan->amount} for User ID: {$loan->user_id}",
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json($loan);
    }
    
    public function listAll()
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        return response()->json(Loan::with('user')->where('status', 'PENDING')->get());
    }
}
