<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class QrController extends Controller
{
    public function generate(Request $request)
    {
        $request->validate([
            'count' => 'required|integer|min:1|max:1000',
            'name' => 'nullable|string'
        ]);

        $batchId = DB::table('qr_batches')->insertGetId([
            'name' => $request->name ?? 'Batch ' . date('Y-m-d H:i:s'),
            'count' => $request->count,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $codes = [];
        for ($i = 0; $i < $request->count; $i++) {
            $codes[] = [
                'batch_id' => $batchId,
                'code' => (string) Str::uuid(),
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Insert in chunks to avoid limits
        foreach (array_chunk($codes, 100) as $chunk) {
            DB::table('qr_codes')->insert($chunk);
        }

        return response()->json([
            'message' => 'QR Codes generated successfully',
            'batch_id' => $batchId,
            'count' => $request->count
        ]);
    }

    public function getBatches()
    {
        $batches = DB::table('qr_batches')->orderBy('created_at', 'desc')->get();
        return response()->json($batches);
    }

    public function getBatchCodes($id)
    {
        $codes = DB::table('qr_codes')
            ->leftJoin('users', 'qr_codes.user_id', '=', 'users.id')
            ->select('qr_codes.*', 'users.name as merchant_name', 'users.mobile_number as merchant_mobile')
            ->where('qr_codes.batch_id', $id)
            ->get();
        return response()->json($codes);
    }

    public function link(Request $request)
    {
        $request->validate([
            'code' => 'required|uuid'
        ]);

        $user = Auth::user();
        
        $qr = DB::table('qr_codes')->where('code', $request->code)->first();

        if (!$qr) {
            return response()->json(['message' => 'Invalid QR Code'], 404);
        }

        if ($qr->status === 'assigned') {
             if ($qr->user_id == $user->id) {
                 return response()->json(['message' => 'QR Code is already linked to you']);
             }
             return response()->json(['message' => 'QR Code is already assigned to another merchant'], 400);
        }

        DB::table('qr_codes')->where('id', $qr->id)->update([
            'user_id' => $user->id,
            'status' => 'assigned',
            'updated_at' => now()
        ]);

        return response()->json(['message' => 'QR Code linked successfully']);
    }
}
