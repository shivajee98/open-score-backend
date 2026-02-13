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
        // Ensure only ADMIN can generate
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['message' => 'Unauthorized: Only admins can generate QR batches'], 403);
        }

        $request->validate([
            'count' => 'required|integer|min:1|max:1000',
            'name' => 'nullable|string'
        ]);

        try {
            DB::beginTransaction();

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

            DB::commit();

            return response()->json([
                'message' => 'QR Codes generated successfully',
                'batch_id' => $batchId,
                'count' => $request->count
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Illuminate\Support\Facades\Log::error('QR Generation Failure: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'message' => 'Failed to generate QR codes. Please check server logs.',
                'error' => $e->getMessage()
            ], 500);
        }
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

    public function deleteCode($id)
    {
        $qr = DB::table('qr_codes')->where('id', $id)->first();
        if (!$qr) {
            return response()->json(['message' => 'QR Code not found'], 404);
        }

        if ($qr->status === 'assigned') {
            return response()->json(['message' => 'Cannot delete a mapped QR code'], 400);
        }

        DB::table('qr_codes')->where('id', $id)->delete();

        return response()->json(['message' => 'QR Code deleted successfully']);
    }

    public function deleteBatchUnmapped($id)
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        try {
            DB::beginTransaction();
            
            // Delete only active (unmapped) codes for this batch
            $deletedCount = DB::table('qr_codes')
                ->where('batch_id', $id)
                ->where('status', '!=', 'assigned')
                ->delete();

            // Check if there are any remaining codes in this batch
            $remainingCount = DB::table('qr_codes')->where('batch_id', $id)->count();
            
            if ($remainingCount === 0) {
                // If no codes left (not even assigned ones), we can delete the batch record too
                DB::table('qr_batches')->where('id', $id)->delete();
            } else {
                // Otherwise, update the batch count to reflect reality
                DB::table('qr_batches')->where('id', $id)->update(['count' => $remainingCount]);
            }

            DB::commit();

            return response()->json([
                'message' => "Deleted $deletedCount unmapped codes from batch.",
                'remaining' => $remainingCount
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error clearing data: ' . $e->getMessage()], 500);
        }
    }

    public function deleteGlobalUnmapped()
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        try {
            DB::beginTransaction();

            // Delete all codes not assigned
            $deletedCount = DB::table('qr_codes')
                ->where('status', '!=', 'assigned')
                ->delete();

            // Cleanup empty batches
            $emptyBatchIds = DB::table('qr_batches')
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('qr_codes')
                        ->whereRaw('qr_codes.batch_id = qr_batches.id');
                })
                ->pluck('id');

            DB::table('qr_batches')->whereIn('id', $emptyBatchIds)->delete();

            // Update remaining batches' counts
            $remainingBatches = DB::table('qr_batches')->get();
            foreach ($remainingBatches as $batch) {
                $count = DB::table('qr_codes')->where('batch_id', $batch->id)->count();
                DB::table('qr_batches')->where('id', $batch->id)->update(['count' => $count]);
            }

            DB::commit();

            return response()->json(['message' => "Deleted $deletedCount unmapped codes globally across the system."]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error clearing data: ' . $e->getMessage()], 500);
        }
    }

    public function moveToBatch(Request $request)
    {
        if (Auth::user()->role !== 'ADMIN') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'qr_ids' => 'required|array',
            'qr_ids.*' => 'integer',
            'batch_id' => 'nullable|integer',
            'new_batch_name' => 'nullable|string'
        ]);

        try {
            DB::beginTransaction();

            $batchId = $request->batch_id;

            if (!$batchId && $request->new_batch_name) {
                $batchId = DB::table('qr_batches')->insertGetId([
                    'name' => $request->new_batch_name,
                    'count' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if (!$batchId) {
                throw new \Exception('Batch ID or New Batch Name is required');
            }

            // Get original batch IDs before moving
            $originalBatchIds = DB::table('qr_codes')
                ->whereIn('id', $request->qr_ids)
                ->pluck('batch_id')
                ->unique()
                ->toArray();

            // Update QR codes
            DB::table('qr_codes')
                ->whereIn('id', $request->qr_ids)
                ->update(['batch_id' => $batchId, 'updated_at' => now()]);

            // Refresh counts for all affected batches
            $batchIdsToUpdate = array_merge($originalBatchIds, [$batchId]);

            foreach (array_unique($batchIdsToUpdate) as $id) {
                $count = DB::table('qr_codes')->where('batch_id', $id)->count();
                DB::table('qr_batches')->where('id', $id)->update(['count' => $count]);
            }

            DB::commit();

            return response()->json(['message' => 'QR codes moved successfully', 'batch_id' => $batchId]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to move QR codes', 'error' => $e->getMessage()], 500);
        }
    }
}
