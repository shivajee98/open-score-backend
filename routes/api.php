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
Route::get('/payees', [AuthController::class, 'listPayees']);
Route::get('/merchants', [AuthController::class, 'listPayees']); // Legacy support

Route::middleware('auth:api')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/onboarding', [AuthController::class, 'completeOnboarding']);
    
    // Wallet
    Route::get('/wallet/balance', [WalletController::class, 'getBalance']);
    Route::get('/wallet/transactions', [WalletController::class, 'getTransactions']);
    Route::get('/wallet/check-pin', [WalletController::class, 'checkPin']);
    Route::post('/wallet/set-pin', [WalletController::class, 'setPin']);
    Route::post('/wallet/verify-pin', [WalletController::class, 'verifyPin']);
    Route::post('/auth/set-pin', [WalletController::class, 'setPin']); // Alias for frontend
    Route::post('/auth/update-profile', [AuthController::class, 'updateProfile']);
    
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
    Route::get('/payment/qr', [PaymentController::class, 'getMyQr']);
    Route::post('/merchant/withdraw', [PaymentController::class, 'requestWithdrawal']);
    Route::get('/payment/payee/{uuid}', [PaymentController::class, 'findPayee']);
    Route::post('/payment/pay', [PaymentController::class, 'pay']);

    // Admin User Management
    Route::get('/admin/users', [AdminController::class, 'getUsers']);
    Route::post('/admin/users/{id}/credit', [AdminController::class, 'creditUser']);
    Route::delete('/admin/users/{id}', [AdminController::class, 'deleteUser']);
    Route::post('/admin/users/{id}/status', [AdminController::class, 'updateStatus']);
    
    // Admin Fund Approval
    Route::get('/admin/funds/pending', [AdminController::class, 'getPendingTransactions']);
    Route::post('/admin/funds/{id}/approve', [AdminController::class, 'approveFund']);
    Route::post('/admin/funds/{id}/reject', [AdminController::class, 'rejectFund']);
});
