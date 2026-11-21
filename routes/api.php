<?php

use App\Http\Controllers\Api\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Api\Admin\AutomaticCreditController;
use App\Http\Controllers\Api\Admin\CustomerController as AdminCustomerController;
use App\Http\Controllers\Api\Admin\CreditRuleProposalController;
use App\Http\Controllers\Api\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\Admin\RotativeCreditGrantController;
use App\Http\Controllers\Api\AsaasWebhookController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\MaintenanceController;
use App\Http\Controllers\Api\PaymentQrCodeController;
use App\Http\Controllers\Api\StatementDocumentController;
use App\Http\Controllers\Api\TransferReceiptDocumentController;
use App\Http\Controllers\Api\TransferReceiptImageController;
use App\Http\Controllers\Api\WhatsappWebhookController;
use App\Http\Middleware\CustomerAuditMiddleware;
use App\Http\Middleware\VerifyConversationApiToken;
use App\Http\Middleware\VerifyWhatsappSignature;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->group(function () {
    Route::post('login', [AdminAuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('login/2fa', [AdminAuthController::class, 'twoFactorChallenge'])->middleware('throttle:5,1');

    Route::middleware(['auth:sanctum', CustomerAuditMiddleware::class])->group(function () {
        Route::get('me', [AdminAuthController::class, 'me']);
        Route::post('logout', [AdminAuthController::class, 'logout']);
        Route::get('dashboard', AdminDashboardController::class);
        Route::get('movements', [AdminDashboardController::class, 'movements']);
        Route::apiResource('users', AdminUserController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::post('users/{user}/two-factor/setup', [AdminUserController::class, 'setupTwoFactor']);
        Route::post('users/{user}/two-factor/confirm', [AdminUserController::class, 'confirmTwoFactor']);
        Route::delete('users/{user}/two-factor', [AdminUserController::class, 'disableTwoFactor']);
        Route::apiResource('customers', AdminCustomerController::class)->only(['index', 'store', 'show', 'update']);
        Route::get('customers/{customer}/automatic-credit', [AutomaticCreditController::class, 'show']);
        Route::post('customers/{customer}/automatic-credit', [AutomaticCreditController::class, 'store']);
        Route::put('customers/{customer}/rotative-wallet-access', [AdminCustomerController::class, 'updateRotativeWalletAccess']);
        Route::post('customers/{customer}/rotative-credit-grants', [RotativeCreditGrantController::class, 'store']);
        Route::get('customers/{customer}/rotative-credit-grants', [RotativeCreditGrantController::class, 'index']);
        Route::post('customers/{customer}/rotative-credit-debts/{debt}/payments', [RotativeCreditGrantController::class, 'payDebt']);
        Route::post('rotative-credit/release-eligible', [RotativeCreditGrantController::class, 'releaseEligible']);
        Route::get('customers/{customer}/movements', [AdminCustomerController::class, 'movements']);
        Route::get('customers/{customer}/statistics', [AdminCustomerController::class, 'statistics']);
        Route::get('customers/{customer}/credit-rule-proposal', CreditRuleProposalController::class);
        Route::get('customers/{customer}/messages', [AdminCustomerController::class, 'messages']);
        Route::get('customers/{customer}/name-history', [AdminCustomerController::class, 'nameHistory']);
    });
});

Route::prefix('conversations')->middleware([VerifyConversationApiToken::class, CustomerAuditMiddleware::class, 'throttle:120,1'])->group(function () {
    Route::post('start', [ConversationController::class, 'start']);
    Route::get('{conversationSession}', [ConversationController::class, 'show']);
    Route::post('{conversationSession}/messages', [ConversationController::class, 'message']);
    Route::post('{conversationSession}/reset', [ConversationController::class, 'reset']);
    Route::post('{conversationSession}/qr-codes', [PaymentQrCodeController::class, 'create']);
});

Route::get('/qr-codes/{token}', [PaymentQrCodeController::class, 'show'])->name('qr-codes.show');
Route::get('/qr-codes/{token}/image.svg', [PaymentQrCodeController::class, 'svg'])->name('qr-codes.svg');
Route::get('/qr-codes/{token}/image.png', [PaymentQrCodeController::class, 'png'])->name('qr-codes.png');
Route::post('/qr-codes/{token}/start', [PaymentQrCodeController::class, 'start'])->middleware([VerifyConversationApiToken::class, CustomerAuditMiddleware::class, 'throttle:120,1']);

Route::prefix('webhooks/whatsapp')->group(function () {
    Route::get('/', [WhatsappWebhookController::class, 'verify']);
    Route::post('/', [WhatsappWebhookController::class, 'receive'])->middleware([VerifyWhatsappSignature::class, CustomerAuditMiddleware::class]);
});

Route::post('/webhooks/asaas', AsaasWebhookController::class)->middleware(CustomerAuditMiddleware::class);
Route::get('/documents/statements/{filename}', StatementDocumentController::class);
Route::get('/documents/transfer-receipts/{filename}', TransferReceiptDocumentController::class);
Route::get('/images/transfer-receipts/{filename}', TransferReceiptImageController::class);
Route::post('/maintenance/run', MaintenanceController::class)->middleware('throttle:3,1');
