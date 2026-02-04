<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\QrController;
use App\Http\Controllers\SupportController;

Route::post('/auth/otp', [AuthController::class, 'requestOtp']);
Route::post('/auth/verify', [AuthController::class, 'verifyOtp']);
Route::get('/payees', [AuthController::class, 'listPayees']);
Route::get('/merchants', [AuthController::class, 'listPayees']); // Legacy support

// External KYC (Publicly accessible with token)
Route::get('/kyc/verify/{token}', [LoanController::class, 'verifyKycToken']);
Route::post('/kyc/submit/{token}', [LoanController::class, 'submitKycData']);
Route::get('/merchants/nearby', [\App\Http\Controllers\MerchantController::class, 'nearby']);
Route::get('/merchants/{id}', [\App\Http\Controllers\MerchantController::class, 'show']);

Route::middleware('auth:api')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::put('/auth/me/update', [AuthController::class, 'updateProfile']);
    Route::post('/auth/onboarding', [AuthController::class, 'completeOnboarding']);
    
    // Wallet
    Route::get('/wallet/balance', [WalletController::class, 'getBalance']);
    Route::get('/wallet/transactions', [WalletController::class, 'getTransactions']);
    Route::get('/wallet/check-pin', [WalletController::class, 'checkPin']);
    Route::post('/wallet/set-pin', [WalletController::class, 'setPin']);
    Route::post('/wallet/verify-pin', [WalletController::class, 'verifyPin']);
    Route::post('/auth/set-pin', [WalletController::class, 'setPin']); // Alias for frontend
    Route::post('/auth/update-profile', [AuthController::class, 'updateProfile']);
    Route::post('/auth/complete-merchant-profile', [AuthController::class, 'completeMerchantProfile']);
    
    // Loan
    Route::post('/loans/apply', [LoanController::class, 'apply']);
    Route::post('/loans/calculate-preview', [LoanController::class, 'calculatePreview']); // EMI preview calculator
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
    Route::get('/admin/loans/history', [LoanController::class, 'listHistory']);
    Route::get('/admin/loans/{id}/details', [LoanController::class, 'getDetails']); // New
    Route::post('/admin/loans/repayments/{id}/manual-collect', [LoanController::class, 'manualCollect']); // New
    Route::post('/admin/loans/repayments/{id}/approve', [LoanController::class, 'approveManualCollect']); // New
    Route::post('/admin/loans/{id}/close', [LoanController::class, 'closeManually']);
    Route::delete('/admin/loans/{id}', [LoanController::class, 'destroy']);
    Route::get('/admin/logs', [AdminController::class, 'getLogs']);
    Route::get('/logs', [AdminController::class, 'getLogs']); // Alias for frontend consistency 
    Route::get('/admin/payouts', [PaymentController::class, 'listWithdrawals']);
    Route::post('/admin/payouts/{id}/approve', [PaymentController::class, 'approveWithdrawal']);
    
    Route::get('/merchant/qr', [PaymentController::class, 'getMyQr']);
    Route::get('/payment/qr', [PaymentController::class, 'getMyQr']);
    // Existing routes...
    Route::post('/merchant/withdraw', [PaymentController::class, 'requestWithdrawal']);
    Route::post('/wallet/request-withdrawal', [PaymentController::class, 'requestWithdrawal']); // Added for customer app
    Route::get('/wallet/withdrawals', [PaymentController::class, 'getMyWithdrawals']);
    
    // Admin Routes
    Route::prefix('admin')->group(function () {
        Route::resource('withdrawal-rules', \App\Http\Controllers\Admin\WithdrawalRuleController::class);
    });
    Route::get('/payment/payee/{uuid}', [PaymentController::class, 'findPayee']);
    Route::get('/payment/search', [PaymentController::class, 'searchPayee']);
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

    // Admin Payouts
    Route::post('/admin/payouts/{id}/reject', [PaymentController::class, 'rejectWithdrawal']);

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
    
    // Loan Plans
    Route::get('/loan-plans', [\App\Http\Controllers\LoanPlanController::class, 'index']); // Public (Auth req)
    Route::get('/admin/loan-plans', [\App\Http\Controllers\LoanPlanController::class, 'adminIndex']);
    Route::post('/admin/loan-plans', [\App\Http\Controllers\LoanPlanController::class, 'store']);
    Route::put('/admin/loan-plans/{id}', [\App\Http\Controllers\LoanPlanController::class, 'update']);
    Route::delete('/admin/loan-plans/{id}', [\App\Http\Controllers\LoanPlanController::class, 'destroy']);
    Route::post('/admin/loan-plans/{id}/restore', [\App\Http\Controllers\LoanPlanController::class, 'restore']);
    Route::get('/admin/loan-plans/{id}/insights', [\App\Http\Controllers\LoanPlanController::class, 'showInsights']);
    Route::get('/admin/users/targetable', [AdminController::class, 'getTargetableUsers']);
    
    // Support System
    Route::get('/support/tickets', [SupportController::class, 'index']);
    Route::post('/support/tickets', [SupportController::class, 'store']);
    Route::get('/support/tickets/{id}', [SupportController::class, 'show']);
    Route::post('/support/tickets/{id}/message', [SupportController::class, 'sendMessage']);
    Route::put('/support/tickets/{id}/status', [SupportController::class, 'updateStatus']);
    
    // Admin Support Logic
    Route::get('/admin/support/tickets', [SupportController::class, 'adminIndex']);
    Route::post('/admin/support/assign/{id}', [SupportController::class, 'assign']);

    // Admin User Details
    Route::get('/admin/users/{id}/transactions', [AdminController::class, 'getUserTransactions']);
    Route::post('/admin/users/bulk-cashback', [AdminController::class, 'bulkUpdateCashback']);
    // Referral System
    Route::get('/admin/referrals', [\App\Http\Controllers\ReferralController::class, 'index']);
    Route::post('/admin/referrals', [\App\Http\Controllers\ReferralController::class, 'store']);
    Route::get('/admin/referrals/{id}', [\App\Http\Controllers\ReferralController::class, 'show']);
    
    // Sub-User Management
    Route::get('/admin/sub-users', [\App\Http\Controllers\SubUserController::class, 'index']);
    Route::post('/admin/sub-users', [\App\Http\Controllers\SubUserController::class, 'store']);
    Route::get('/admin/sub-users/{id}', [\App\Http\Controllers\SubUserController::class, 'show']);
    Route::put('/admin/sub-users/{id}', [\App\Http\Controllers\SubUserController::class, 'update']);
    Route::post('/admin/sub-users/{id}/credit', [\App\Http\Controllers\SubUserController::class, 'addCredit']);
    Route::get('/admin/sub-users/{id}/stats', [\App\Http\Controllers\SubUserController::class, 'getReferralStats']);
    
    // Signup Cashback Settings
    Route::get('/admin/cashback-settings', [\App\Http\Controllers\AdminController::class, 'getCashbackSettings']);
    Route::put('/admin/cashback-settings/{role}', [\App\Http\Controllers\AdminController::class, 'updateCashbackSetting']);
    
});

// Sub-User Login (Public)
Route::post('/auth/sub-user/login', [\App\Http\Controllers\SubUserController::class, 'login']);

// Sub-User Protected Routes
Route::middleware('auth:sub-user')->group(function () {
    Route::get('/admin/sub-users/{id}/stats', [\App\Http\Controllers\SubUserController::class, 'getReferralStats']);
});
