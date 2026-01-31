<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class MerchantController extends Controller
{
    public function nearby(Request $request)
    {
        $request->validate([
            'pincode' => 'nullable|string',
            'city' => 'nullable|string'
        ]);

        $query = User::where('role', 'MERCHANT');

        if ($request->pincode) {
            $query->where('pincode', $request->pincode);
        } elseif ($request->city) {
            $query->where('city', $request->city);
        } else {
            // Fallback to user's location if available
            $user = Auth::guard('api')->user();
            if ($user) {
                if ($user->pincode) {
                    $query->where('pincode', $user->pincode);
                } elseif ($user->city) {
                    $query->where('city', $user->city);
                }
            }
        }

        $merchants = $query->select('id', 'name', 'business_name', 'business_address', 'pincode', 'city', 'mobile_number')
                           ->get(); // Limit?

        return response()->json($merchants);
    }
}
