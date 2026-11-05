<?php

use App\Http\Controllers\CRM\PaymentReviewController as Review;
use Illuminate\Support\Facades\Route;

Route::prefix('reconciliations')->group(function () {
    Route::get('/', [Review::class, 'index']);
    Route::get('/options', [Review::class, 'options']);
    Route::get('/export', [Review::class, 'export']);
    Route::get('/my-corrections', [Review::class, 'inbox']);
    Route::post('/decisions/preview', [Review::class, 'preview']);
    Route::post('/decisions', [Review::class, 'commit']);
    Route::put('/grants/{user}', [Review::class, 'grants']);
    Route::post('/corrections/{correction}/resubmit', [Review::class, 'resubmit']);
    Route::post('/corrections/{correction}/reassign', [Review::class, 'reassign']);
    Route::get('/{type}/{id}', [Review::class, 'show'])->where('type', 'payment|bundle')->whereNumber('id');
    Route::post('/{type}/{id}/attachments', [Review::class, 'attachment'])->where('type', 'payment|bundle')->whereNumber('id');
    Route::get('/{type}/{id}/evidence/{kind}/{evidence}', [Review::class, 'evidence'])->where('type', 'payment|bundle')->where('kind', 'submission|attachment')->whereNumber('id')->whereNumber('evidence');
});

use App\Http\Controllers\CRM\FreeTrialApprovalController as Trial;

Route::get('/free-trials', [Trial::class, 'index']);
Route::get('/free-trials/catalogue/{platform}', [Trial::class, 'catalogue']);
Route::post('/free-trials/requests', [Trial::class, 'store']);
Route::get('/free-trials/requests/{trial}', [Trial::class, 'show']);
Route::post('/free-trials/requests/{trial}/decision', [Trial::class, 'decision']);
Route::post('/free-trials/requests/{trial}/recover', [Trial::class, 'recover']);
Route::post('/free-trials/requests/{trial}/cancel', [Trial::class, 'cancel']);
Route::get('/markets/{platform}/trial-approval-policy', [Trial::class, 'policy']);
Route::put('/markets/{platform}/trial-approval-policy', [Trial::class, 'savePolicy']);

Route::post('/free-trials/requests/{trial}/resume', [Trial::class, 'resume']);
