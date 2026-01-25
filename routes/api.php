<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\AdminController;

Route::post('/auth/otp', [AuthController::class, 'requestOtp']);
Route::post('/auth/verify', [AuthController::class, 'verifyOtp']);
Route::get('/merchants', [AuthController::class, 'listMerchants']);

Route::middleware('auth:api')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    
    // Wallet
    Route::get('/wallet/balance', [WalletController::class, 'getBalance']);
    Route::get('/wallet/transactions', [WalletController::class, 'getTransactions']);
    
    // Loan
    Route::post('/loans/apply', [LoanController::class, 'apply']);
    Route::get('/loans', [LoanController::class, 'index']);
    
    // Admin
    Route::post('/admin/loans/{id}/approve', [LoanController::class, 'approve']);
    Route::get('/admin/loans', [LoanController::class, 'listAll']);
    Route::get('/admin/logs', [AdminController::class, 'getLogs']);
    Route::get('/admin/payouts', [PaymentController::class, 'listWithdrawals']);
    Route::post('/admin/payouts/{id}/approve', [PaymentController::class, 'approveWithdrawal']);
    
    Route::get('/merchant/qr', [PaymentController::class, 'getMyQr']);
    Route::post('/merchant/withdraw', [PaymentController::class, 'requestWithdrawal']);
    Route::get('/payment/merchant/{uuid}', [PaymentController::class, 'findMerchant']);
    Route::post('/payment/pay', [PaymentController::class, 'pay']);
});
