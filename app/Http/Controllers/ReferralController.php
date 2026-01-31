<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ReferralCampaign;

class ReferralController extends Controller
{
    public function index()
    {
        $campaigns = ReferralCampaign::withCount('users')->latest()->get();
        return response()->json($campaigns);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'code' => 'required|string|unique:referral_campaigns,code',
            'cashback_amount' => 'required|numeric|min:0'
        ]);

        $campaign = ReferralCampaign::create([
            'name' => $request->name,
            'code' => strtoupper($request->code),
            'cashback_amount' => $request->cashback_amount,
            'is_active' => true
        ]);

        return response()->json($campaign, 201);
    }

    public function show($id)
    {
        $campaign = ReferralCampaign::with('users')->findOrFail($id);
        return response()->json($campaign);
    }
}
