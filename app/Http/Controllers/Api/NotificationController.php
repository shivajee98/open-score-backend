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

    public function sendTestNotification(Request $request)
    {
        $request->validate([
            'mobile_number' => 'required|string',
            'title' => 'sometimes|string',
            'body' => 'sometimes|string',
        ]);

        $user = \App\Models\User::where('mobile_number', $request->mobile_number)->first();
        
        if (!$user) {
            return response()->json(['error' => 'User with this mobile number not found'], 404);
        }

        if (!$user->fcm_token) {
            return response()->json(['error' => 'User does not have an FCM token registered'], 400);
        }

        $title = $request->title ?? "Test Notification 🔔";
        $body = $request->body ?? "If you're reading this, push notifications are working!";

        $result = \App\Services\FcmService::sendToUser($user, $title, $body, ['type' => 'test']);

        if ($result) {
            return response()->json(['success' => true, 'message' => 'Notification sent successfully', 'fcm_response' => $result]);
        }

        return response()->json(['success' => false, 'message' => 'Failed to send notification'], 500);
    }
}
