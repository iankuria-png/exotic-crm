<?php

use App\Http\Controllers\CRM\CollectionPaymentController;
use App\Http\Controllers\CRM\CollectionSalesController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'crm.session-token', 'crm.active', 'crm.impersonation', 'role:admin,sub_admin,sales'])
    ->prefix('crm/collections')->group(function () {
        Route::get('capabilities', [CollectionPaymentController::class, 'capabilities']);
        Route::get('clients/{client}/sales', [CollectionSalesController::class, 'clientSales'])->whereNumber('client');
        Route::get('receipts', [CollectionPaymentController::class, 'index']);
        Route::get('receipts/{receipt}', [CollectionPaymentController::class, 'show'])->whereNumber('receipt');
        Route::get('receipts/{receipt}/review-context', [CollectionPaymentController::class, 'reviewContext'])->whereNumber('receipt');
        Route::get('receipts/{receipt}/messages', [CollectionPaymentController::class, 'messages'])->whereNumber('receipt');
        Route::get('receipts/{receipt}/sales', [CollectionSalesController::class, 'show'])->whereNumber('receipt');
        Route::get('receipts/{receipt}/sales/subsidiary-trial-targets', [CollectionSalesController::class, 'subsidiaryTargets'])->whereNumber('receipt');
        Route::get('receipts/{receipt}/sales/subsidiary-trial-preview', [CollectionSalesController::class, 'subsidiaryPreview'])->whereNumber('receipt');
        Route::post('receipts/{receipt}/sales/preview', [CollectionSalesController::class, 'preview'])->whereNumber('receipt');
        Route::post('receipts/{receipt}/sales/save', [CollectionSalesController::class, 'save'])->whereNumber('receipt');
        Route::post('receipts/{receipt}/sales/activate', [CollectionSalesController::class, 'activate'])->whereNumber('receipt');
        Route::post('sales/{session}/items/{item}/reconcile', [CollectionSalesController::class, 'reconcile'])->whereUuid('session')->whereUuid('item');
        Route::post('sales/{session}/items/{item}/retry', [CollectionSalesController::class, 'retry'])->whereUuid('session')->whereUuid('item');
        Route::post('sales/items/{item}/correction-preview', [\App\Http\Controllers\CRM\CollectionSalesCorrectionController::class, 'preview'])->whereUuid('item');
        Route::post('sales/corrections/{correction}/execute', [\App\Http\Controllers\CRM\CollectionSalesCorrectionController::class, 'execute'])->whereUuid('correction');
        Route::post('receipts/{receipt}/standing-agreements', [\App\Http\Controllers\CRM\CollectionSalesCorrectionController::class, 'standingAgreement'])->whereNumber('receipt');
        Route::get('receipts/{receipt}/candidates', [CollectionPaymentController::class, 'candidates'])->whereNumber('receipt');
        Route::post('receipts/{receipt}/intents', [CollectionPaymentController::class, 'createIntent'])->whereNumber('receipt');
        Route::post('receipts/{receipt}/payer-links', [CollectionPaymentController::class, 'reviewPayer'])->whereNumber('receipt');
        Route::post('receipts/{receipt}/approve', [CollectionPaymentController::class, 'approve'])->whereNumber('receipt');
        Route::post('receipts/{receipt}/confirm', [CollectionPaymentController::class, 'confirm'])->whereNumber('receipt');
        Route::post('receipts/{receipt}/reversal-review', [CollectionPaymentController::class, 'reviewReversal'])->whereNumber('receipt');
        Route::post('receipts/{receipt}/correction-preview', [CollectionPaymentController::class, 'correctionPreview'])->whereNumber('receipt');
        Route::post('corrections/{correction}/execute', [CollectionPaymentController::class, 'executeCorrection']);
        Route::post('receipts/{receipt}/subscription-link-preview', [\App\Http\Controllers\CRM\CollectionSubscriptionLinkController::class, 'preview'])->whereNumber('receipt');
        Route::post('subscription-links/{link}/execute', [\App\Http\Controllers\CRM\CollectionSubscriptionLinkController::class, 'execute'])->whereUuid('link');
        Route::get('arrivals', [CollectionPaymentController::class, 'arrivals']);
        Route::get('device-health', [CollectionPaymentController::class, 'deviceHealth']);
        Route::get('accounts/{account}/package-rule-evidence', [CollectionPaymentController::class, 'packageRuleEvidence']);
        Route::get('receipts/{receipt}/subsidiary', [\App\Http\Controllers\CRM\CollectionSubsidiaryController::class, 'show'])->whereNumber('receipt');
        Route::get('receipts/{receipt}/subsidiary/subsidiary-trial-targets', [\App\Http\Controllers\CRM\CollectionSubsidiaryController::class, 'targets'])->whereNumber('receipt');
        Route::get('receipts/{receipt}/subsidiary/subsidiary-trial-preview', [\App\Http\Controllers\CRM\CollectionSubsidiaryController::class, 'preview'])->whereNumber('receipt');
        Route::post('receipts/{receipt}/subsidiary/finish', [\App\Http\Controllers\CRM\CollectionSubsidiaryController::class, 'finish'])->whereNumber('receipt');
        Route::get('account-policies', [CollectionPaymentController::class, 'policies']);
        Route::put('accounts/{account}/policy', [CollectionPaymentController::class, 'updatePolicy']);
        Route::post('accounts/{account}/signoffs', [CollectionPaymentController::class, 'recordSignoff']);
        Route::post('arrivals/read', [CollectionPaymentController::class, 'readArrivals']);
        Route::get('accounts/{account}/settlement', [CollectionPaymentController::class, 'settlement']);
        Route::post('accounts/{account}/statement', [CollectionPaymentController::class, 'statement']);
        Route::post('accounts/{account}/adjustments', [CollectionPaymentController::class, 'adjustment']);
        Route::post('receipts/{receipt}/aliases', [CollectionPaymentController::class, 'alias'])->whereNumber('receipt');
    });
