<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\PaymentController;


/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

// Payment response routes (these are called by CyberSource)
Route::post('/response', [PaymentController::class, 'paymentResponse'])->name('payment.response');
Route::post('cancel', [PaymentController::class, 'paymentCancel'])->name('payment.cancel');

Route::get('/success/{id}', [PaymentController::class, 'paymentSuccess'])->name('payment.success');
Route::get('/failed/{id}', [PaymentController::class, 'paymentFailed'])->name('payment.failed');
Route::get('/canceled', [PaymentController::class, 'paymentCanceled'])->name('payment.canceled');
Route::get('error', [PaymentController::class, 'paymentError'])->name('payment.error');

Route::get('/api/payment-status/{transaction_uuid}', [PaymentController::class, 'checkPaymentStatus']);

Route::get('/debug-check-email/{email}', function($email) {
    $user = App\Models\User::where('email', $email)->first();
    
    if ($user) {
        return response()->json([
            'exists' => true,
            'user' => $user
        ]);
    }
    
    return response()->json([
        'exists' => false,
        'message' => 'Email not found in database'
    ]);
});

use App\Http\Controllers\GoogleAuthController;

Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirectToGoogle'])->name('google.redirect');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'handleGoogleCallback'])->name('google.callback');
Route::get('/google-auth-success', [GoogleAuthController::class, 'googleAuthSuccess']);

// CRM SPA — serve React for all frontend routes after explicit web endpoints.
Route::get('/{any?}', function () {
    return view('crm');
})->where('any', '^(?!api|response|cancel|success|failed|error|auth|debug).*$');


