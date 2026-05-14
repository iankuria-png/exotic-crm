<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\PlatformController;
use App\Http\Controllers\API\DashboardController;
use App\Http\Controllers\API\ProductController;
use App\Http\Controllers\API\PaymentController;
use App\Http\Controllers\API\WalletController;
use App\Http\Controllers\API\BillingController;
use App\Http\Controllers\API\SmsLogController;
use App\Http\Controllers\API\ActivityLogController;
use App\Http\Controllers\AfricanCountryController;
use App\Http\Controllers\CRM\AuthController as CrmAuthController;
use App\Http\Controllers\CRM\AuthSettingsController;
use App\Http\Controllers\CRM\DashboardController as CrmDashboardController;
use App\Http\Controllers\CRM\ClientController;
use App\Http\Controllers\CRM\ClientWalletController;
use App\Http\Controllers\CRM\LeadController;
use App\Http\Controllers\CRM\PaymentQueueController;
use App\Http\Controllers\CRM\PaymentExportController;
use App\Http\Controllers\CRM\PaymentLinkProxyController;
use App\Http\Controllers\CRM\DealController;
use App\Http\Controllers\CRM\ErrorLogController;
use App\Http\Controllers\CRM\ManualPaymentBundleController;
use App\Http\Controllers\CRM\SettingsController;
use App\Http\Controllers\CRM\ConversationController;
use App\Http\Controllers\CRM\ImageProxyController;
use App\Http\Controllers\CRM\PushCampaignController;
use App\Http\Controllers\CRM\RenewalController;
use App\Http\Controllers\CRM\ReportController;
use App\Http\Controllers\CRM\ScorecardExportController;
use App\Http\Controllers\CRM\SetupController;
use App\Http\Controllers\CRM\SupportBoardController;
use App\Http\Controllers\CRM\TeamController;
use App\Http\Controllers\CRM\SystemHealthUpdateController;
use App\Http\Controllers\CRM\AgentTodoController;
use App\Http\Controllers\CRM\Faq\CategoryController as FaqCategoryController;
use App\Http\Controllers\CRM\Faq\ArticleController as FaqArticleController;
use App\Http\Controllers\CRM\Faq\ContextController as FaqContextController;
use App\Http\Controllers\CRM\Faq\MediaController as FaqMediaController;
use App\Http\Controllers\CRM\Faq\WalkthroughController as FaqWalkthroughController;
use App\Http\Controllers\CRM\Faq\FeedbackController as FaqFeedbackController;
use App\Http\Controllers\CRM\Faq\FeedbackVoteController as FaqFeedbackVoteController;
use App\Http\Controllers\CRM\Faq\FeedbackCommentController as FaqFeedbackCommentController;
use App\Http\Controllers\CRM\University\AnalyticsController as UniversityAnalyticsController;
use App\Http\Controllers\CRM\University\CertSettingsController as UniversityCertSettingsController;
use App\Http\Controllers\CRM\University\CertificateController as UniversityCertificateController;
use App\Http\Controllers\CRM\University\CertificationController as UniversityCertificationController;
use App\Http\Controllers\CRM\University\CourseController as UniversityCourseController;
use App\Http\Controllers\CRM\University\LessonController as UniversityLessonController;
use App\Http\Controllers\CRM\University\ModuleController as UniversityModuleController;
use App\Http\Controllers\CRM\University\ProgressController as UniversityProgressController;

Route::get('/ping', function () {
    return response()->json(['message' => 'API is working!']);
});

// ==================== CRM ROUTES ====================

// CRM Auth (public)
Route::post('/crm/login', [CrmAuthController::class, 'login']);
Route::get('/crm/auth/config', [AuthSettingsController::class, 'publicConfig']);
Route::get('/billing/health', [BillingController::class, 'health']);
Route::get('/payments/link/{token}', [PaymentLinkProxyController::class, 'handle']);
Route::get('/crm/university/certificates/{code}/verify', [UniversityCertificateController::class, 'verify']);

// Image proxy — public but rate-limited; domain allowlist enforced server-side
Route::get('/crm/image-proxy', [ImageProxyController::class, 'show'])->middleware('throttle:120,1');

Route::prefix('crm/setup')->middleware('throttle:5,1')->group(function () {
    Route::get('/status', [SetupController::class, 'status']);
    Route::post('/check-env', [SetupController::class, 'checkEnv']);
    Route::post('/check-database', [SetupController::class, 'checkDatabase']);
    Route::post('/run-migrations', [SetupController::class, 'runMigrations']);
    Route::post('/create-admin', [SetupController::class, 'createAdmin']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/check-platform', [SetupController::class, 'checkPlatform']);
        Route::post('/run-sync', [SetupController::class, 'runSync']);
        Route::post('/run-diagnostics', [SetupController::class, 'runDiagnostics']);
        Route::post('/complete', [SetupController::class, 'complete']);
    });
});

// CRM Protected Routes (Sanctum token required)
Route::middleware(['auth:sanctum', 'crm.active', 'crm.impersonation'])->prefix('crm')->group(function () {
    // Auth
    Route::get('/me', [CrmAuthController::class, 'me']);
    Route::post('/logout', [CrmAuthController::class, 'logout']);
    Route::post('/heartbeat', [TeamController::class, 'heartbeat']);
    Route::get('/team/me', [TeamController::class, 'myStats']);

    // Dashboard
    Route::get('/dashboard', [CrmDashboardController::class, 'summary']);
    Route::get('/dashboard/country-revenue', [CrmDashboardController::class, 'countryRevenue']);
    Route::get('/dashboard/country-performance/{platform}', [CrmDashboardController::class, 'countryPerformance']);
    Route::get('/dashboard/my-markets', [CrmDashboardController::class, 'myMarkets'])->middleware('role:admin,sub_admin,sales,marketing');
    Route::get('/products', [CrmDashboardController::class, 'products']);
    Route::post('/markets/{platform}/sync', [SettingsController::class, 'runSalesMarketSync'])->middleware('role:admin,sub_admin,sales');
    Route::get('/markets/{platform}/sync/latest', [SettingsController::class, 'latestPlatformClientSync'])->middleware('role:admin,sub_admin,sales');

    Route::middleware('role:admin,sub_admin,sales')->prefix('todos')->group(function () {
        Route::get('/', [AgentTodoController::class, 'index']);
        Route::post('/', [AgentTodoController::class, 'store']);
        Route::patch('/{todo}', [AgentTodoController::class, 'update']);
        Route::delete('/{todo}', [AgentTodoController::class, 'destroy']);
    });

    Route::middleware('role:admin,sub_admin')->prefix('team')->group(function () {
        Route::get('/presence', [TeamController::class, 'presence']);
        Route::get('/leaderboard', [TeamController::class, 'leaderboard']);
        Route::get('/goals', [TeamController::class, 'goals']);
        Route::post('/goals', [TeamController::class, 'setGoal']);
        Route::post('/goals/overrides', [TeamController::class, 'setGoalOverride']);
        Route::delete('/goals/overrides/{goalOverride}', [TeamController::class, 'deleteGoalOverride']);
        Route::delete('/goals/{goal}', [TeamController::class, 'deleteGoal']);
        Route::get('/{user}/stats', [TeamController::class, 'agentStats']);
        Route::get('/{user}/activity', [TeamController::class, 'activityFeed']);
    });

    // Push Campaigns (static routes before dynamic route-model binding segments)
    Route::middleware('role:marketing,admin,sub_admin')->prefix('push-campaigns')->group(function () {
        Route::get('/', [PushCampaignController::class, 'index']);
        Route::post('/', [PushCampaignController::class, 'store']);

        // Static routes
        Route::post('/upload', [PushCampaignController::class, 'upload']);
        Route::post('/upload/paste', [PushCampaignController::class, 'uploadPaste']);
        Route::get('/upload/limits', [PushCampaignController::class, 'uploadLimits']);
        Route::get('/upload/queue', [PushCampaignController::class, 'uploadQueue']);
        Route::get('/upload/{batchId}/status', [PushCampaignController::class, 'uploadStatus']);
        Route::post('/upload/{batchId}/create-from-dry-run', [PushCampaignController::class, 'createCampaignsFromDryRun']);
        Route::post('/upload/{batchId}/confirm', [PushCampaignController::class, 'confirmQueuedBatch']);
        Route::post('/upload/{batchId}/process-now', [PushCampaignController::class, 'processQueuedUploadNow']);
        Route::delete('/upload/{batchId}', [PushCampaignController::class, 'cancelQueuedUpload']);
        Route::get('/crm-profiles', [PushCampaignController::class, 'crmProfiles']);
        Route::post('/from-crm', [PushCampaignController::class, 'storeFromCrm']);
        Route::get('/dashboard', [PushCampaignController::class, 'dashboard']);
        Route::get('/subscribers', [PushCampaignController::class, 'subscribers']);
        Route::post('/subscribers/sync', [PushCampaignController::class, 'syncSubscribers']);
        Route::get('/presets', [PushCampaignController::class, 'listPresets']);
        Route::post('/presets', [PushCampaignController::class, 'storePreset']);
        Route::post('/presets/detect', [PushCampaignController::class, 'detectPreset']);
        Route::post('/presets/{preset}/test', [PushCampaignController::class, 'testPreset']);
        Route::patch('/presets/{preset}', [PushCampaignController::class, 'updatePreset'])->middleware('role:admin,sub_admin');

        // Dynamic routes
        Route::patch('/{pushCampaign}/items/{pushCampaignItem}', [PushCampaignController::class, 'updateItem']);
        Route::get('/{pushCampaign}/items/{pushCampaignItem}/match-candidates', [PushCampaignController::class, 'matchCandidates']);
        Route::post('/{pushCampaign}/items/{pushCampaignItem}/match-crm', [PushCampaignController::class, 'matchCrm']);
        Route::post('/{pushCampaign}/items/{pushCampaignItem}/hydrate-profile', [PushCampaignController::class, 'hydrateItemProfile']);
        Route::get('/{pushCampaign}/items/{pushCampaignItem}/media', [PushCampaignController::class, 'itemMedia']);
        Route::post('/{pushCampaign}/items/{pushCampaignItem}/media/select', [PushCampaignController::class, 'selectItemMedia']);
        Route::post('/{pushCampaign}/items/{pushCampaignItem}/media/upload', [PushCampaignController::class, 'uploadItemMedia']);
        Route::delete('/{pushCampaign}/items/{pushCampaignItem}', [PushCampaignController::class, 'removeItem']);
        Route::get('/{pushCampaign}/dispatch-readiness', [PushCampaignController::class, 'dispatchReadiness']);
        Route::get('/{pushCampaign}', [PushCampaignController::class, 'show']);
        Route::post('/{pushCampaign}/execute', [PushCampaignController::class, 'execute']);
        Route::post('/{pushCampaign}/schedule', [PushCampaignController::class, 'schedule']);
        Route::get('/{pushCampaign}/analytics', [PushCampaignController::class, 'analytics']);
        Route::post('/{pushCampaign}/reschedule', [PushCampaignController::class, 'reschedule']);
        Route::delete('/{pushCampaign}', [PushCampaignController::class, 'destroy']);
    });

    // Clients (marketing role has read-only access)
    Route::get('/clients', [ClientController::class, 'index'])->middleware('role:admin,sub_admin,sales,marketing');
    Route::get('/clients/cities', [ClientController::class, 'cities'])->middleware('role:admin,sub_admin,sales,marketing');
    Route::post('/clients', [ClientController::class, 'store'])->middleware('role:admin,sub_admin,sales');
    Route::post('/clients/upload-csv', [ClientController::class, 'uploadCsv'])->middleware('role:admin,sub_admin,sales');
    Route::post('/clients/bulk-delete/preview', [ClientController::class, 'bulkDeletePreview'])->middleware('role:admin,sub_admin');
    Route::post('/clients/bulk-delete', [ClientController::class, 'bulkDelete'])->middleware('role:admin,sub_admin');
    Route::post('/clients/bulk-refresh-display-images', [ClientController::class, 'bulkRefreshDisplayImages'])->middleware('role:admin,sub_admin,sales');
    Route::get('/clients/{client}', [ClientController::class, 'show'])->middleware('role:admin,sub_admin,sales,marketing');
    Route::patch('/clients/{client}', [ClientController::class, 'update'])->middleware('role:admin,sub_admin,sales');
    Route::post('/clients/{client}/delete-preview', [ClientController::class, 'deletePreview'])->middleware('role:admin,sub_admin');
    Route::delete('/clients/{client}', [ClientController::class, 'destroy'])->middleware('role:admin,sub_admin');
    Route::get('/clients/{client}/timeline', [ClientController::class, 'timeline'])->middleware('role:admin,sub_admin,sales,marketing');
    Route::post('/clients/{client}/notes', [ClientController::class, 'storeNote'])->middleware('role:admin,sub_admin,sales');
    Route::post('/clients/{client}/sync', [ClientController::class, 'syncOne'])->middleware('role:admin,sub_admin,sales');
    Route::post('/clients/{client}/deactivate-subscription', [ClientController::class, 'deactivateSubscription'])->middleware('role:admin,sub_admin,sales');
    Route::post('/clients/{client}/verified-status', [ClientController::class, 'updateVerifiedStatus'])->middleware('role:admin,sub_admin,sales');
    Route::post('/clients/{client}/new-badge', [ClientController::class, 'updateNewBadge'])->middleware('role:admin,sub_admin,sales');
    Route::get('/clients/{client}/tours', [ClientController::class, 'tours'])->middleware('role:admin,sub_admin,sales,marketing');
    Route::post('/clients/{client}/tours', [ClientController::class, 'addTour'])->middleware('role:admin,sub_admin,sales');
    Route::delete('/clients/{client}/tours/{tourId}', [ClientController::class, 'deleteTour'])->middleware('role:admin,sub_admin,sales');
    Route::get('/clients/{client}/completeness', [ClientController::class, 'profileCompleteness'])->middleware('role:admin,sub_admin,sales,marketing');
    Route::get('/clients/{client}/retention-insight', [ClientController::class, 'retentionInsight'])->middleware('role:admin,sub_admin,sales,marketing');
    Route::get('/clients/{client}/retention-history', [ClientController::class, 'retentionHistory'])->middleware('role:admin,sub_admin,sales,marketing');
    Route::get('/clients/{client}/wp-profile', [ClientController::class, 'wpProfile'])->middleware('role:admin,sub_admin,sales,marketing');
    Route::get('/clients/{client}/analytics', [ClientController::class, 'profileAnalytics'])->middleware('role:admin,sub_admin,sales,marketing');
    Route::patch('/clients/{client}/wp-profile', [ClientController::class, 'updateWpProfile'])->middleware('role:admin,sub_admin,sales');
    Route::post('/clients/{client}/repair-wp-link', [ClientController::class, 'repairWpLink'])->middleware('role:admin,sub_admin,sales');
    Route::get('/clients/{client}/media', [ClientController::class, 'media'])->middleware('role:admin,sub_admin,sales,marketing');
    Route::post('/clients/{client}/media', [ClientController::class, 'uploadMedia'])->middleware('role:admin,sub_admin,sales');
    Route::delete('/clients/{client}/media/{attachmentId}', [ClientController::class, 'deleteMedia'])->middleware('role:admin,sub_admin,sales');
    Route::patch('/clients/{client}/media/{attachmentId}/set-main', [ClientController::class, 'setMainMedia'])->middleware('role:admin,sub_admin,sales');
    Route::get('/clients/{client}/health', [ClientController::class, 'health'])->middleware('role:admin,sub_admin,sales,marketing');
    Route::get('/clients/{client}/access-context', [ClientController::class, 'credentialAccessContext'])->middleware('role:admin,sub_admin,sales,marketing');
    Route::get('/clients/{client}/wallet', [ClientWalletController::class, 'show'])->middleware('role:admin,sub_admin,sales,marketing');
    Route::get('/clients/{client}/wallet/transactions', [ClientWalletController::class, 'transactions'])->middleware('role:admin,sub_admin,sales,marketing');
    Route::post('/clients/{client}/wallet/topup', [ClientWalletController::class, 'topup'])->middleware('role:admin,sub_admin,sales');
    Route::post('/clients/{client}/wallet/adjustment', [ClientWalletController::class, 'adjustment'])->middleware('role:admin,sub_admin,sales');
    Route::post('/clients/{client}/health/resolve', [ClientController::class, 'resolveHealth'])->middleware('role:admin,sub_admin,sales');
    Route::post('/clients/{client}/credentials/reset', [ClientController::class, 'resetCredentials'])->middleware('role:admin,sub_admin,sales');
    Route::post('/clients/{client}/login-as-client', [ClientController::class, 'loginAsClient'])->middleware('role:admin,sub_admin,sales');
    Route::get('/clients/{client}/credentials/dispatches', [ClientController::class, 'credentialDispatches'])->middleware('role:admin,sub_admin,sales,marketing');
    Route::post('/clients/{client}/credentials/dispatch', [ClientController::class, 'sendCredentials'])->middleware('role:admin,sub_admin,sales');
    Route::post('/clients/{client}/credentials/dispatches/{dispatch}/retry', [ClientController::class, 'retryCredentialDispatch'])->middleware('role:admin,sub_admin,sales');
    Route::get('/clients/{client}/support-board/status', [SupportBoardController::class, 'status'])->middleware('role:admin,sub_admin,sales');
    Route::get('/clients/{client}/support-board/profile', [SupportBoardController::class, 'profile'])->middleware('role:admin,sub_admin,sales');
    Route::post('/clients/{client}/support-board/profile-sync/preview', [SupportBoardController::class, 'previewProfileSync'])->middleware('role:admin,sub_admin,sales');
    Route::post('/clients/{client}/support-board/profile-sync/apply', [SupportBoardController::class, 'applyProfileSync'])->middleware('role:admin,sub_admin,sales');
    Route::get('/clients/{client}/support-board/conversations', [SupportBoardController::class, 'conversations'])->middleware('role:admin,sub_admin,sales');
    Route::get('/clients/{client}/support-board/conversations/{conversationId}', [SupportBoardController::class, 'conversation'])->middleware('role:admin,sub_admin,sales');
    Route::post('/clients/{client}/support-board/conversations/{conversationId}/reply', [SupportBoardController::class, 'reply'])->middleware('role:admin,sub_admin,sales');
    Route::get('/reports/profile-engagement', [ReportController::class, 'profileEngagement'])->middleware('role:admin,sub_admin,sales,marketing');

    Route::middleware('role:admin,sub_admin,sales')->group(function () {
        // Deals
        Route::get('/deals', [DealController::class, 'index']);
        Route::post('/deals', [DealController::class, 'store']);
        Route::get('/deals/{deal}', [DealController::class, 'show']);
        Route::patch('/deals/{deal}', [DealController::class, 'update']);
        Route::delete('/deals/{deal}', [DealController::class, 'destroy']);
        Route::post('/deals/{deal}/activate', [DealController::class, 'activate']);
        Route::post('/deals/{deal}/deactivate', [DealController::class, 'deactivate']);
        Route::post('/deals/{deal}/extend', [DealController::class, 'extend']);
        Route::post('/deals/{deal}/renew', [DealController::class, 'renew']);

        // Leads
        Route::get('/leads', [LeadController::class, 'index']);
        Route::post('/leads', [LeadController::class, 'store']);
        Route::post('/leads/scrape-entry', [LeadController::class, 'scrapeEntry']);
        Route::post('/leads/scraper/preview', [LeadController::class, 'scrapePreview']);
        Route::post('/leads/scraper/preview/{previewId}/commit', [LeadController::class, 'commitScrapePreview']);
        Route::delete('/leads/scraper/preview/{previewId}', [LeadController::class, 'dismissScrapePreview']);
        Route::post('/leads/upload-csv', [LeadController::class, 'uploadCsv']);
        Route::get('/leads/pipeline', [LeadController::class, 'pipeline']);
        Route::post('/leads/import', [LeadController::class, 'import']);
        Route::get('/leads/{lead}', [LeadController::class, 'show']);
        Route::patch('/leads/{lead}/archive', [LeadController::class, 'archive']);
        Route::delete('/leads/{lead}', [LeadController::class, 'destroy']);
        Route::patch('/leads/{lead}/status', [LeadController::class, 'updateStatus']);
        Route::patch('/leads/{lead}/assign', [LeadController::class, 'assign']);
        Route::post('/leads/reconcile', [LeadController::class, 'batchReconcile']);
        Route::post('/leads/{lead}/reconcile', [LeadController::class, 'reconcile']);
        Route::post('/leads/{lead}/convert-to-client', [LeadController::class, 'convertToClient']);

        // Conversations
        Route::post('/conversations/clients/{client}/send', [ConversationController::class, 'send']);
        Route::post('/clients/{client}/payment-link', [ClientController::class, 'sendPaymentLink']);

        // Renewals
        Route::get('/renewals', [RenewalController::class, 'overview']);
        Route::get('/renewals/runs', [RenewalController::class, 'runs']);
        Route::post('/renewals/run', [RenewalController::class, 'run']);
        Route::post('/renewals/remind', [RenewalController::class, 'remind']);
        Route::post('/renewals/bulk-remind', [RenewalController::class, 'bulkRemind']);
        Route::post('/renewals/pause', [RenewalController::class, 'pause']);
        Route::post('/renewals/resume', [RenewalController::class, 'resume']);

        // Reports
        Route::get('/reports/summary', [ReportController::class, 'summary']);
        Route::get('/reports/scorecard/preview', [ScorecardExportController::class, 'weeklyScorecard']);
        Route::post('/reports/scorecard/export', [ScorecardExportController::class, 'exportScorecard']);

        // Payments
        Route::get('/payments', [PaymentQueueController::class, 'index']);
        Route::post('/payments/export', [PaymentExportController::class, 'exportPayments']);
        Route::get('/payments/reference-check', [ManualPaymentBundleController::class, 'referenceCheck']);
        Route::post('/payments/import/preview', [PaymentQueueController::class, 'importPreview']);
        Route::post('/payments/import/commit', [PaymentQueueController::class, 'importCommit']);
        Route::get('/payments/import/template', [PaymentQueueController::class, 'importTemplate']);
        Route::get('/payments/import/kpis', [PaymentQueueController::class, 'importKpis']);
        Route::get('/payments/import/candidates', [PaymentQueueController::class, 'importCandidates']);
        Route::post('/payments/import/row-match', [PaymentQueueController::class, 'updateImportRowMatch']);
        Route::get('/payments/{payment}/diagnostics', [PaymentQueueController::class, 'diagnostics']);
        Route::post('/payments/{payment}/check-provider-status', [PaymentQueueController::class, 'checkProviderStatus']);
        Route::post('/payments/{payment}/sandbox-reconcile', [PaymentQueueController::class, 'sandboxReconcile']);
        Route::post('/payments/{payment}/mark-test', [PaymentQueueController::class, 'markTest'])->middleware('role:admin');
        Route::delete('/payments/{payment}/delete-test', [PaymentQueueController::class, 'deleteTest'])->middleware('role:admin');
        Route::get('/payments/{payment}/candidates', [PaymentQueueController::class, 'candidates']);
        Route::post('/payments/{payment}/auto-match', [PaymentQueueController::class, 'autoMatch']);
        Route::post('/payments/{payment}/confirm-match', [PaymentQueueController::class, 'confirmMatch']);
        Route::post('/payments/{payment}/retry-stk', [PaymentQueueController::class, 'retryStk']);
        Route::post('/payments/{payment}/send-payment-link', [PaymentQueueController::class, 'sendPaymentLink']);
        Route::get('/payments/manual-submissions/{submission}/proof', [PaymentQueueController::class, 'manualSubmissionProof']);
        Route::post('/payments/{payment}/manual-approve', [PaymentQueueController::class, 'approveManualSubmission']);
        Route::post('/payments/{payment}/manual-verify', [PaymentQueueController::class, 'verifyManualSubmission']);
        Route::post('/payments/{payment}/manual-reject', [PaymentQueueController::class, 'rejectManualSubmission']);
        Route::post('/payments/{payment}/manual-close', [PaymentQueueController::class, 'manualClose']);
        Route::post('/payments/{payment}/review-state', [PaymentQueueController::class, 'updateReviewState']);
        Route::post('/payments/{payment}/create-subscription', [PaymentQueueController::class, 'createSubscription']);
        Route::post('/payments/batch-match', [PaymentQueueController::class, 'batchMatch']);
        Route::get('/payments/mpesa-review', [PaymentQueueController::class, 'mpesaReview']);
        Route::post('/payments/mpesa-confirm-subscriptions', [PaymentQueueController::class, 'mpesaConfirmSubscriptions']);

        // Shared manual payment bundles
        Route::post('/manual-payment-bundles/preview', [ManualPaymentBundleController::class, 'preview']);
        Route::post('/manual-payment-bundles/commit', [ManualPaymentBundleController::class, 'commit']);
        Route::get('/manual-payment-bundles/{bundle}', [ManualPaymentBundleController::class, 'show']);
        Route::post('/manual-payment-bundles/{bundle}/void', [ManualPaymentBundleController::class, 'void'])->middleware('role:admin');
    });

    Route::prefix('faq')->group(function () {
        Route::get('/categories', [FaqCategoryController::class, 'index']);
        Route::get('/context', [FaqContextController::class, 'index']);
        Route::get('/articles', [FaqArticleController::class, 'index']);
        Route::get('/articles/{article}', [FaqArticleController::class, 'show']);
        Route::get('/walkthroughs', [FaqWalkthroughController::class, 'index']);

        Route::get('/feedback', [FaqFeedbackController::class, 'index']);
        Route::get('/feedback/{feedback}', [FaqFeedbackController::class, 'show']);
        Route::post('/feedback', [FaqFeedbackController::class, 'store'])->middleware('role:admin,sub_admin,sales,marketing');
        Route::post('/feedback/{feedback}/votes/toggle', [FaqFeedbackVoteController::class, 'toggle'])->middleware('role:admin,sub_admin,sales,marketing');
        Route::get('/feedback/{feedback}/comments', [FaqFeedbackCommentController::class, 'index']);
        Route::post('/feedback/{feedback}/comments', [FaqFeedbackCommentController::class, 'store'])->middleware('role:admin,sub_admin,sales,marketing');

        Route::middleware('role:admin,sub_admin')->group(function () {
            Route::post('/categories', [FaqCategoryController::class, 'store']);
            Route::patch('/categories/{category}', [FaqCategoryController::class, 'update']);
            Route::post('/categories/reorder', [FaqCategoryController::class, 'reorder']);
            Route::delete('/categories/{category}', [FaqCategoryController::class, 'destroy']);

            Route::post('/articles', [FaqArticleController::class, 'store']);
            Route::patch('/articles/{article}', [FaqArticleController::class, 'update']);
            Route::patch('/articles/{article}/draft', [FaqArticleController::class, 'saveDraft']);
            Route::post('/articles/{article}/publish', [FaqArticleController::class, 'publish']);
            Route::post('/articles/{article}/duplicate', [FaqArticleController::class, 'duplicate']);
            Route::post('/articles/reorder', [FaqArticleController::class, 'reorder']);
            Route::delete('/articles/{article}', [FaqArticleController::class, 'destroy']);

            Route::post('/articles/{article}/media', [FaqMediaController::class, 'store']);
            Route::delete('/articles/{article}/media/{media}', [FaqMediaController::class, 'destroy']);

            Route::post('/walkthroughs', [FaqWalkthroughController::class, 'store']);
            Route::get('/walkthroughs/{walkthrough}', [FaqWalkthroughController::class, 'show']);
            Route::patch('/walkthroughs/{walkthrough}', [FaqWalkthroughController::class, 'update']);
            Route::delete('/walkthroughs/{walkthrough}', [FaqWalkthroughController::class, 'destroy']);

            Route::patch('/feedback/{feedback}', [FaqFeedbackController::class, 'update']);
            Route::delete('/feedback/{feedback}', [FaqFeedbackController::class, 'destroy']);
            Route::delete('/feedback/{feedback}/comments/{comment}', [FaqFeedbackCommentController::class, 'destroy']);
        });
    });

    Route::prefix('university')->group(function () {
        Route::get('/courses', [UniversityCourseController::class, 'index']);
        Route::get('/courses/{slug}', [UniversityCourseController::class, 'show']);
        Route::post('/lessons/{lesson}/progress', [UniversityProgressController::class, 'store']);

        Route::get('/certifications', [UniversityCertificationController::class, 'index']);
        Route::get('/certifications/{certification}', [UniversityCertificationController::class, 'show']);
        Route::post('/certifications/{certification}/attempts', [UniversityCertificationController::class, 'startAttempt']);
        Route::post('/attempts/{attempt}/submit', [UniversityCertificationController::class, 'submitAttempt']);
        Route::get('/attempts/{attempt}/result', [UniversityCertificationController::class, 'result']);
        Route::get('/certificates/{code}.pdf', [UniversityCertificateController::class, 'download']);

        Route::middleware('role:admin,sub_admin')->group(function () {
            Route::post('/courses', [UniversityCourseController::class, 'store']);
            Route::patch('/courses/{course:id}', [UniversityCourseController::class, 'update']);
            Route::delete('/courses/{course:id}', [UniversityCourseController::class, 'destroy']);

            Route::post('/courses/{course:id}/modules', [UniversityModuleController::class, 'store']);
            Route::patch('/modules/{module}', [UniversityModuleController::class, 'update']);
            Route::delete('/modules/{module}', [UniversityModuleController::class, 'destroy']);

            Route::post('/modules/{module}/lessons', [UniversityLessonController::class, 'store']);
            Route::patch('/lessons/{lesson}', [UniversityLessonController::class, 'update']);
            Route::delete('/lessons/{lesson}', [UniversityLessonController::class, 'destroy']);
            Route::post('/lessons/{lesson}/media', [UniversityLessonController::class, 'uploadMedia']);
            Route::delete('/lessons/{lesson}/media/{mediaId}', [UniversityLessonController::class, 'destroyMedia']);

            Route::post('/certifications', [UniversityCertSettingsController::class, 'store']);
            Route::patch('/certifications/{certification}/settings', [UniversityCertSettingsController::class, 'update']);
            Route::get('/certifications/{certification}/questions', [UniversityCertSettingsController::class, 'questions']);
            Route::post('/certifications/{certification}/questions', [UniversityCertSettingsController::class, 'storeQuestion']);
            Route::patch('/questions/{question}', [UniversityCertSettingsController::class, 'updateQuestion']);
            Route::delete('/questions/{question}', [UniversityCertSettingsController::class, 'destroyQuestion']);
            Route::patch('/certificates/{certificate}/revoke', [UniversityCertificateController::class, 'revoke']);

            Route::get('/analytics/team', [UniversityAnalyticsController::class, 'team']);
            Route::get('/analytics/agents/{user}', [UniversityAnalyticsController::class, 'agent']);
            Route::get('/analytics/certifications/{certification}', [UniversityAnalyticsController::class, 'certification']);
            Route::get('/analytics/expiring', [UniversityAnalyticsController::class, 'expiring']);
            Route::get('/analytics/live-attempts', [UniversityAnalyticsController::class, 'liveAttempts']);
        });
    });

    // Settings
    Route::get('/settings/integrations', [SettingsController::class, 'integrations']);
    Route::get('/settings/auth', [AuthSettingsController::class, 'show'])->middleware('role:admin');
    Route::patch('/settings/auth', [AuthSettingsController::class, 'update'])->middleware('role:admin');
    Route::post('/settings/auth/google/test/start', [AuthSettingsController::class, 'startGoogleTest'])->middleware('role:admin');
    Route::post('/settings/auth/google/activate', [AuthSettingsController::class, 'activateGoogle'])->middleware('role:admin');
    Route::post('/settings/auth/rollback', [AuthSettingsController::class, 'rollback'])->middleware('role:admin');
    Route::get('/settings/reporting-currency', [SettingsController::class, 'reportingCurrency'])->middleware('role:admin,sub_admin,sales,marketing');
    Route::patch('/settings/reporting-currency', [SettingsController::class, 'updateReportingCurrency'])->middleware('role:admin,sub_admin');
    Route::get('/settings/reporting-currency/test', [SettingsController::class, 'testReportingCurrencyProvider'])->middleware('role:admin,sub_admin');
    Route::get('/settings/reporting-fx-rates', [SettingsController::class, 'listReportingFxRates'])->middleware('role:admin,sub_admin');
    Route::post('/settings/reporting-fx-rates', [SettingsController::class, 'createReportingFxRate'])->middleware('role:admin,sub_admin');
    Route::patch('/settings/reporting-fx-rates/{reportingFxRate}', [SettingsController::class, 'updateReportingFxRate'])->middleware('role:admin,sub_admin');
    Route::delete('/settings/reporting-fx-rates/{reportingFxRate}', [SettingsController::class, 'deleteReportingFxRate'])->middleware('role:admin,sub_admin');
    Route::get('/settings/sales-dashboard-widgets', [SettingsController::class, 'getSalesDashboardWidgets']);
    Route::patch('/settings/sales-dashboard-widgets', [SettingsController::class, 'updateSalesDashboardWidgets'])->middleware('role:admin,sub_admin');
    Route::get('/settings/billing/overview', [SettingsController::class, 'billingOverview']);
    Route::get('/settings/billing/system', [SettingsController::class, 'billingSystem']);
    Route::patch('/settings/billing/system/kill-switches', [SettingsController::class, 'updateBillingKillSwitches'])->middleware('role:admin');
    Route::get('/settings/billing/diagnostics-summary', [SettingsController::class, 'billingDiagnosticsSummary']);
    Route::get('/settings/billing/diagnostics-route-simulator', [SettingsController::class, 'billingDiagnosticsRouteSimulator']);
    Route::get('/settings/billing/providers-catalog', [SettingsController::class, 'providersCatalog']);
    Route::get('/settings/billing/provider-profiles', [SettingsController::class, 'providerProfiles']);
    Route::post('/settings/billing/provider-profiles', [SettingsController::class, 'storeProviderProfile']);
    Route::put('/settings/billing/provider-profiles/{profile}', [SettingsController::class, 'updateProviderProfile']);
    Route::get('/settings/billing/routing-rules/{market}', [SettingsController::class, 'billingRoutingRules']);
    Route::put('/settings/billing/routing-rules/{market}', [SettingsController::class, 'storeBillingRoutingRules']);
    Route::get('/settings/billing/wallet-rules/{market}', [SettingsController::class, 'billingWalletRules']);
    Route::put('/settings/billing/wallet-rules/{market}', [SettingsController::class, 'storeBillingWalletRules']);
    Route::get('/settings/billing/subscription-rules/{market}', [SettingsController::class, 'billingSubscriptionRules']);
    Route::put('/settings/billing/subscription-rules/{market}', [SettingsController::class, 'storeBillingSubscriptionRules']);
    Route::get('/settings/billing/manual-payment-methods/{market}', [SettingsController::class, 'billingManualPaymentMethods']);
    Route::put('/settings/billing/manual-payment-methods/{market}', [SettingsController::class, 'storeBillingManualPaymentMethods']);
    Route::get('/settings/system-health/updates', [SystemHealthUpdateController::class, 'show'])->middleware('role:admin,sub_admin');
    Route::get('/settings/system-health/updates/log', [SystemHealthUpdateController::class, 'log'])->middleware('role:admin,sub_admin');
    Route::get('/settings/system-health/updates/commits', [SystemHealthUpdateController::class, 'commitHistory'])->middleware('role:admin,sub_admin');
    Route::post('/settings/system-health/updates/deploy', [SystemHealthUpdateController::class, 'deploy'])->middleware('role:admin');
    Route::get('/settings/system-health/updates/history', [SystemHealthUpdateController::class, 'deploymentHistory'])->middleware('role:admin,sub_admin');
    Route::get('/settings/system-health/updates/backups', [SystemHealthUpdateController::class, 'backups'])->middleware('role:admin,sub_admin');
    Route::post('/settings/system-health/updates/upload-backup', [SystemHealthUpdateController::class, 'uploadBackup'])->middleware('role:admin');
    Route::delete('/settings/system-health/updates/backups/{filename}', [SystemHealthUpdateController::class, 'deleteBackup'])->middleware('role:admin');
    Route::post('/settings/system-health/updates/rollback', [SystemHealthUpdateController::class, 'rollback'])->middleware('role:admin');
    Route::get('/settings/system-health/queue-status', [SystemHealthUpdateController::class, 'queueStatus'])->middleware('role:admin,sub_admin');
    Route::post('/settings/system-health/queue-retry', [SystemHealthUpdateController::class, 'retryFailedJobs'])->middleware('role:admin');
    Route::post('/settings/system-health/queue-flush-failed', [SystemHealthUpdateController::class, 'flushFailedJobs'])->middleware('role:admin');
    Route::post('/settings/system-health/queue-clear-pending', [SystemHealthUpdateController::class, 'clearPendingJobs'])->middleware('role:admin');
    Route::post('/settings/system-health/queue-clear-all', [SystemHealthUpdateController::class, 'clearAllJobs'])->middleware('role:admin');
    Route::post('/settings/system-health/queue-nudge', [SystemHealthUpdateController::class, 'nudgeWorker'])->middleware('role:admin');
    Route::get('/settings/wallet', [SettingsController::class, 'wallet']);
    Route::patch('/settings/wallet', [SettingsController::class, 'updateWallet'])->middleware('role:admin');
    Route::patch('/settings/wallet/pin', [SettingsController::class, 'updateWalletPin'])->middleware('role:admin');
    Route::patch('/settings/free-trial/pin', [SettingsController::class, 'updateFreeTrialPin'])->middleware('role:admin');
    Route::patch('/settings/discounts/pin', [SettingsController::class, 'updateDiscountPin'])->middleware('role:admin');
    Route::patch('/settings/discounts/config', [SettingsController::class, 'updateDiscountConfig'])->middleware('role:admin');
    Route::post('/settings/wallet/test-email', [SettingsController::class, 'testWalletEmail'])->middleware('role:admin');
    Route::post('/settings/wallet/test-domain', [SettingsController::class, 'testWalletDomain'])->middleware('role:admin');
    Route::post('/settings/wallet/test-app', [SettingsController::class, 'testWalletApp'])->middleware('role:admin');
    Route::post('/settings/wallet/test-ssl', [SettingsController::class, 'testWalletSsl'])->middleware('role:admin');
    Route::get('/settings/integrations/push-provider', [SettingsController::class, 'pushProviderConfig']);
    Route::patch('/settings/integrations/push-provider', [SettingsController::class, 'updatePushProvider'])->middleware('role:admin,sub_admin');
    Route::post('/settings/integrations/push-provider/test', [SettingsController::class, 'testPushProvider'])->middleware('role:admin,sub_admin');
    Route::patch('/settings/integrations/sms-provider', [SettingsController::class, 'updateSmsProvider'])->middleware('role:admin');
    Route::post('/settings/integrations/sms-provider/test', [SettingsController::class, 'testSmsProvider'])->middleware('role:admin');
    Route::post('/settings/integrations/platforms', [SettingsController::class, 'storeIntegrationPlatform'])->middleware('role:admin');
    Route::patch('/settings/integrations/platforms/{platform}', [SettingsController::class, 'updateIntegrationPlatform'])->middleware('role:admin,sub_admin');
    Route::patch('/settings/integrations/platforms/{platform}/packages', [SettingsController::class, 'updatePlatformPackages'])->middleware('role:admin,sub_admin');
    Route::patch('/settings/integrations/platforms/{platform}/payment-link-providers', [SettingsController::class, 'updatePaymentLinkProviders'])->middleware('role:admin,sub_admin');
    Route::patch('/settings/integrations/platforms/{platform}/wallet', [SettingsController::class, 'updatePlatformWallet'])->middleware('role:admin,sub_admin');
    Route::patch('/settings/integrations/platforms/{platform}/wallet/providers', [SettingsController::class, 'updatePlatformWalletProviders'])->middleware('role:admin,sub_admin');
    Route::post('/settings/integrations/platforms/{platform}/wallet/providers/test', [SettingsController::class, 'testPlatformWalletProvider'])->middleware('role:admin,sub_admin');
    Route::post('/settings/integrations/platforms/{platform}/wallet/wp-credentials/rotate', [SettingsController::class, 'rotatePlatformWalletWpCredentials'])->middleware('role:admin,sub_admin');
    Route::post('/settings/integrations/platforms/{platform}/wallet/wp-credentials/push', [SettingsController::class, 'pushPlatformWalletWpCredentials'])->middleware('role:admin,sub_admin');
    Route::post('/settings/integrations/platforms/{platform}/test-connection', [SettingsController::class, 'testPlatformConnection'])->middleware('role:admin,sub_admin');
    Route::post('/settings/integrations/platforms/{platform}/sync', [SettingsController::class, 'runPlatformSync'])->middleware('role:admin,sub_admin');
    Route::get('/settings/integrations/platforms/{platform}/sync/latest', [SettingsController::class, 'latestPlatformClientSync'])->middleware('role:admin,sub_admin,sales');
    Route::post('/settings/integrations/platforms/{platform}/sync/reset-cursor', [SettingsController::class, 'resetPlatformClientSyncCursor'])->middleware('role:admin,sub_admin');
    Route::post('/settings/integrations/platforms/{platform}/capabilities/refresh', [SettingsController::class, 'refreshPlatformClientSyncCapabilities'])->middleware('role:admin,sub_admin');
    Route::post('/settings/integrations/platforms/{platform}/support-board/sync', [SettingsController::class, 'runPlatformSupportBoardSync'])->middleware('role:admin,sub_admin');
    Route::get('/settings/integrations/platforms/{platform}/support-board/sync/latest', [SettingsController::class, 'latestPlatformSupportBoardSync'])->middleware('role:admin,sub_admin');
    Route::post('/settings/integrations/platforms/{platform}/support-board/lead-import', [SettingsController::class, 'runSbLeadImport'])->middleware('role:admin,sub_admin');
    Route::get('/settings/integrations/platforms/{platform}/support-board/lead-import/latest', [SettingsController::class, 'latestSbLeadImportRun'])->middleware('role:admin,sub_admin');
    Route::post('/settings/integrations/scraper-sources', [SettingsController::class, 'storeScraperSource'])->middleware('role:admin,sub_admin');
    Route::patch('/settings/integrations/scraper-sources/{scraperSource}', [SettingsController::class, 'updateScraperSource'])->middleware('role:admin,sub_admin');
    Route::post('/settings/integrations/scraper-sources/{scraperSource}/run', [SettingsController::class, 'runScraperSource'])->middleware('role:admin,sub_admin');
    Route::get('/settings/owners', [SettingsController::class, 'owners']);
    Route::get('/settings/templates', [SettingsController::class, 'templates']);
    Route::post('/settings/templates', [SettingsController::class, 'storeTemplate'])->middleware('role:admin,sub_admin');
    Route::patch('/settings/templates/{template}', [SettingsController::class, 'updateTemplate'])->middleware('role:admin,sub_admin');
    Route::delete('/settings/templates/{template}', [SettingsController::class, 'destroyTemplate'])->middleware('role:admin,sub_admin');
    Route::get('/settings/webhook-logs', [SettingsController::class, 'webhookLogs']);
    Route::middleware('role:admin')->group(function () {
        Route::get('/settings/error-logs', [ErrorLogController::class, 'index']);
        Route::get('/settings/error-logs/{group}', [ErrorLogController::class, 'show']);
        Route::post('/settings/error-logs/{group}/resolve', [ErrorLogController::class, 'resolve']);
        Route::post('/settings/error-logs/{group}/reopen', [ErrorLogController::class, 'reopen']);
    });
    Route::get('/settings/roles', [SettingsController::class, 'roles'])->middleware('role:admin');
    Route::post('/settings/roles/users', [SettingsController::class, 'storeUser'])->middleware('role:admin');
    Route::patch('/settings/roles/{user}', [SettingsController::class, 'updateRole'])->middleware('role:admin');
    Route::post('/settings/roles/{user}/impersonation-link', [SettingsController::class, 'impersonationLink'])->middleware('role:admin');
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
Route::post('/self-checkout', [PaymentController::class, 'selfCheckout']);
Route::post('/manual-payment-submissions', [PaymentController::class, 'submitManualPayment']);
Route::post('/initiate-card-payment', [PaymentController::class, 'initiateCardPayment']);
Route::post('/cybersource/initiate-payment', [PaymentController::class, 'initiateCardPayment']);
Route::middleware('wallet.auth:read')->group(function () {
    Route::get('/wallet/balance', [WalletController::class, 'balance']);
    Route::get('/wallet/transactions', [WalletController::class, 'transactions']);
});
Route::middleware('wallet.auth:write')->group(function () {
    Route::post('/wallet/subscribe', [WalletController::class, 'subscribe']);
    Route::post('/billing/initiate', [BillingController::class, 'initiate']);
    Route::post('/billing/retry-stk', [BillingController::class, 'retryStk']);
});
Route::get('/payments', [PaymentController::class, 'list']);
Route::get('/payments/{user_id}', [PaymentController::class, 'getPayments'])->name('payment.history');

// Payment callbacks/webhooks (public)
Route::post('/payment-callback', [PaymentController::class, 'callback']);
Route::post('/callback', [PaymentController::class, 'paybillCallback']);
Route::post('/cybersource/notifications', [PaymentController::class, 'handleNotification']);
Route::post('/payment/notification', [PaymentController::class, 'handleNotification'])->name('payment.notification');
Route::post('/billing/paystack/webhook', [BillingController::class, 'paystackWebhook']);
Route::match(['get', 'post'], '/billing/pesapal/ipn', [BillingController::class, 'pesapalIpn']);
Route::post('/billing/mpesa/callback', [BillingController::class, 'mpesaCallback']);
Route::post('/billing/pawapay/callback', [BillingController::class, 'pawaPayCallback']);

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
Route::get('/webhook-info', function () {
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

Route::post('/simulate-webhook', function (Request $request) {
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
