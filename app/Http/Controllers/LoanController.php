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

    public function proceed(Request $request, $id)
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $loan = Loan::findOrFail($id);
        if ($loan->status !== 'PENDING') {
            return response()->json(['error' => 'Can only proceed from PENDING state'], 400);
        }

        $loan->status = 'PROCEEDED';
        $loan->save();

        return response()->json($loan);
    }

    public function sendKyc(Request $request, $id)
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $loan = Loan::findOrFail($id);
        if ($loan->status !== 'PROCEEDED') {
            return response()->json(['error' => 'Can only send KYC from PROCEEDED state'], 400);
        }

        $loan->status = 'KYC_SENT';
        $loan->save();

        return response()->json($loan);
    }

    public function submitForm(Request $request, $id)
    {
        $loan = Loan::findOrFail($id);
        
        // Ensure only the owner can submit the form
        if ($loan->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($loan->status !== 'KYC_SENT') {
            return response()->json(['error' => 'KYC form not requested or already submitted'], 400);
        }

        $loan->form_data = $request->all();
        $loan->status = 'FORM_SUBMITTED';
        $loan->save();

        return response()->json($loan);
    }

    public function approve(Request $request, $id)
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $loan = Loan::findOrFail($id);
        if ($loan->status !== 'FORM_SUBMITTED') {
            return response()->json(['error' => 'Loan must have submitted form before approval'], 400);
        }

        $loan->status = 'APPROVED';
        $loan->approved_at = now();
        $loan->approved_by = Auth::id();
        $loan->save();

        DB::table('admin_logs')->insert([
            'admin_id' => Auth::id(),
            'action' => 'loan_approved',
            'description' => "Approved loan stage (pre-disbursal) for ₹{$loan->amount}, User ID: {$loan->user_id}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json($loan);
    }

    public function release(Request $request, $id)
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $loan = Loan::findOrFail($id);
        if ($loan->status !== 'APPROVED') {
            return response()->json(['error' => 'Loan must be approved before releasing funds'], 400);
        }

        DB::transaction(function () use ($loan) {
            $loan->status = 'DISBURSED';
            $loan->disbursed_at = now();
            $loan->disbursed_by = Auth::id();
            $loan->save();

            $wallet = $this->walletService->getWallet($loan->user_id);
            if (!$wallet) $wallet = $this->walletService->createWallet($loan->user_id);
            
            $this->walletService->credit($wallet->id, $loan->amount, 'LOAN', $loan->id, "Loan Disbursed");

            DB::table('admin_logs')->insert([
                'admin_id' => Auth::id(),
                'action' => 'loan_disbursed',
                'description' => "Disbursed funds for loan of ₹{$loan->amount} for User ID: {$loan->user_id}",
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
        // Include everything that is not yet fully finalized (DISBURSED or REJECTED)
        return response()->json(Loan::with('user')->whereNotIn('status', ['DISBURSED', 'REJECTED'])->orderBy('created_at', 'desc')->get());
    }
}
