<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function updateFcmToken(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $user = $request->user();
        if ($user) {
            $user->update(['fcm_token' => $request->token]);
            return response()->json(['message' => 'Token updated successfully']);
        }

        return response()->json(['message' => 'User not found'], 404);
    }
}
