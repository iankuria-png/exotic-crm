<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\PlatformController;
use App\Http\Controllers\API\DashboardController;
use App\Http\Controllers\API\ProductController;
use App\Http\Controllers\API\PaymentController;
use App\Http\Controllers\API\SmsLogController;
use App\Http\Controllers\API\ActivityLogController;
use App\Http\Controllers\AfricanCountryController;
use App\Http\Controllers\CRM\AuthController as CrmAuthController;
use App\Http\Controllers\CRM\DashboardController as CrmDashboardController;
use App\Http\Controllers\CRM\ClientController;
use App\Http\Controllers\CRM\LeadController;
use App\Http\Controllers\CRM\PaymentQueueController;
use App\Http\Controllers\CRM\DealController;
use App\Http\Controllers\CRM\SettingsController;
use App\Http\Controllers\CRM\ConversationController;
use App\Http\Controllers\CRM\RenewalController;
use App\Http\Controllers\CRM\ReportController;

Route::get('/ping', function () {
    return response()->json(['message' => 'API is working!']);
});

// ==================== CRM ROUTES ====================

// CRM Auth (public)
Route::post('/crm/login', [CrmAuthController::class, 'login']);

// CRM Protected Routes (Sanctum token required)
Route::middleware('auth:sanctum')->prefix('crm')->group(function () {
    // Auth
    Route::get('/me', [CrmAuthController::class, 'me']);
    Route::post('/logout', [CrmAuthController::class, 'logout']);

    // Dashboard
    Route::get('/dashboard', [CrmDashboardController::class, 'summary']);
    Route::get('/products', [CrmDashboardController::class, 'products']);

    // Clients
    Route::get('/clients', [ClientController::class, 'index']);
    Route::post('/clients', [ClientController::class, 'store']);
    Route::post('/clients/upload-csv', [ClientController::class, 'uploadCsv']);
    Route::get('/clients/{client}', [ClientController::class, 'show']);
    Route::patch('/clients/{client}', [ClientController::class, 'update']);
    Route::get('/clients/{client}/timeline', [ClientController::class, 'timeline']);
    Route::post('/clients/{client}/notes', [ClientController::class, 'storeNote']);
    Route::post('/clients/{client}/sync', [ClientController::class, 'syncOne']);

    // Deals
    Route::get('/deals', [DealController::class, 'index']);
    Route::post('/deals', [DealController::class, 'store']);
    Route::get('/deals/{deal}', [DealController::class, 'show']);
    Route::patch('/deals/{deal}', [DealController::class, 'update']);
    Route::delete('/deals/{deal}', [DealController::class, 'destroy']);
    Route::post('/deals/{deal}/activate', [DealController::class, 'activate']);
    Route::post('/deals/{deal}/deactivate', [DealController::class, 'deactivate']);
    Route::post('/deals/{deal}/extend', [DealController::class, 'extend']);

    // Leads
    Route::get('/leads', [LeadController::class, 'index']);
    Route::post('/leads', [LeadController::class, 'store']);
    Route::post('/leads/scrape-entry', [LeadController::class, 'scrapeEntry']);
    Route::post('/leads/upload-csv', [LeadController::class, 'uploadCsv']);
    Route::get('/leads/pipeline', [LeadController::class, 'pipeline']);
    Route::post('/leads/import', [LeadController::class, 'import']);
    Route::get('/leads/{lead}', [LeadController::class, 'show']);
    Route::patch('/leads/{lead}/archive', [LeadController::class, 'archive']);
    Route::delete('/leads/{lead}', [LeadController::class, 'destroy']);
    Route::patch('/leads/{lead}/status', [LeadController::class, 'updateStatus']);
    Route::patch('/leads/{lead}/assign', [LeadController::class, 'assign']);

    // Conversations
    Route::post('/conversations/clients/{client}/send', [ConversationController::class, 'send']);

    // Renewals
    Route::get('/renewals', [RenewalController::class, 'overview']);
    Route::get('/renewals/runs', [RenewalController::class, 'runs']);
    Route::post('/renewals/run', [RenewalController::class, 'run']);
    Route::post('/renewals/remind', [RenewalController::class, 'remind']);
    Route::post('/renewals/pause', [RenewalController::class, 'pause']);
    Route::post('/renewals/resume', [RenewalController::class, 'resume']);

    // Reports
    Route::get('/reports/summary', [ReportController::class, 'summary']);

    // Payments
    Route::get('/payments', [PaymentQueueController::class, 'index']);
    Route::get('/payments/{payment}/candidates', [PaymentQueueController::class, 'candidates']);
    Route::post('/payments/{payment}/auto-match', [PaymentQueueController::class, 'autoMatch']);
    Route::post('/payments/{payment}/confirm-match', [PaymentQueueController::class, 'confirmMatch']);
    Route::post('/payments/batch-match', [PaymentQueueController::class, 'batchMatch']);

    // Settings
    Route::get('/settings/integrations', [SettingsController::class, 'integrations']);
    Route::post('/settings/integrations/platforms', [SettingsController::class, 'storeIntegrationPlatform'])->middleware('role:admin');
    Route::patch('/settings/integrations/platforms/{platform}', [SettingsController::class, 'updateIntegrationPlatform'])->middleware('role:admin,sub_admin');
    Route::post('/settings/integrations/platforms/{platform}/test-connection', [SettingsController::class, 'testPlatformConnection'])->middleware('role:admin,sub_admin');
    Route::post('/settings/integrations/platforms/{platform}/sync', [SettingsController::class, 'runPlatformSync'])->middleware('role:admin,sub_admin');
    Route::get('/settings/owners', [SettingsController::class, 'owners']);
    Route::get('/settings/templates', [SettingsController::class, 'templates']);
    Route::post('/settings/templates', [SettingsController::class, 'storeTemplate'])->middleware('role:admin,sub_admin');
    Route::patch('/settings/templates/{template}', [SettingsController::class, 'updateTemplate'])->middleware('role:admin,sub_admin');
    Route::delete('/settings/templates/{template}', [SettingsController::class, 'destroyTemplate'])->middleware('role:admin,sub_admin');
    Route::get('/settings/webhook-logs', [SettingsController::class, 'webhookLogs']);
    Route::get('/settings/roles', [SettingsController::class, 'roles'])->middleware('role:admin');
    Route::patch('/settings/roles/{user}', [SettingsController::class, 'updateRole'])->middleware('role:admin');
});

// ==================== ALL ROUTES ARE PUBLIC (No authentication required) ====================

// Authentication routes (public)
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout']); // Even logout is public now
Route::get('/auth/google/redirect', [AuthController::class, 'redirectToGoogle']);
Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback']);


// User management routes (public)
Route::put('/users/{id}', [AuthController::class, 'updateUser']);
Route::get('/users', [AuthController::class, 'getUsers']);

// Platform routes (public)
Route::get('/platforms', [PlatformController::class, 'platform']);
Route::post('/platforms', [PlatformController::class, 'store']);
Route::put('/platforms/{id}', [PlatformController::class, 'update']);
Route::delete('/platforms/{id}', [PlatformController::class, 'destroy']);

// Sales user specific routes (public)
Route::get('/my-platforms', [AuthController::class, 'getMyPlatforms']);

// Dashboard routes (public)
Route::post('/dashboard-summary', [DashboardController::class, 'summary']);
Route::post('/summary', [DashboardController::class, 'summary']);
Route::post('/escort-posts', [DashboardController::class, 'escortPosts']);
Route::get('/recent-users', [DashboardController::class, 'recentUsers']);

// Product routes (public)
Route::get('/products', [ProductController::class, 'index']);
Route::post('/products', [ProductController::class, 'store']);
Route::put('/products/{id}', [ProductController::class, 'update']);
Route::delete('/products/{id}', [ProductController::class, 'destroy']);

// Payment routes (public)
Route::post('/initiate-stk-payment', [PaymentController::class, 'initiate']);
Route::post('/initiate-payment', [PaymentController::class, 'initiatePayment']);
Route::post('/initiate-card-payment', [PaymentController::class, 'initiateCardPayment']);
Route::get('/payments', [PaymentController::class, 'list']);
Route::get('/payments/{user_id}', [PaymentController::class, 'getPayments'])->name('payment.history');

// Payment callbacks/webhooks (public)
Route::post('/payment-callback', [PaymentController::class, 'callback']);
Route::post('/callback', [PaymentController::class, 'paybillCallback']);
Route::post('/cybersource/notifications', [PaymentController::class, 'handleNotification']);
Route::post('/payment/notification', [PaymentController::class, 'handleNotification'])->name('payment.notification');

// SMS logs (public)
Route::get('/sms-logs', [SmsLogController::class, 'messages']);

// Profile activation/deactivation (public)
Route::post('/activate-profile', [PaymentController::class, 'manualActivate']);
Route::post('/deactivate-profile', [PaymentController::class, 'manualDeactivate']);

// Activity logs (public)
Route::get('/activity-logs', [ActivityLogController::class, 'activityLogs']);

// Profile lists (public)
Route::get('/activated-profiles', [PaymentController::class, 'listActivatedProfiles']);
Route::get('/deactivated-profiles', [PaymentController::class, 'listDeactivatedProfiles']);

// Manual operations (public)
Route::post('/manual-stk-push', [PaymentController::class, 'manualStkPush']);
Route::post('/payment/update', [PaymentController::class, 'updatePaymentStatus']);
Route::post('/manual-update', [PaymentController::class, 'manuallyUpdatePaymentStatus']);


// African countries routes (public)
Route::get('/african-countries', [AfricanCountryController::class, 'index']);
Route::get('/african-countries/currency/{currencyCode}', [AfricanCountryController::class, 'getByCurrencyCode']);
Route::get('/african-countries/search', [AfricanCountryController::class, 'search']);

// Debug routes (public)
Route::post('/check-payment-status', [PaymentController::class, 'checkStatus']);
Route::get('/debug-kopokopo', [PaymentController::class, 'debugKopokopo']);
Route::post('/clear-pending-payments', [PaymentController::class, 'clearPendingPayments']);
Route::any('/test-webhook', [PaymentController::class, 'testWebhook']);
Route::post('/subscribe-webhooks', [PaymentController::class, 'subscribeToWebhooks']);
Route::get('/webhook-info', function() {
    return response()->json([
        'webhook_url' => url('/api/payment-callback'),
        'test_webhook_url' => url('/api/test-webhook'),
        'server_info' => [
            'https' => request()->isSecure(),
            'host' => request()->getHost(),
            'full_url' => request()->fullUrl(),
            'server_ip' => $_SERVER['SERVER_ADDR'] ?? 'unknown',
            'php_version' => PHP_VERSION,
            'timestamp' => now()->toDateTimeString()
        ],
        'kopokopo_config' => [
            'base_url' => config('kopokopo.base_url'),
            'till_number' => config('kopokopo.till_number'),
            'is_production' => config('kopokopo.base_url') === 'https://api.kopokopo.com'
        ]
    ]);
});

Route::post('/simulate-webhook', function(Request $request) {
    if (!config('app.debug')) {
        return response()->json(['error' => 'Debug mode required'], 403);
    }
    
    $paymentId = $request->input('payment_id');
    $eventType = $request->input('event_type', 'buygoods_transaction_received');
    
    if (!$paymentId) {
        return response()->json(['error' => 'payment_id required'], 400);
    }
    
    $payment = \App\Models\Payment::find($paymentId);
    if (!$payment) {
        return response()->json(['error' => 'Payment not found'], 404);
    }
    
    // Simulate webhook payload
    $webhookPayload = [
        'event_type' => $eventType,
        'resource' => [
            'id' => 'test_' . time(),
            'reference' => 'TEST_REF_' . $paymentId,
            'origination_time' => now()->toISOString(),
            'sender_phone_number' => $payment->phone,
            'amount' => $payment->amount,
            'currency' => 'KES',
            'metadata' => [
                'payment_id' => $paymentId,
                'platform_id' => $payment->platform_id,
                'product_id' => $payment->product_id,
                'user_id' => $payment->user_id,
                'duration' => $payment->duration
            ]
        ],
        'timestamp' => now()->toISOString()
    ];
    
    // Call the webhook handler directly
    $webhookRequest = Request::create('/api/payment-callback', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_KOPOKOPO_SIGNATURE' => 'test_signature'
    ], json_encode($webhookPayload));
    
    $controller = new \App\Http\Controllers\API\PaymentController();
    $response = $controller->handleCallback($webhookRequest);
    
    return response()->json([
        'status' => 'success',
        'message' => 'Webhook simulated',
        'payload' => $webhookPayload,
        'response' => $response->getData()
    ]);
});
