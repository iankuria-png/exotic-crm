<?php

use App\Http\Controllers\CRM\CollectionPaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'crm.session-token', 'crm.active', 'crm.impersonation', 'role:admin,sub_admin,sales'])
    ->prefix('crm/collections')->group(function () {
        Route::get('capabilities', [CollectionPaymentController::class, 'capabilities']);
        Route::get('receipts', [CollectionPaymentController::class, 'index']);
        Route::get('receipts/{receipt}', [CollectionPaymentController::class, 'show'])->whereNumber('receipt');
        Route::get('receipts/{receipt}/review-context', [CollectionPaymentController::class, 'reviewContext'])->whereNumber('receipt');
        Route::get('receipts/{receipt}/candidates', [CollectionPaymentController::class, 'candidates'])->whereNumber('receipt');
        Route::post('receipts/{receipt}/intents', [CollectionPaymentController::class, 'createIntent'])->whereNumber('receipt');
        Route::post('receipts/{receipt}/payer-links', [CollectionPaymentController::class, 'reviewPayer'])->whereNumber('receipt');
        Route::post('receipts/{receipt}/approve', [CollectionPaymentController::class, 'approve'])->whereNumber('receipt');
        Route::post('receipts/{receipt}/confirm', [CollectionPaymentController::class, 'confirm'])->whereNumber('receipt');
        Route::post('receipts/{receipt}/correction-preview', [CollectionPaymentController::class, 'correctionPreview'])->whereNumber('receipt');
        Route::post('corrections/{correction}/execute', [CollectionPaymentController::class, 'executeCorrection']);
        Route::get('arrivals', [CollectionPaymentController::class, 'arrivals']);
        Route::get('device-health', [CollectionPaymentController::class, 'deviceHealth']);
        Route::get('account-policies', [CollectionPaymentController::class, 'policies']);
        Route::put('accounts/{account}/policy', [CollectionPaymentController::class, 'updatePolicy']);
        Route::post('arrivals/read', [CollectionPaymentController::class, 'readArrivals']);
        Route::get('accounts/{account}/settlement', [CollectionPaymentController::class, 'settlement']);
        Route::post('accounts/{account}/statement', [CollectionPaymentController::class, 'statement']);
        Route::post('accounts/{account}/adjustments', [CollectionPaymentController::class, 'adjustment']);
        Route::post('receipts/{receipt}/aliases', [CollectionPaymentController::class, 'alias'])->whereNumber('receipt');
    });
