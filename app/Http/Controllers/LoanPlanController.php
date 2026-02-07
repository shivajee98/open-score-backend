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
        $userId = Auth::id();
        $plans = LoanPlan::where('is_active', true)
            ->where(function($q) use ($userId) {
                $q->where('is_public', true)
                  ->orWhereHas('users', fn($uq) => $uq->where('users.id', $userId));
            })
            ->orderBy('amount', 'asc')
            ->get();

        // Fetch amounts of loans "taken" by user (Disbursed or Closed)
        // We assume 'DISBURSED', 'CLOSED', 'SETTLED' imply the user successfully reached the stage of taking the loan.
        $takenAmounts = \App\Models\Loan::where('user_id', $userId)
            ->whereIn('status', ['DISBURSED', 'CLOSED', 'SETTLED', 'PAID'])
            ->pluck('amount')
            ->map(fn($a) => (float)$a)
            ->unique()
            ->toArray();

        $plans->transform(function ($plan) use ($plans, $takenAmounts) {
            $amount = (float)$plan->amount;
            $isLocked = $plan->is_locked; // respect the manual lock

            // Lock logic for > 50k (keep existing automatic logic)
            if (!$isLocked && $amount > 50000) {
                // Find previous level plan (largest amount strictly less than current)
                $prevPlan = $plans->where('amount', '<', $amount)->sortByDesc('amount')->first();
                
                if ($prevPlan) {
                    $prevAmount = (float)$prevPlan->amount;
                    if (!in_array($prevAmount, $takenAmounts)) {
                        $isLocked = true;
                    }
                }
            }
            
            $plan->is_locked = $isLocked;
            return $plan;
        });

        return response()->json($plans);
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
        
        return response()->json($query->orderBy('amount', 'asc')->get()->each->append('assigned_user_ids'));
    }

    /**
     * Get detailed insights for a specific plan.
     */
    public function showInsights($id)
    {
        if (\Illuminate\Support\Facades\Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $plan = LoanPlan::withTrashed()->withCount([
            'loans',
            'loans as active_loans_count' => fn($q) => $q->whereIn('status', ['APPROVED', 'DISBURSED', 'OVERDUE']),
            'loans as defaulted_loans_count' => fn($q) => $q->where('status', 'DEFAULTED'),
            'loans as pending_loans_count' => fn($q) => $q->whereIn('status', ['PENDING', 'PROCEEDED', 'KYC_SENT', 'FORM_SUBMITTED']),
            'loans as fully_paid_loans_count' => fn($q) => $q->where('status', 'CLOSED'), // Assuming CLOSED or PAID match
        ])->findOrFail($id);
        
        $plan->append('assigned_user_ids');

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
            'configurations.*.interest_rate' => 'nullable|numeric|min:0',
            'configurations.*.interest_rates' => 'nullable|array',
            'configurations.*.allowed_frequencies' => 'required|array',
            'configurations.*.fees' => 'array', // Optional custom fees
            'configurations.*.fees.*.name' => 'required_with:configurations.*.fees|string',
            'configurations.*.fees.*.amount' => 'required_with:configurations.*.fees|numeric',
            'configurations.*.cashback' => 'array', // Key: Freq, Value: Amount
            'plan_color' => 'required|string',
            'is_locked' => 'nullable|boolean'
        ]);

        $plan = LoanPlan::create($request->all());

        if (!$request->is_public && $request->has('assigned_user_ids')) {
            $plan->users()->sync($request->assigned_user_ids);
        }

        $plan->append('assigned_user_ids');
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

        if (array_key_exists('is_public', $request->all()) && !$request->is_public && $request->has('assigned_user_ids')) {
            $plan->users()->sync($request->assigned_user_ids);
        } elseif ($request->is_public) {
            $plan->users()->detach(); // If turned public, remove specific assignments
        }

        $plan->append('assigned_user_ids');
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
