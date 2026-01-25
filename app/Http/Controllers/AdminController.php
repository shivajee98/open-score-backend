<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function getLogs()
    {
        $logs = DB::table('admin_logs')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();
            
        return response()->json($logs);
    }
}
