<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\WithdrawalRule;
use App\Models\LoanPlan;
use Illuminate\Support\Facades\Validator;

class WithdrawalRuleController extends Controller
{
    public function index()
    {
        $rules = WithdrawalRule::with('loanPlan')->orderBy('created_at', 'desc')->get();
        return response()->json($rules);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'loan_plan_id' => 'nullable|exists:loan_plans,id',
            'user_type' => 'required|in:MERCHANT,CUSTOMER',
            'min_spend_amount' => 'required|numeric|min:0',
            'min_txn_count' => 'required|integer|min:0',
            'daily_limit' => 'nullable|numeric|min:0',
            'target_users' => 'nullable|array',
            'is_active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $rule = WithdrawalRule::create($request->all());
        return response()->json($rule, 201);
    }

    public function update(Request $request, $id)
    {
        $rule = WithdrawalRule::findOrFail($id);
        
        $validator = Validator::make($request->all(), [
            'min_spend_amount' => 'numeric|min:0',
            'min_txn_count' => 'integer|min:0',
            'daily_limit' => 'nullable|numeric|min:0',
            'target_users' => 'nullable|array',
            'is_active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $rule->update($request->all());
        return response()->json($rule);
    }

    public function destroy($id)
    {
        $rule = WithdrawalRule::findOrFail($id);
        $rule->delete();
        return response()->json(['message' => 'Rule deleted successfully']);
    }
}
