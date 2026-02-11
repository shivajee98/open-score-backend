<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Events\IncomingCall;
use App\Events\CallAnswered;
use App\Events\IceCandidate;
use App\Events\EndCall;

class CallController extends Controller
{
    /**
     * Initiate a call
     */
    public function initiate(Request $request)
    {
        $request->validate([
            'offer' => 'required',
            'to' => 'required|exists:users,id'
        ]);

        $caller = Auth::user();
        $receiverId = $request->to;
        
        // Broadcast event to receiver
        broadcast(new IncomingCall($request->offer, $caller, $receiverId))->toOthers();

        return response()->json(['message' => 'Call initiated']);
    }

    /**
     * Answer a call
     */
    public function answer(Request $request)
    {
        $request->validate([
            'answer' => 'required',
            'to' => 'required|exists:users,id' // This is the original caller's ID
        ]);

        $originalCallerId = $request->to;

        // Broadcast event to caller
        broadcast(new CallAnswered($request->answer, $originalCallerId))->toOthers();

        return response()->json(['message' => 'Call answered']);
    }

    /**
     * Exchange ICE candidates
     */
    public function iceCandidate(Request $request)
    {
        $request->validate([
            'candidate' => 'required',
            'to' => 'required|exists:users,id'
        ]);

        $targetId = $request->to;

        // Broadcast event to target
        broadcast(new IceCandidate($request->candidate, $targetId))->toOthers();

        return response()->json(['message' => 'ICE candidate sent']);
    }

    /**
     * End a call
     */
    public function end(Request $request)
    {
        $request->validate([
            'to' => 'required|exists:users,id'
        ]);

        $targetId = $request->to;

        // Broadcast event to target
        broadcast(new EndCall($targetId))->toOthers();

        return response()->json(['message' => 'Call ended']);
    }
}
