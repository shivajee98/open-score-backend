<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\File;

Route::get('/', function () {
    return view('welcome');
});

// Hostinger Storage Fallback: Serve files from storage if symlink is broken
Route::get('/storage/{path}', function ($path) {
    $path = storage_path('app/public/' . $path);

    if (!File::exists($path)) {
        abort(404);
    }

    return response()->file($path);
})->where('path', '.*');


