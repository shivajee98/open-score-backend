<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\QrController;

Route::post('/auth/otp', [AuthController::class, 'requestOtp']);
Route::post('/auth/verify', [AuthController::class, 'verifyOtp']);
Route::get('/payees', [AuthController::class, 'listPayees']);
Route::get('/merchants', [AuthController::class, 'listPayees']); // Legacy support

// External KYC (Publicly accessible with token)
Route::get('/kyc/verify/{token}', [LoanController::class, 'verifyKycToken']);
Route::post('/kyc/submit/{token}', [LoanController::class, 'submitKycData']);

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
    Route::post('/loans/{id}/confirm', [LoanController::class, 'confirm']);
    Route::post('/loans/{id}/cancel', [LoanController::class, 'cancel']);
    Route::post('/loans/{id}/repay', [LoanController::class, 'repay']);
    Route::get('/loans/{id}/repayments', [LoanController::class, 'repayments']);
    Route::post('/loans/{id}/submit-form', [LoanController::class, 'submitForm']);
    
    
    // Admin
    Route::post('/admin/loans/{id}/proceed', [LoanController::class, 'proceed']);
    Route::post('/admin/loans/{id}/send-kyc', [LoanController::class, 'sendKyc']);
    Route::post('/admin/loans/{id}/approve', [LoanController::class, 'approve']);
    Route::post('/admin/loans/{id}/release', [LoanController::class, 'release']);
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
    
    // Admin Analytics
    Route::get('/admin/analytics/dashboard', [\App\Http\Controllers\AnalyticsController::class, 'getDashboardStats']);
    Route::get('/admin/analytics/deep', [\App\Http\Controllers\AnalyticsController::class, 'getDeepAnalytics']);

    // Admin Fund Approval
    Route::get('/admin/funds/pending', [AdminController::class, 'getPendingTransactions']);
    Route::post('/admin/funds/{id}/approve', [AdminController::class, 'approveFund']);
    Route::post('/admin/funds/{id}/reject', [AdminController::class, 'rejectFund']);

    // QR Codes
    Route::post('/admin/qr/generate', [QrController::class, 'generate']);
    Route::get('/admin/qr/batches', [QrController::class, 'getBatches']);
    Route::get('/admin/qr/batches/{id}', [QrController::class, 'getBatchCodes']);
    
    // Merchant Cashback Management
    Route::get('/admin/merchants', [\App\Http\Controllers\MerchantCashbackController::class, 'getMerchants']);
    Route::get('/admin/merchants/{id}/stats', [\App\Http\Controllers\MerchantCashbackController::class, 'getMerchantStats']);
    Route::get('/admin/cashback/tiers', [\App\Http\Controllers\MerchantCashbackController::class, 'getTiers']);
    Route::post('/admin/cashback/tiers', [\App\Http\Controllers\MerchantCashbackController::class, 'updateTier']);
    Route::put('/admin/cashback/tiers/{id}', [\App\Http\Controllers\MerchantCashbackController::class, 'updateTier']);
    Route::post('/admin/cashback/award', [\App\Http\Controllers\MerchantCashbackController::class, 'awardCashback']);
    Route::post('/admin/cashback/bulk-award', [\App\Http\Controllers\MerchantCashbackController::class, 'bulkAwardCashback']);
    Route::get('/admin/cashback', [\App\Http\Controllers\MerchantCashbackController::class, 'getCashbacks']);
    Route::post('/admin/cashback/{id}/approve', [\App\Http\Controllers\MerchantCashbackController::class, 'approveCashback']);
    Route::post('/admin/cashback/{id}/reject', [\App\Http\Controllers\MerchantCashbackController::class, 'rejectCashback']);
    
    Route::post('/merchant/link-qr', [QrController::class, 'link']);
});
