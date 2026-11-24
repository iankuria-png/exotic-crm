<?php

use App\Http\Controllers\CRM\EwmsKpiPaymentEvidenceController;
use App\Http\Middleware\EnsureEwmsKpiIntegrationToken;
use Illuminate\Support\Facades\Route;

Route::prefix('internal/ewms/kpi/payment-evidence')
    ->middleware(['auth:sanctum', EnsureEwmsKpiIntegrationToken::class])
    ->group(function () {
        Route::post('/', [EwmsKpiPaymentEvidenceController::class, 'records']);
        Route::post('/summary', [EwmsKpiPaymentEvidenceController::class, 'summary']);
    });
