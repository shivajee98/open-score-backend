<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmService
{
    /**
     * Send a push notification using FCM Legacy HTTP API.
     * Note: FCM Legacy API will be deprecated. Consider using FCM v1 API in production.
     */
    public static function send($token, $title, $body, $data = [])
    {
        if (!$token) {
            return false;
        }

        $url = 'https://fcm.googleapis.com/fcm/send';
        $serverKey = env('FCM_SERVER_KEY');

        if (!$serverKey) {
            Log::error('FCM_SERVER_KEY not found in .env');
            return false;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'key=' . $serverKey,
                'Content-Type' => 'application/json',
            ])->post($url, [
                'to' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                    'sound' => 'default',
                    'click_action' => 'FCM_PLUGIN_ACTIVITY',
                    'icon' => 'fcm_push_icon'
                ],
                'data' => $data,
                'priority' => 'high'
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('FCM Send Error: ' . $response->body());
            return false;
        } catch (\Exception $e) {
            Log::error('FCM Exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send notification to a specific user.
     */
    public static function sendToUser($user, $title, $body, $data = [])
    {
        if (!$user || !$user->fcm_token) {
            return false;
        }

        return self::send($user->fcm_token, $title, $body, $data);
    }
}
