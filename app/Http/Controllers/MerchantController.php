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

        // Apply filters
        if ($request->business_segment) {
            $query->where('business_segment', $request->business_segment);
        }

        if ($request->business_type) {
            $query->where('business_type', 'like', '%' . $request->business_type . '%');
        }

        // Apply Sorting
        if ($request->sort) {
            switch ($request->sort) {
                case 'name_asc':
                    $query->orderBy('business_name', 'asc');
                    break;
                case 'name_desc':
                    $query->orderBy('business_name', 'desc');
                    break;
                case 'newest':
                    $query->orderBy('created_at', 'desc');
                    break;
            }
        }

        $merchants = $query->select('id', 'name', 'business_name', 'business_address', 'pincode', 'city', 'mobile_number', 'business_nature', 'description', 'location_url', 'profile_image', 'business_segment', 'business_type', 'shop_images', 'map_location_url')
                           ->get();

        return response()->json($merchants);
    }

    public function show($id)
    {
        $merchant = User::where('role', 'MERCHANT')
                        ->where('id', $id)
                        ->select('id', 'name', 'business_name', 'business_address', 'pincode', 'city', 'mobile_number', 'email', 'business_nature', 'description', 'location_url', 'profile_image', 'business_segment', 'business_type', 'shop_images', 'map_location_url')
                        ->firstOrFail();

        return response()->json($merchant);
    }
}
