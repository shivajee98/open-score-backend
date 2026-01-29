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
    public function adminIndex()
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        return response()->json(LoanPlan::all());
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
            'tenure_days' => 'required|integer|min:1',
            'interest_rate' => 'required|numeric|min:0',
            'repayment_frequency' => 'required|string',
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
     * Toggle active status or delete.
     */
    public function destroy($id)
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $plan = LoanPlan::findOrFail($id);
        // Soft delete logic or just set inactive? 
        // For now, let's just delete if no loans rely on it? NO, dangerous.
        // It's safer to just set inactive.
        $plan->is_active = false;
        $plan->save();

        return response()->json(['message' => 'Plan deactivated successfully']);
    }
}
