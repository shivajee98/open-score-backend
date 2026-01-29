<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\LoanPlan;
use Illuminate\Support\Facades\Auth;

class LoanPlanController extends Controller
{
    /**
     * Public endpoint to get active plans for customers.
     */
    public function index()
    {
        return response()->json(LoanPlan::where('is_active', true)->get());
    }

    /**
     * Admin endpoint to get all plans.
     */
    /**
     * Admin endpoint to get all plans with stats.
     */
    public function adminIndex(Request $request)
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $query = LoanPlan::query();

        // Stats: Total loans, Active loans, Defaulted loans
        $query->withCount([
            'loans',
            'loans as active_loans_count' => fn($q) => $q->whereIn('status', ['APPROVED', 'DISBURSED', 'OVERDUE']),
            'loans as defaulted_loans_count' => fn($q) => $q->where('status', 'DEFAULTED')
        ]);

        if ($request->query('status') === 'archived') {
            $query->onlyTrashed();
        } else {
            // Default active view (not deleted)
            // Optional: You might want to filter is_active=true too, 
            // but usually in Admin we want to see all non-deleted plans even if inactive.
        }
        
        return response()->json($query->orderBy('created_at', 'desc')->get());
    }

    /**
     * Get detailed insights for a specific plan.
     */
    public function showInsights($id)
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $plan = LoanPlan::withTrashed()->withCount([
            'loans',
            'loans as active_loans_count' => fn($q) => $q->whereIn('status', ['APPROVED', 'DISBURSED', 'OVERDUE']),
            'loans as defaulted_loans_count' => fn($q) => $q->where('status', 'DEFAULTED'),
            'loans as pending_loans_count' => fn($q) => $q->whereIn('status', ['PENDING', 'PROCEEDED', 'KYC_SENT', 'FORM_SUBMITTED']),
            'loans as fully_paid_loans_count' => fn($q) => $q->where('status', 'CLOSED'), // Assuming CLOSED or PAID match
        ])->findOrFail($id);

        // Calculate financial aggregates
        $totalDisbursed = $plan->loans()->where('status', 'DISBURSED')->sum('amount');
        // This assumes LoanRepayment has a loan_id and the loan belongs to this plan
        $totalRepaid = \App\Models\LoanRepayment::whereHas('loan', fn($q) => $q->where('loan_plan_id', $id))
            ->where('status', 'PAID')
            ->sum('amount');

        return response()->json([
            'plan' => $plan,
            'stats' => [
                'total_disbursed' => $totalDisbursed,
                'total_repaid' => $totalRepaid,
                'repayment_rate' => $totalDisbursed > 0 ? round(($totalRepaid / $totalDisbursed) * 100, 1) : 0, 
            ]
        ]);
    }

    /**
     * Store a new loan plan.
     */
    public function store(Request $request)
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'name' => 'required|string',
            'amount' => 'required|numeric|min:0',
            'configurations' => 'required|array',
            'configurations.*.tenure_days' => 'required|integer|min:1',
            'configurations.*.interest_rate' => 'required|numeric|min:0',
            'configurations.*.allowed_frequencies' => 'required|array',
            'configurations.*.fees' => 'array', // Optional custom fees
            'configurations.*.fees.*.name' => 'required_with:configurations.*.fees|string',
            'configurations.*.fees.*.amount' => 'required_with:configurations.*.fees|numeric',
            'configurations.*.cashback' => 'array', // Key: Freq, Value: Amount
            'plan_color' => 'required|string'
        ]);

        $plan = LoanPlan::create($request->all());
        return response()->json($plan, 201);
    }

    /**
     * Update an existing loan plan.
     */
    public function update(Request $request, $id)
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $plan = LoanPlan::findOrFail($id);
        $plan->update($request->all());

        return response()->json($plan);
    }

    /**
     * Soft delete a plan.
     */
    public function destroy($id)
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $plan = LoanPlan::findOrFail($id);
        $plan->delete(); // Soft delete due to trait

        return response()->json(['message' => 'Plan archived successfully']);
    }

    /**
     * Restore a soft-deleted plan.
     */
    public function restore($id)
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $plan = LoanPlan::withTrashed()->findOrFail($id);
        $plan->restore();

        return response()->json(['message' => 'Plan restored successfully']);
    }
}
