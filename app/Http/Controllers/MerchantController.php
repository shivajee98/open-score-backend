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
            $query->where('pincode', 'like', '%' . $request->pincode . '%');
        }

        if ($request->city) {
            $query->where('city', 'like', '%' . $request->city . '%');
        }

        if (!$request->pincode && !$request->city) {
            // Fallback to user's location if available
            $user = Auth::guard('api')->user();
            if ($user && ($user->pincode || $user->city)) {
                 $query->where(function($q) use ($user) {
                     if ($user->pincode) $q->orWhere('pincode', 'like', "%{$user->pincode}%");
                     if ($user->city) $q->orWhere('city', 'like', "%{$user->city}%");
                 });
            }
        }

        $merchants = $query->select('id', 'name', 'business_name', 'business_address', 'pincode', 'city', 'mobile_number')
                           ->get(); // Limit?

        return response()->json($merchants);
    }
}
