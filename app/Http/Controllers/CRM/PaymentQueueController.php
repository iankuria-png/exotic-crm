<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Client;
use App\Models\Product;
use App\Models\Platform;
use App\Models\PaymentImportBatch;
use App\Models\PaymentImportRow;
use App\Models\TimelineEvent;
use App\Models\AuditLog;
use App\Services\AuditService;
use App\Services\BillingGatewayService;
use App\Services\BillingModeService;
use App\Services\NotificationService;
use App\Services\PaymentImportService;
use App\Services\PaymentMatchingService;
use App\Services\PaymentAttemptService;
use App\Services\PaymentCompletionService;
use App\Services\PaymentLinkService;
use App\Services\HostedCheckoutService;
use App\Services\LegacyStkService;
use App\Services\MarketAuthorizationService;
use App\Services\SubscriptionProvisioningService;
use App\Support\CrmAuditAction;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class PaymentQueueController extends Controller
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly AuditService $auditService,
        private readonly NotificationService $notificationService,
        private readonly PaymentAttemptService $paymentAttemptService,
        private readonly PaymentLinkService $paymentLinkService,
        private readonly PaymentImportService $paymentImportService,
        private readonly BillingModeService $billingModeService,
        private readonly BillingGatewayService $billingGatewayService,
        private readonly HostedCheckoutService $hostedCheckoutService,
        private readonly LegacyStkService $legacyStkService,
        private readonly PaymentCompletionService $paymentCompletionService,
        private readonly SubscriptionProvisioningService $subscriptionProvisioningService
    ) {
    }

    public function index(Request $request)
    {
        $this->marketAuthorizationService->ensureRequestedPlatformIsAccessible(
            $request,
            'platform_id',
            'You do not have access to this payment market.'
        );

        $query = Payment::with(['platform', 'product', 'client']);
        $this->marketAuthorizationService->applyPlatformScope($query, $request->user());

        if ($request->filled('platform_id')) {
            $query->where('platform_id', $request->platform_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('phone', 'like', "%{$search}%")
                    ->orWhere('transaction_reference', 'like', "%{$search}%");
            });
        }

        $statsQuery = clone $query;
        $statusFilter = trim((string) $request->input('status', ''));
        if ($statusFilter !== '') {
            if ($statusFilter === 'awaiting_payment') {
                $query->whereIn('status', ['initiated', 'pending']);
            } elseif ($statusFilter === 'recovery_queue') {
                $query->where(function ($builder) {
                    $builder->whereIn('status', ['initiated', 'pending', 'failed'])
                        ->orWhere(function ($unmatchedCompleted) {
                            $unmatchedCompleted->where('status', 'completed')
                                ->whereNull('client_id');
                        });
                });
            } else {
                $query->where('status', $statusFilter);
            }
        }

        if ($request->filled('matched')) {
            if ($request->matched === 'unmatched') {
                $query->whereNull('client_id');
            } elseif ($request->matched === 'matched') {
                $query->whereNotNull('client_id');
            }
        }

        if ($request->filled('source')) {
            $query->where('source', $request->source);
        }

        $confidenceFilter = trim((string) $request->input('match_confidence', ''));
        if ($confidenceFilter !== '') {
            if (in_array($confidenceFilter, ['high', 'medium', 'low'], true)) {
                $query->where('reconciliation_confidence', $confidenceFilter);
            } else {
                $query->where('match_confidence', $confidenceFilter);
            }
        }

        if ($request->filled('review_state')) {
            $query->where('reconciliation_state', $request->review_state);
        }

        $awaitingStatuses = ['initiated', 'pending'];
        $oneHourAgo = Carbon::now()->subHour();
        $dayAgo = Carbon::now()->subDay();
        $threeDaysAgo = Carbon::now()->subDays(3);

        $stats = [
            'total' => (clone $statsQuery)->count(),
            'pending' => (clone $statsQuery)->whereIn('status', $awaitingStatuses)->count(),
            'pending_amount' => (float) (clone $statsQuery)->whereIn('status', $awaitingStatuses)->sum('amount'),
            'confirmed' => (clone $statsQuery)->where('status', 'completed')->count(),
            'confirmed_amount' => (float) (clone $statsQuery)->where('status', 'completed')->sum('amount'),
            'failed' => (clone $statsQuery)->where('status', 'failed')->count(),
            'failed_amount' => (float) (clone $statsQuery)->where('status', 'failed')->sum('amount'),
            'matched' => (clone $statsQuery)->whereNotNull('client_id')->count(),
            'unmatched' => (clone $statsQuery)->whereNull('client_id')->count(),
            'unmatched_review' => (clone $statsQuery)->where('status', 'completed')->whereNull('client_id')->count(),
            'unmatched_review_amount' => (float) (clone $statsQuery)->where('status', 'completed')->whereNull('client_id')->sum('amount'),
            'awaiting_aging' => [
                'lt_1h' => [
                    'count' => (clone $statsQuery)->whereIn('status', $awaitingStatuses)->where('created_at', '>=', $oneHourAgo)->count(),
                    'amount' => (float) (clone $statsQuery)->whereIn('status', $awaitingStatuses)->where('created_at', '>=', $oneHourAgo)->sum('amount'),
                ],
                'h1_24' => [
                    'count' => (clone $statsQuery)->whereIn('status', $awaitingStatuses)->where('created_at', '<', $oneHourAgo)->where('created_at', '>=', $dayAgo)->count(),
                    'amount' => (float) (clone $statsQuery)->whereIn('status', $awaitingStatuses)->where('created_at', '<', $oneHourAgo)->where('created_at', '>=', $dayAgo)->sum('amount'),
                ],
                'h25_72' => [
                    'count' => (clone $statsQuery)->whereIn('status', $awaitingStatuses)->where('created_at', '<', $dayAgo)->where('created_at', '>=', $threeDaysAgo)->count(),
                    'amount' => (float) (clone $statsQuery)->whereIn('status', $awaitingStatuses)->where('created_at', '<', $dayAgo)->where('created_at', '>=', $threeDaysAgo)->sum('amount'),
                ],
                'gt_72h' => [
                    'count' => (clone $statsQuery)->whereIn('status', $awaitingStatuses)->where('created_at', '<', $threeDaysAgo)->count(),
                    'amount' => (float) (clone $statsQuery)->whereIn('status', $awaitingStatuses)->where('created_at', '<', $threeDaysAgo)->sum('amount'),
                ],
            ],
        ];

        $payments = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 25));

        $payload = $payments->toArray();
        $payload['stats'] = $stats;

        return response()->json($payload);
    }

    public function candidates(Request $request, Payment $payment)
    {
        $this->authorizePaymentAccess($request, $payment);
        $validated = $request->validate([
            'search' => 'nullable|string|max:120',
        ]);

        if (!$payment->platform_id) {
            return response()->json(['data' => []]);
        }

        $payment->loadMissing('platform');
        $phonePrefix = (string) ($payment->platform?->phone_prefix ?: '254');
        $phone = PhoneNormalizer::normalize($payment->phone, $phonePrefix);
        $search = trim((string) ($validated['search'] ?? ''));

        $query = Client::where('platform_id', $payment->platform_id)
            ->select(['id', 'wp_post_id', 'wp_user_id', 'name', 'phone_normalized', 'email', 'city', 'profile_status', 'premium', 'featured', 'verified']);

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('phone_normalized', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");

                if (ctype_digit($search)) {
                    $builder->orWhere('id', (int) $search)
                        ->orWhere('wp_post_id', (int) $search)
                        ->orWhere('wp_user_id', (int) $search);
                }
            });
        } elseif ($phone) {
            $query->where('phone_normalized', $phone);
        } else {
            $query->limit(25);
        }

        $candidates = $query->orderBy('name')->get();

        return response()->json([
            'payment_id' => $payment->id,
            'normalized_phone' => $phone,
            'count' => $candidates->count(),
            'data' => $candidates,
        ]);
    }

    public function autoMatch(Request $request, Payment $payment)
    {
        $this->authorizePaymentAccess($request, $payment);

        $beforeState = [
            'client_id' => $payment->client_id,
            'match_confidence' => $payment->match_confidence,
            'confirmed_by' => $payment->confirmed_by,
        ];

        $payment->loadMissing('platform');
        $phonePrefix = (string) ($payment->platform?->phone_prefix ?: '254');
        $service = new PaymentMatchingService();
        $result = $service->matchPayment($payment, $phonePrefix);
        $freshPayment = $payment->fresh(['platform', 'product', 'client']);

        $this->auditService->fromRequest(
            $request,
            (int) $payment->platform_id,
            CrmAuditAction::PAYMENT_MATCH_AUTO,
            'payment',
            (int) $payment->id,
            $beforeState,
            [
                'client_id' => $freshPayment?->client_id,
                'match_confidence' => $freshPayment?->match_confidence,
                'matched' => (bool) ($result['matched'] ?? false),
                'confidence' => $result['confidence'] ?? null,
            ],
            'Auto-match from payment queue'
        );

        return response()->json([
            'result' => $result,
            'payment' => $freshPayment,
        ]);
    }

    public function confirmMatch(Request $request, Payment $payment)
    {
        $this->authorizePaymentAccess($request, $payment);

        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'reason' => 'required|string|max:500',
        ]);

        $client = Client::findOrFail((int) $validated['client_id']);
        if ((int) $client->platform_id !== (int) $payment->platform_id) {
            return response()->json([
                'message' => 'Selected client does not belong to the payment market.',
            ], 422);
        }

        $service = new PaymentMatchingService();
        $beforeState = [
            'client_id' => $payment->client_id,
            'match_confidence' => $payment->match_confidence,
            'confirmed_by' => $payment->confirmed_by,
            'confirmed_at' => optional($payment->confirmed_at)->toDateTimeString(),
        ];

        $payment = $service->confirmMatch($payment, $validated['client_id'], $request->user()->id);
        $payment->load(['platform', 'product']);

        $this->auditService->fromRequest(
            $request,
            (int) $payment->platform_id,
            CrmAuditAction::PAYMENT_MATCH_CONFIRM,
            'payment',
            (int) $payment->id,
            $beforeState,
            [
                'client_id' => $payment->client_id,
                'match_confidence' => $payment->match_confidence,
                'confirmed_by' => $payment->confirmed_by,
                'confirmed_at' => optional($payment->confirmed_at)->toDateTimeString(),
            ],
            (string) $validated['reason']
        );

        $canCreateSubscription = $payment->status === 'completed'
            && $payment->client_id
            && !$payment->deal_id
            && $this->resolveReconciliationConfidence($payment) === 'high';

        return response()->json([
            'payment' => $payment,
            'can_create_subscription' => $canCreateSubscription,
        ]);
    }

    public function createSubscription(Request $request, Payment $payment)
    {
        $this->authorizePaymentAccess($request, $payment);

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        if ($payment->status !== 'completed') {
            return response()->json(['message' => 'Only completed payments can create subscriptions.'], 422);
        }
        if (!$payment->client_id) {
            return response()->json(['message' => 'Payment must be matched to a client first.'], 422);
        }
        if ($payment->deal_id) {
            return response()->json(['message' => 'Payment is already linked to a subscription.'], 422);
        }

        $reconciliationConfidence = $this->resolveReconciliationConfidence($payment);
        if ($reconciliationConfidence !== 'high') {
            return response()->json([
                'message' => 'Subscription creation requires high-confidence reconciliation. Confirm the payment match first.',
            ], 422);
        }

        $beforeState = [
            'deal_id' => $payment->deal_id,
            'client_id' => $payment->client_id,
            'status' => $payment->status,
            'reconciliation_confidence' => $reconciliationConfidence,
        ];

        try {
            $deal = DB::transaction(fn () => $this->subscriptionProvisioningService->provisionCompletedPayment($payment, [
                'actor_id' => (int) $request->user()->id,
                'confirmed_by' => (int) $request->user()->id,
                'confirmed_at' => $payment->confirmed_at ?? now(),
                'match_confidence' => $payment->match_confidence ?: 'manual',
                'reconciliation_confidence' => $payment->reconciliation_confidence ?: $reconciliationConfidence,
                'reconciliation_state' => 'resolved',
                'emit_payment_received_timeline' => true,
                'emit_profile_activated_timeline' => false,
                'emit_deal_activated_timeline' => true,
            ]));
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (\Throwable $exception) {
            Log::error('Payment queue subscription creation failed', [
                'payment_id' => $payment->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Subscription creation failed: ' . $exception->getMessage(),
            ], 500);
        }

        $payment->refresh();
        $payment->load(['platform', 'product', 'client']);
        $deal->load(['client', 'product', 'platform']);

        $this->auditService->fromRequest(
            $request,
            (int) $payment->platform_id,
            CrmAuditAction::PAYMENT_CREATE_SUBSCRIPTION,
            'payment',
            (int) $payment->id,
            $beforeState,
            [
                'deal_id' => $deal->id,
                'deal_status' => $deal->status,
                'expires_at' => optional($deal->expires_at)->toDateTimeString(),
            ],
            $validated['reason'] ?? 'Subscription created from matched payment'
        );

        return response()->json([
            'payment' => $payment,
            'deal' => $deal,
        ], 201);
    }

    public function batchMatch(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'nullable|exists:platforms,id',
            'reason' => 'required|string|max:500',
            'dry_run' => 'nullable|boolean',
        ]);

        $platformId = !empty($validated['platform_id']) ? (int) $validated['platform_id'] : null;
        if ($platformId) {
            $this->marketAuthorizationService->ensureUserCanAccessPlatform(
                $request->user(),
                $platformId,
                'You do not have access to this market.'
            );
        }

        $service = new PaymentMatchingService();
        $dryRun = (bool) ($validated['dry_run'] ?? false);

        $accessiblePlatformIds = null;

        if ($dryRun) {
            if ($platformId !== null) {
                $results = $service->dryRunBatchMatch($platformId);
            } else {
                $accessiblePlatformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());
                if (is_array($accessiblePlatformIds)) {
                    if (empty($accessiblePlatformIds)) {
                        return response()->json([
                            'message' => 'No accessible markets available for batch matching.',
                        ], 422);
                    }
                    $results = $service->dryRunBatchMatchForPlatforms($accessiblePlatformIds);
                } else {
                    $results = $service->dryRunBatchMatch();
                }
            }

            $results['dry_run'] = true;
            return response()->json($results);
        }

        if ($platformId !== null) {
            $results = $service->batchMatch($platformId);
        } else {
            $accessiblePlatformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());
            if (is_array($accessiblePlatformIds)) {
                if (empty($accessiblePlatformIds)) {
                    return response()->json([
                        'message' => 'No accessible markets available for batch matching.',
                    ], 422);
                }

                $results = $service->batchMatchForPlatforms($accessiblePlatformIds);
            } else {
                $results = $service->batchMatch();
            }
        }

        $auditPlatformId = $platformId
            ?? (is_array($accessiblePlatformIds) && !empty($accessiblePlatformIds) ? (int) $accessiblePlatformIds[0] : null);

        if ($auditPlatformId !== null) {
            $this->auditService->fromRequest(
                $request,
                $auditPlatformId,
                CrmAuditAction::PAYMENT_MATCH_BATCH,
                'user',
                (int) $request->user()->id,
                [
                    'requested_platform_id' => $platformId,
                    'scoped_platform_ids' => is_array($accessiblePlatformIds) ? $accessiblePlatformIds : null,
                ],
                $results,
                (string) $validated['reason']
            );
        }

        return response()->json($results);
    }

    public function importPreview(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'required|integer|exists:platforms,id',
            'file' => 'required|file|mimes:csv,txt,xlsx,xml|max:20480',
            'has_header' => 'nullable|boolean',
            'default_currency' => 'nullable|string|max:10',
            'reason' => 'required|string|max:500',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);

        $platformId = (int) $validated['platform_id'];
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            $platformId,
            'You do not have access to this payment market.'
        );

        $platform = Platform::query()->findOrFail($platformId);

        try {
            $result = $this->paymentImportService->previewImport(
                $validated['file'],
                $platform,
                (int) $request->user()->id,
                (bool) ($validated['has_header'] ?? true),
                (string) $validated['reason'],
                $validated['default_currency'] ?? null,
                $validated['date_from'] ?? null,
                $validated['date_to'] ?? null
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        $this->auditService->fromRequest(
            $request,
            $platformId,
            CrmAuditAction::PAYMENT_IMPORT_PREVIEW,
            'payment_import_batch',
            (int) $result['batch_id'],
            null,
            [
                'file_name' => $validated['file']->getClientOriginalName(),
                'summary' => $result['summary'] ?? [],
            ],
            (string) $validated['reason']
        );

        return response()->json($result);
    }

    public function importCommit(Request $request)
    {
        $validated = $request->validate([
            'batch_id' => 'required|integer|exists:payment_import_batches,id',
            'reason' => 'required|string|max:500',
        ]);

        $batch = PaymentImportBatch::query()->findOrFail((int) $validated['batch_id']);
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $batch->platform_id,
            'You do not have access to this payment market.'
        );

        $beforeState = [
            'status' => $batch->status,
            'total_rows' => (int) $batch->total_rows,
            'valid_rows' => (int) $batch->valid_rows,
            'invalid_rows' => (int) $batch->invalid_rows,
            'duplicate_rows' => (int) $batch->duplicate_rows,
            'committed_rows' => (int) $batch->committed_rows,
        ];

        $result = $this->paymentImportService->commitImport(
            $batch,
            (int) $request->user()->id,
            (string) $validated['reason']
        );

        $this->auditService->fromRequest(
            $request,
            (int) $batch->platform_id,
            CrmAuditAction::PAYMENT_IMPORT_COMMIT,
            'payment_import_batch',
            (int) $batch->id,
            $beforeState,
            $result['summary'] ?? [],
            (string) $validated['reason']
        );

        return response()->json($result);
    }

    public function importTemplate(): \Symfony\Component\HttpFoundation\Response
    {
        $headers = [
            'payment_date',
            'amount',
            'currency',
            'phone',
            'transaction_reference',
            'status',
            'profile_url',
            'subscription_type',
            'notes',
        ];

        $sample = [
            '2026-01-31 09:15:00',
            '2500',
            'KES',
            '0711000001',
            'SAMPLEABC123',
            'completed',
            'https://example.com/profile/sample',
            'renewal',
            'Optional free-form note',
        ];

        $csv = implode(',', $headers) . PHP_EOL . implode(',', $sample) . PHP_EOL;

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="payment-import-template.csv"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function importKpis(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'nullable|integer|exists:platforms,id',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $this->marketAuthorizationService->ensureRequestedPlatformIsAccessible(
            $request,
            'platform_id',
            'You do not have access to this payment market.'
        );

        $batchQuery = PaymentImportBatch::query();
        $paymentQuery = Payment::query()->where('source', 'excel_import');

        if (!empty($validated['platform_id'])) {
            $platformId = (int) $validated['platform_id'];
            $batchQuery->where('platform_id', $platformId);
            $paymentQuery->where('platform_id', $platformId);
        } else {
            $accessiblePlatformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());
            if (is_array($accessiblePlatformIds)) {
                if (empty($accessiblePlatformIds)) {
                    return response()->json([
                        'kpis' => [
                            'batches' => 0,
                            'rows_total' => 0,
                            'rows_committed' => 0,
                            'rows_duplicate' => 0,
                            'rows_invalid' => 0,
                            'payments_imported' => 0,
                            'duplicate_rate_pct' => 0,
                            'auto_high_rate_pct' => 0,
                            'manual_review_open' => 0,
                        ],
                        'aging' => [
                            'lt_3d' => 0,
                            'd4_14' => 0,
                            'gt_14d' => 0,
                        ],
                    ]);
                }

                $batchQuery->whereIn('platform_id', $accessiblePlatformIds);
                $paymentQuery->whereIn('platform_id', $accessiblePlatformIds);
            }
        }

        if (!empty($validated['from'])) {
            $batchQuery->whereDate('created_at', '>=', $validated['from']);
            $paymentQuery->whereDate('created_at', '>=', $validated['from']);
        }
        if (!empty($validated['to'])) {
            $batchQuery->whereDate('created_at', '<=', $validated['to']);
            $paymentQuery->whereDate('created_at', '<=', $validated['to']);
        }

        $batches = (clone $batchQuery)->count();
        $rowsTotal = (int) (clone $batchQuery)->sum('total_rows');
        $rowsCommitted = (int) (clone $batchQuery)->sum('committed_rows');
        $rowsDuplicate = (int) (clone $batchQuery)->sum('duplicate_rows');
        $rowsInvalid = (int) (clone $batchQuery)->sum('invalid_rows');

        $paymentsImported = (clone $paymentQuery)->count();
        $autoHighCount = (clone $paymentQuery)->where('reconciliation_confidence', 'high')->count();
        $manualReviewOpen = (clone $paymentQuery)->where('reconciliation_state', 'manual_review')->count();

        $now = now();
        $aging = [
            'lt_3d' => (clone $paymentQuery)
                ->where('reconciliation_state', 'manual_review')
                ->where('created_at', '>=', $now->copy()->subDays(3))
                ->count(),
            'd4_14' => (clone $paymentQuery)
                ->where('reconciliation_state', 'manual_review')
                ->where('created_at', '<', $now->copy()->subDays(3))
                ->where('created_at', '>=', $now->copy()->subDays(14))
                ->count(),
            'gt_14d' => (clone $paymentQuery)
                ->where('reconciliation_state', 'manual_review')
                ->where('created_at', '<', $now->copy()->subDays(14))
                ->count(),
        ];

        return response()->json([
            'kpis' => [
                'batches' => $batches,
                'rows_total' => $rowsTotal,
                'rows_committed' => $rowsCommitted,
                'rows_duplicate' => $rowsDuplicate,
                'rows_invalid' => $rowsInvalid,
                'payments_imported' => $paymentsImported,
                'duplicate_rate_pct' => $rowsTotal > 0 ? round(($rowsDuplicate / $rowsTotal) * 100, 2) : 0,
                'auto_high_rate_pct' => $paymentsImported > 0 ? round(($autoHighCount / $paymentsImported) * 100, 2) : 0,
                'manual_review_open' => $manualReviewOpen,
            ],
            'aging' => $aging,
        ]);
    }

    public function diagnostics(Request $request, Payment $payment)
    {
        $this->authorizePaymentAccess($request, $payment);

        $payment->load(['platform', 'product', 'client', 'deal', 'confirmedBy']);

        $attempts = $payment->attempts()
            ->with('actor:id,name,email')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        $auditEntries = AuditLog::query()
            ->with('actor:id,name,email')
            ->where('entity_type', 'payment')
            ->where('entity_id', $payment->id)
            ->whereIn('action', [
                CrmAuditAction::PAYMENT_RETRY_STK,
                CrmAuditAction::PAYMENT_SEND_LINK,
                CrmAuditAction::PAYMENT_MATCH_AUTO,
                CrmAuditAction::PAYMENT_MATCH_CONFIRM,
                CrmAuditAction::PAYMENT_CREATE_SUBSCRIPTION,
                CrmAuditAction::PAYMENT_MANUAL_CLOSE,
                CrmAuditAction::PAYMENT_REVIEW_STATE_UPDATE,
            ])
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        $latestFailedAttempt = $attempts->first(fn($attempt) => $attempt->status === 'failed');
        $latestAttempt = $attempts->first();
        $rawPayload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $manualCloseMeta = $rawPayload['manual_close'] ?? null;
        $linkProxy = $this->buildLinkProxyDiagnostics($payment, $attempts, $auditEntries);

        $requestMeta = $attempts
            ->map(fn($attempt) => is_array($attempt->request_meta) ? $attempt->request_meta : null)
            ->first(fn($meta) => is_array($meta) && !empty($meta));

        $latencies = $attempts
            ->pluck('latency_ms')
            ->filter(fn($latency) => $latency !== null)
            ->map(fn($latency) => (int) $latency)
            ->sort()
            ->values();

        $avgLatency = $latencies->count() > 0
            ? (int) round($latencies->sum() / $latencies->count())
            : null;
        $p95Latency = $latencies->count() > 0
            ? (int) $latencies->get(max(0, (int) ceil(($latencies->count() * 0.95) - 1)))
            : null;
        $timelineEntries = TimelineEvent::query()
            ->with('actor:id,name,email')
            ->where('entity_type', 'payment')
            ->where('entity_id', $payment->id)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        $failureStage = $this->resolveFailureStage($payment, $latestFailedAttempt, $latestAttempt, $manualCloseMeta, $linkProxy);
        $failureReason = $latestFailedAttempt?->error_message
            ?? (is_array($rawPayload['failure_data'] ?? null) ? ($rawPayload['failure_data']['message'] ?? null) : null)
            ?? (is_array($manualCloseMeta) ? ($manualCloseMeta['reason'] ?? null) : null);

        return response()->json([
            'payment' => $payment,
            'failure' => [
                'status' => $payment->status,
                'stage' => $failureStage,
                'reason' => $failureReason,
                'error_code' => $latestFailedAttempt?->error_code ?? null,
                'http_status' => $latestFailedAttempt?->http_status ?? null,
                'manual_close' => is_array($manualCloseMeta) ? $manualCloseMeta : null,
            ],
            'performance' => [
                'attempt_count' => $attempts->count(),
                'avg_latency_ms' => $avgLatency,
                'p95_latency_ms' => $p95Latency,
                'last_latency_ms' => $latestAttempt?->latency_ms ? (int) $latestAttempt->latency_ms : null,
            ],
            'browser_meta' => [
                'origin_url' => $requestMeta['origin_url'] ?? null,
                'referrer' => $requestMeta['referrer'] ?? null,
                'user_agent_family' => $requestMeta['user_agent_family'] ?? null,
                'device_type' => $requestMeta['device_type'] ?? null,
                'ip_hash' => $requestMeta['ip_hash'] ?? null,
                'request_id' => $requestMeta['request_id'] ?? null,
            ],
            'recommendations' => $this->buildRecommendations($payment, $linkProxy),
            'link_proxy' => $linkProxy,
            'attempts' => $attempts->map(function ($attempt) {
                return [
                    'id' => (int) $attempt->id,
                    'attempt_type' => $attempt->attempt_type,
                    'provider' => $attempt->provider,
                    'status' => $attempt->status,
                    'error_code' => $attempt->error_code,
                    'error_message' => $attempt->error_message,
                    'http_status' => $attempt->http_status,
                    'latency_ms' => $attempt->latency_ms,
                    'request_meta' => $attempt->request_meta,
                    'response_meta' => $attempt->response_meta,
                    'actor' => $attempt->actor ? [
                        'id' => (int) $attempt->actor->id,
                        'name' => $attempt->actor->name,
                        'email' => $attempt->actor->email,
                    ] : null,
                    'created_at' => optional($attempt->created_at)->toDateTimeString(),
                ];
            })->values(),
            'audit_trail' => $auditEntries->map(function (AuditLog $log) {
                return [
                    'id' => (int) $log->id,
                    'action' => $log->action,
                    'reason' => $log->reason,
                    'before_state' => $log->before_state,
                    'after_state' => $log->after_state,
                    'actor' => $log->actor ? [
                        'id' => (int) $log->actor->id,
                        'name' => $log->actor->name,
                    ] : null,
                    'created_at' => optional($log->created_at)->toDateTimeString(),
                ];
            })->values(),
            'timeline' => $timelineEntries->map(function (TimelineEvent $event) {
                return [
                    'id' => (int) $event->id,
                    'event_type' => $event->event_type,
                    'content' => is_array($event->content) ? $event->content : null,
                    'actor' => $event->actor ? [
                        'id' => (int) $event->actor->id,
                        'name' => $event->actor->name,
                        'email' => $event->actor->email,
                    ] : null,
                    'created_at' => optional($event->created_at)->toDateTimeString(),
                ];
            })->values(),
        ]);
    }

    public function checkProviderStatus(Request $request, Payment $payment)
    {
        $this->authorizePaymentAccess($request, $payment);

        try {
            $snapshot = $this->verifyProviderStatus($payment);

            $this->paymentAttemptService->record($payment, 'provider_status_check', (string) ($snapshot['status'] ?? 'failed'), [
                'provider' => $payment->provider_key,
                'error_message' => $snapshot['message'] ?? null,
                'request_meta' => $this->paymentAttemptService->requestMetaFromRequest($request, [
                    'provider_environment' => $payment->provider_environment,
                    'reference_number' => $payment->reference_number,
                    'provider_reference' => $snapshot['provider_reference'] ?? null,
                ]),
                'response_meta' => [
                    'provider_status' => $snapshot['status'] ?? null,
                    'provider_message' => $snapshot['message'] ?? null,
                    'provider_payload' => $snapshot['data'] ?? null,
                ],
                'created_by' => optional($request->user())->id,
            ]);

            return response()->json($snapshot);
        } catch (InvalidArgumentException $exception) {
            $this->paymentAttemptService->record($payment, 'provider_status_check', 'failed', [
                'provider' => $payment->provider_key,
                'error_code' => 'provider_status_unavailable',
                'error_message' => $exception->getMessage(),
                'request_meta' => $this->paymentAttemptService->requestMetaFromRequest($request, [
                    'provider_environment' => $payment->provider_environment,
                    'reference_number' => $payment->reference_number,
                ]),
                'created_by' => optional($request->user())->id,
            ]);

            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (\Throwable $exception) {
            $this->paymentAttemptService->record($payment, 'provider_status_check', 'failed', [
                'provider' => $payment->provider_key,
                'error_code' => 'provider_status_check_failed',
                'error_message' => $exception->getMessage(),
                'request_meta' => $this->paymentAttemptService->requestMetaFromRequest($request, [
                    'provider_environment' => $payment->provider_environment,
                    'reference_number' => $payment->reference_number,
                ]),
                'created_by' => optional($request->user())->id,
            ]);

            return response()->json([
                'message' => 'Provider status could not be checked right now.',
            ], 502);
        }
    }

    public function sandboxReconcile(Request $request, Payment $payment)
    {
        $this->authorizePaymentAccess($request, $payment);

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $reason = trim((string) ($validated['reason'] ?? ''));
        $beforeState = $this->sandboxReconcileState($payment);

        try {
            $this->assertSandboxReconcileEligibility($payment);

            if ($this->isSandboxReconcileTerminal($payment)) {
                $freshPayment = $payment->fresh(['platform', 'product', 'client']);
                $this->paymentAttemptService->record($freshPayment, 'sandbox_reconcile', (string) data_get($freshPayment->payment_data, 'test_result', $freshPayment->status), [
                    'provider' => $freshPayment->provider_key,
                    'request_meta' => $this->paymentAttemptService->requestMetaFromRequest($request, [
                        'provider_environment' => $freshPayment->provider_environment,
                        'reference_number' => $freshPayment->reference_number,
                        'already_reconciled' => true,
                    ]),
                    'response_meta' => [
                        'already_reconciled' => true,
                        'payment_status' => $freshPayment->status,
                        'test_result' => data_get($freshPayment->payment_data, 'test_result'),
                    ],
                    'created_by' => optional($request->user())->id,
                ]);

                return response()->json([
                    'message' => 'Sandbox payment was already reconciled.',
                    'already_reconciled' => true,
                    'reconciled' => false,
                    'payment' => $freshPayment,
                    'provider_snapshot' => null,
                ]);
            }

            $snapshot = $this->verifyProviderStatus($payment);
            $providerStatus = (string) ($snapshot['status'] ?? 'failed');
            $updatedPayment = $payment->fresh(['platform', 'product', 'client']);
            $reconciled = false;
            $message = 'Provider still reports this sandbox payment as pending.';

            if ($providerStatus === 'completed') {
                $completion = $this->paymentCompletionService->complete($payment, is_array($snapshot['data'] ?? null) ? $snapshot['data'] : [], [
                    'transaction_reference' => $snapshot['provider_reference'] ?? null,
                ]);
                $updatedPayment = $completion['payment'] ?? $payment->fresh(['platform', 'product', 'client']);
                $reconciled = true;
                $message = 'Sandbox payment reconciled as completed.';
            } elseif ($providerStatus === 'failed') {
                $updatedPayment = $this->billingGatewayService->failPayment(
                    $payment,
                    (string) ($snapshot['message'] ?? 'Sandbox provider verification failed.'),
                    is_array($snapshot['data'] ?? null) ? $snapshot['data'] : []
                );
                $reconciled = true;
                $message = 'Sandbox payment reconciled as failed.';
            }

            $afterState = $this->sandboxReconcileState($updatedPayment);
            $this->paymentAttemptService->record($updatedPayment, 'sandbox_reconcile', $providerStatus, [
                'provider' => $updatedPayment->provider_key,
                'error_message' => $snapshot['message'] ?? null,
                'request_meta' => $this->paymentAttemptService->requestMetaFromRequest($request, [
                    'provider_environment' => $updatedPayment->provider_environment,
                    'reference_number' => $updatedPayment->reference_number,
                    'provider_reference' => $snapshot['provider_reference'] ?? null,
                ]),
                'response_meta' => [
                    'provider_status' => $providerStatus,
                    'provider_message' => $snapshot['message'] ?? null,
                    'provider_payload' => $snapshot['data'] ?? null,
                    'reconciled' => $reconciled,
                    'before_state' => $beforeState,
                    'after_state' => $afterState,
                ],
                'created_by' => optional($request->user())->id,
            ]);

            $this->auditService->fromRequest(
                $request,
                (int) $updatedPayment->platform_id,
                CrmAuditAction::PAYMENT_SANDBOX_RECONCILE,
                'payment',
                (int) $updatedPayment->id,
                $beforeState,
                $afterState,
                $reason !== '' ? $reason : $message
            );

            return response()->json([
                'message' => $message,
                'already_reconciled' => false,
                'reconciled' => $reconciled,
                'payment' => $updatedPayment->fresh(['platform', 'product', 'client']),
                'provider_snapshot' => $snapshot,
            ]);
        } catch (InvalidArgumentException $exception) {
            $this->paymentAttemptService->record($payment, 'sandbox_reconcile', 'failed', [
                'provider' => $payment->provider_key,
                'error_code' => 'sandbox_reconcile_unavailable',
                'error_message' => $exception->getMessage(),
                'request_meta' => $this->paymentAttemptService->requestMetaFromRequest($request, [
                    'provider_environment' => $payment->provider_environment,
                    'reference_number' => $payment->reference_number,
                ]),
                'created_by' => optional($request->user())->id,
            ]);

            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (\Throwable $exception) {
            $this->paymentAttemptService->record($payment, 'sandbox_reconcile', 'failed', [
                'provider' => $payment->provider_key,
                'error_code' => 'sandbox_reconcile_failed',
                'error_message' => $exception->getMessage(),
                'request_meta' => $this->paymentAttemptService->requestMetaFromRequest($request, [
                    'provider_environment' => $payment->provider_environment,
                    'reference_number' => $payment->reference_number,
                ]),
                'created_by' => optional($request->user())->id,
            ]);

            return response()->json([
                'message' => 'Sandbox reconcile could not be completed right now.',
            ], 502);
        }
    }

    public function updateReviewState(Request $request, Payment $payment)
    {
        $this->authorizePaymentAccess($request, $payment);

        $validated = $request->validate([
            'state' => 'required|in:open,manual_review,resolved',
            'reason' => 'required|string|max:500',
        ]);

        $beforeState = [
            'reconciliation_state' => $payment->reconciliation_state,
            'reconciliation_confidence' => $this->resolveReconciliationConfidence($payment),
        ];

        $payment->forceFill([
            'reconciliation_state' => $validated['state'],
        ])->save();

        $this->auditService->fromRequest(
            $request,
            (int) $payment->platform_id,
            CrmAuditAction::PAYMENT_REVIEW_STATE_UPDATE,
            'payment',
            (int) $payment->id,
            $beforeState,
            [
                'reconciliation_state' => $payment->reconciliation_state,
                'reconciliation_confidence' => $this->resolveReconciliationConfidence($payment),
            ],
            (string) $validated['reason']
        );

        return response()->json([
            'message' => 'Review state updated.',
            'payment' => $payment->fresh(['platform', 'product', 'client']),
        ]);
    }

    public function manualClose(Request $request, Payment $payment)
    {
        $this->authorizePaymentAccess($request, $payment);

        $validated = $request->validate([
            'category' => 'required|in:timeout,customer_cancelled,duplicate_request,fraud_suspected,other',
            'reason' => 'required|string|max:500',
        ]);

        if (!in_array($payment->status, ['initiated', 'pending'], true)) {
            return response()->json([
                'message' => 'Only initiated or pending payments can be manually closed.',
            ], 422);
        }

        $beforeState = [
            'status' => $payment->status,
            'raw_payload' => $payment->raw_payload,
        ];

        $rawPayload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $rawPayload['manual_close'] = [
            'category' => $validated['category'],
            'reason' => $validated['reason'],
            'closed_by' => optional($request->user())->id,
            'closed_at' => now()->toDateTimeString(),
        ];

        $payment->forceFill([
            'status' => 'failed',
            'reconciliation_state' => 'resolved',
            'raw_payload' => $rawPayload,
        ])->save();

        $this->paymentAttemptService->record($payment, 'manual_close', 'closed', [
            'provider' => 'crm_operator',
            'error_code' => 'manual_close',
            'error_message' => $validated['reason'],
            'request_meta' => $this->paymentAttemptService->requestMetaFromRequest($request, [
                'category' => $validated['category'],
            ]),
            'response_meta' => [
                'category' => $validated['category'],
            ],
            'created_by' => optional($request->user())->id,
        ]);

        $this->auditService->fromRequest(
            $request,
            (int) $payment->platform_id,
            CrmAuditAction::PAYMENT_MANUAL_CLOSE,
            'payment',
            (int) $payment->id,
            $beforeState,
            [
                'status' => $payment->status,
                'manual_close' => $rawPayload['manual_close'],
            ],
            $validated['reason']
        );

        return response()->json([
            'message' => 'Payment closed manually.',
            'payment' => $payment->fresh(['platform', 'product', 'client']),
        ]);
    }

    /**
     * Retry STK push for a failed or initiated payment using the existing Django proxy.
     * Reuses the same initiate flow; callback will update this payment row.
     */
    public function retryStk(Request $request, Payment $payment)
    {
        $this->authorizePaymentAccess($request, $payment);

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        if (!in_array($payment->status, ['failed', 'initiated', 'pending'], true)) {
            return response()->json([
                'message' => 'Only failed, initiated, or pending payments can be retried.',
            ], 422);
        }

        $payment->load(['product', 'platform', 'client']);
        $product = $payment->product;
        $platform = $payment->platform;

        if (!$product || !$platform) {
            return response()->json([
                'message' => 'Payment is missing product or platform.',
            ], 422);
        }

        $phone = PhoneNormalizer::normalize($payment->phone, (string) ($platform->phone_prefix ?: '254'));
        if (!$phone) {
            return response()->json([
                'message' => 'Payment has no valid phone number for STK push.',
            ], 422);
        }

        $amount = (float) $payment->amount;
        $duration = $payment->duration ?? 'monthly';
        if ($amount <= 0) {
            return response()->json([
                'message' => 'Payment amount is invalid.',
            ], 422);
        }

        $firstName = null;
        $lastName = null;
        $email = null;
        if ($payment->client_id && $payment->relationLoaded('client') && $payment->client) {
            $firstName = $payment->client->name ? explode(' ', $payment->client->name, 2)[0] ?? null : null;
            $lastName = $payment->client->name ? (explode(' ', $payment->client->name, 2)[1] ?? null) : null;
            $email = $payment->client->email;
        }

        $beforeStatus = $payment->status;
        $payment->status = 'initiated';
        $payment->save();

        $requestMeta = $this->paymentAttemptService->requestMetaFromRequest($request, [
            'channel' => 'stk',
            'phone' => $phone,
            'amount' => $amount,
            'duration' => $duration,
        ]);
        $attemptStartedAt = microtime(true);

        try {
            $result = $this->legacyStkService->initiate($payment, [
                'phone' => $phone,
                'duration' => $duration,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
            ]);
        } catch (InvalidArgumentException $exception) {
            $payment->status = 'failed';
            $payment->save();

            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (\Throwable $exception) {
            Log::error('Retry STK: initiation crashed', [
                'payment_id' => $payment->id,
                'error' => $exception->getMessage(),
            ]);
            $payment->status = 'failed';
            $payment->save();

            return response()->json([
                'message' => 'STK push could not be initiated.',
            ], 500);
        }

        $latencyMs = (int) round((microtime(true) - $attemptStartedAt) * 1000);

        if ($result['success']) {
            $updates = [
                'status' => 'pending',
                'failure_reason' => null,
                'provider_key' => 'mpesa_stk',
                'provider_environment' => $result['provider_environment'] ?? null,
                'raw_payload' => array_merge($payment->raw_payload ?? [], [
                    'legacy_stk' => $result['provider_response'] ?? null,
                ]),
            ];
            if (!empty($result['provider_reference'])) {
                $updates['transaction_reference'] = $result['provider_reference'];
            }
            $payment->forceFill($updates)->save();

            $this->paymentAttemptService->record($payment, 'retry_stk', 'success', [
                'provider' => $result['provider'] ?? 'django_stk',
                'http_status' => $result['http_status'] ?? null,
                'latency_ms' => $latencyMs,
                'request_meta' => $requestMeta,
                'response_meta' => [
                    'message' => $result['message'] ?? null,
                    'provider_environment' => $result['provider_environment'] ?? null,
                    'transport' => $result['transport'] ?? null,
                    'upstream_url' => $result['upstream_url'] ?? null,
                    'provider_response' => $result['provider_response'] ?? null,
                ],
                'created_by' => optional($request->user())->id,
            ]);

            $this->auditService->fromRequest(
                $request,
                (int) $payment->platform_id,
                CrmAuditAction::PAYMENT_RETRY_STK,
                'payment',
                (int) $payment->id,
                ['before_status' => $beforeStatus],
                [
                    'after_status' => 'pending',
                    'provider' => $result['provider'] ?? null,
                    'provider_environment' => $result['provider_environment'] ?? null,
                    'transport' => $result['transport'] ?? null,
                    'upstream_url' => $result['upstream_url'] ?? null,
                    'provider_response' => $result['provider_response'] ?? null,
                ],
                (string) ($validated['reason'] ?? 'Retry STK from CRM')
            );

            return response()->json([
                'message' => $result['message'] ?? 'STK push sent. Customer should complete the request on their phone.',
                'payment' => $payment->fresh(['platform', 'product']),
            ]);
        }

        Log::warning('Retry STK: upstream rejected initiation', [
            'payment_id' => $payment->id,
            'provider' => $result['provider'] ?? null,
            'transport' => $result['transport'] ?? null,
            'upstream_url' => $result['upstream_url'] ?? null,
            'http_status' => $result['http_status'] ?? null,
            'redirect_location' => $result['redirect_location'] ?? null,
            'response_body' => $result['response_body'] ?? null,
            'provider_response' => $result['provider_response'] ?? null,
        ]);

        $payment->forceFill([
            'status' => 'failed',
            'failure_reason' => mb_substr((string) ($result['message'] ?? 'STK push could not be initiated.'), 0, 190),
            'provider_key' => 'mpesa_stk',
            'provider_environment' => $result['provider_environment'] ?? null,
            'raw_payload' => array_merge($payment->raw_payload ?? [], [
                'legacy_stk_failure' => [
                    'provider' => $result['provider'] ?? null,
                    'transport' => $result['transport'] ?? null,
                    'upstream_url' => $result['upstream_url'] ?? null,
                    'http_status' => $result['http_status'] ?? null,
                    'redirect_location' => $result['redirect_location'] ?? null,
                    'response_body' => $result['response_body'] ?? null,
                    'provider_response' => $result['provider_response'] ?? null,
                ],
            ]),
        ])->save();

        $this->paymentAttemptService->record($payment, 'retry_stk', 'failed', [
            'provider' => $result['provider'] ?? 'django_stk',
            'error_code' => $result['http_status'] ? 'upstream_http_' . $result['http_status'] : 'upstream_error',
            'error_message' => $result['message'] ?? 'STK push could not be initiated.',
            'http_status' => $result['http_status'] ?? null,
            'latency_ms' => $latencyMs,
            'request_meta' => $requestMeta,
            'response_meta' => [
                'provider_environment' => $result['provider_environment'] ?? null,
                'transport' => $result['transport'] ?? null,
                'upstream_url' => $result['upstream_url'] ?? null,
                'redirect_location' => $result['redirect_location'] ?? null,
                'response_body' => $result['response_body'] ?? null,
                'provider_response' => $result['provider_response'] ?? null,
            ],
            'created_by' => optional($request->user())->id,
        ]);

        $this->auditService->fromRequest(
            $request,
            (int) $payment->platform_id,
            CrmAuditAction::PAYMENT_RETRY_STK,
            'payment',
            (int) $payment->id,
            ['before_status' => $beforeStatus],
            [
                'after_status' => 'failed',
                'provider' => $result['provider'] ?? null,
                'provider_environment' => $result['provider_environment'] ?? null,
                'transport' => $result['transport'] ?? null,
                'upstream_url' => $result['upstream_url'] ?? null,
                'http_status' => $result['http_status'] ?? null,
                'redirect_location' => $result['redirect_location'] ?? null,
                'response_body' => $result['response_body'] ?? null,
                'provider_response' => $result['provider_response'] ?? null,
            ],
            (string) ($validated['reason'] ?? 'Retry STK from CRM')
        );

        return response()->json([
            'message' => $result['message'] ?? 'STK push could not be initiated.',
        ], 400);
    }

    /**
     * Send payment link via SMS (and optionally other channels later).
     * Link is built from platform site URL + config path so customer can complete payment.
     */
    public function sendPaymentLink(Request $request, Payment $payment)
    {
        $this->authorizePaymentAccess($request, $payment);

        $validated = $request->validate([
            'channel' => 'required|in:sms',
            'phone' => 'nullable|string|max:20',
            'provider' => 'nullable|string|max:50',
            'reason' => 'nullable|string|max:500',
        ]);

        if (!in_array($payment->status, ['failed', 'initiated', 'pending'], true)) {
            return response()->json([
                'message' => 'Payment link can only be sent for failed, initiated, or pending payments.',
            ], 422);
        }

        $sendResult = $this->paymentLinkService->sendLink($payment, [
            'request' => $request,
            'channel' => $validated['channel'],
            'phone' => $validated['phone'] ?? null,
            'provider' => $validated['provider'] ?? null,
            'reason' => (string) ($validated['reason'] ?? 'Send payment link from CRM'),
            'notification_purpose' => 'payment_link',
            'success_message' => 'Payment link sent by SMS.',
            'disabled_message' => 'Payment link message prepared (SMS is disabled in settings).',
        ]);

        if (!($sendResult['success'] ?? false)) {
            return response()->json([
                'message' => $sendResult['message'] ?? 'SMS could not be sent.',
            ], (int) ($sendResult['http_status'] ?? 500));
        }

        return response()->json([
            'message' => $sendResult['message'],
            'payment' => $payment->fresh(['platform', 'product']),
        ]);
    }

    private function resolveFailureStage(Payment $payment, $latestFailedAttempt, $latestAttempt, $manualCloseMeta, ?array $linkProxy = null): string
    {
        if (is_array($manualCloseMeta)) {
            return 'manually_closed';
        }

        if ($latestFailedAttempt) {
            return match ($latestFailedAttempt->attempt_type) {
                'retry_stk' => 'stk_initiation',
                'send_payment_link' => 'link_delivery',
                'callback_update' => 'callback_processing',
                'provider_status_check', 'reconciliation_check' => 'provider_verification',
                default => $latestFailedAttempt->attempt_type,
            };
        }

        if (is_array($linkProxy)) {
            if (($linkProxy['token_status'] ?? null) === 'expired') {
                return 'proxy_link_expired';
            }

            if ($payment->status === 'failed' && !empty($linkProxy['initialized_at'])) {
                return 'provider_checkout_failed';
            }

            if (!empty($linkProxy['initialized_at'])) {
                return $payment->status === 'completed'
                    ? 'provider_callback_completed'
                    : 'provider_checkout_pending';
            }

            if (!empty($linkProxy['opened_at']) || ((int) ($linkProxy['open_count'] ?? 0)) > 0) {
                return 'proxy_link_opened';
            }

            if (!empty($linkProxy['sent_at'])) {
                return 'proxy_link_sent';
            }
        }

        if ($payment->status === 'pending') {
            return 'awaiting_callback';
        }

        if ($payment->status === 'initiated') {
            return 'initiation_queued';
        }

        if ($payment->status === 'failed') {
            return 'failed_without_attempt_telemetry';
        }

        if ($latestAttempt) {
            return $latestAttempt->attempt_type;
        }

        return 'unknown';
    }

    private function buildRecommendations(Payment $payment, ?array $linkProxy = null): array
    {
        $ageHours = $payment->created_at
            ? now()->diffInHours($payment->created_at)
            : 0;
        $canCheckProviderStatus = is_array($linkProxy)
            && ($linkProxy['mode'] ?? null) === PaymentLinkService::MODE_PROXY_HOSTED_CHECKOUT
            && $ageHours >= 1
            && in_array((string) $payment->status, ['initiated', 'pending'], true)
            && in_array((string) $payment->provider_key, ['paystack', 'pesapal'], true)
            && (!empty($linkProxy['initialized_at']) || !empty($linkProxy['provider_reference']));

        if (in_array($payment->status, ['initiated', 'pending'], true)) {
            if ($ageHours < 1) {
                $recommendations = [
                    ['key' => 'wait_callback', 'label' => 'Wait for callback', 'description' => 'Payment is still fresh; monitor callback status first.', 'recommended' => true],
                    ['key' => 'send_link', 'label' => 'Send payment link', 'description' => 'Share a direct payment URL if customer missed the STK prompt.', 'recommended' => false],
                ];

                return $this->prependRecommendation($recommendations, $canCheckProviderStatus ? [
                    'key' => 'check_provider_status',
                    'label' => 'Check provider status',
                    'description' => 'Verify whether hosted checkout already captured payment before retrying the customer.',
                    'recommended' => false,
                ] : null);
            }
            if ($ageHours < 24) {
                $recommendations = [
                    ['key' => 'send_link', 'label' => 'Send payment link', 'description' => 'Best first recovery path for pending payments older than one hour.', 'recommended' => true],
                    ['key' => 'retry_stk', 'label' => 'Retry STK', 'description' => 'Retry STK once if customer confirms they can approve now.', 'recommended' => false],
                ];

                return $this->prependRecommendation($recommendations, $canCheckProviderStatus ? [
                    'key' => 'check_provider_status',
                    'label' => 'Check provider status',
                    'description' => 'Confirm whether the provider already shows a completed or stuck checkout session.',
                    'recommended' => true,
                ] : null);
            }
            if ($ageHours < 72) {
                $recommendations = [
                    ['key' => 'retry_stk', 'label' => 'Retry STK', 'description' => 'Retry once, then shift to manual follow-up if callback still does not arrive.', 'recommended' => true],
                    ['key' => 'send_link', 'label' => 'Send payment link', 'description' => 'Provide self-serve payment route during follow-up.', 'recommended' => false],
                    ['key' => 'manual_close', 'label' => 'Close manually', 'description' => 'Use only if customer confirms no payment should proceed.', 'recommended' => false],
                ];

                return $this->prependRecommendation($recommendations, $canCheckProviderStatus ? [
                    'key' => 'check_provider_status',
                    'label' => 'Check provider status',
                    'description' => 'Review the provider-side state before sending another reminder or retry.',
                    'recommended' => true,
                ] : null);
            }

            $recommendations = [
                ['key' => 'manual_close', 'label' => 'Close manually', 'description' => 'Payment is stale (>72h). Close with a reason category after follow-up.', 'recommended' => true],
                ['key' => 'send_link', 'label' => 'Send payment link', 'description' => 'Optional final attempt before closure.', 'recommended' => false],
            ];

            return $this->prependRecommendation($recommendations, $canCheckProviderStatus ? [
                'key' => 'check_provider_status',
                'label' => 'Check provider status',
                'description' => 'Validate the provider-side outcome before finally closing this stale checkout.',
                'recommended' => true,
            ] : null);
        }

        if ($payment->status === 'failed') {
            return [
                ['key' => 'retry_stk', 'label' => 'Retry STK', 'description' => 'Retry after validating customer phone/network readiness.', 'recommended' => true],
                ['key' => 'send_link', 'label' => 'Send payment link', 'description' => 'Fallback when STK approvals repeatedly fail.', 'recommended' => true],
            ];
        }

        if ($payment->status === 'completed' && !$payment->client_id) {
            return [
                ['key' => 'auto_match', 'label' => 'Auto-match', 'description' => 'Try phone-based matching first.', 'recommended' => true],
                ['key' => 'manual_match', 'label' => 'Manual match', 'description' => 'Pick exact client when auto-match confidence is low.', 'recommended' => true],
            ];
        }

        if ($payment->status === 'completed' && $payment->client_id && !$payment->deal_id) {
            if ($this->resolveReconciliationConfidence($payment) !== 'high') {
                return [
                    ['key' => 'manual_match', 'label' => 'Confirm reconciliation', 'description' => 'Resolve to high confidence before creating subscription.', 'recommended' => true],
                    ['key' => 'manual_review', 'label' => 'Manual review', 'description' => 'Mark for review when identifiers are weak or conflicting.', 'recommended' => false],
                ];
            }

            return [
                ['key' => 'create_subscription', 'label' => 'Create subscription', 'description' => 'Link this payment to a new active subscription.', 'recommended' => true],
            ];
        }

        return [];
    }

    private function prependRecommendation(array $recommendations, ?array $recommendation): array
    {
        if (!$recommendation) {
            return $recommendations;
        }

        $filtered = array_values(array_filter($recommendations, function (array $item) use ($recommendation) {
            return ($item['key'] ?? null) !== ($recommendation['key'] ?? null);
        }));

        array_unshift($filtered, $recommendation);

        return $filtered;
    }

    private function buildLinkProxyDiagnostics(Payment $payment, $attempts, $auditEntries): ?array
    {
        $linkProxy = is_array(data_get($payment->payment_data, 'link_proxy'))
            ? data_get($payment->payment_data, 'link_proxy')
            : null;

        if (!is_array($linkProxy)) {
            return null;
        }

        $sendLinkAttemptCount = max(
            (int) $attempts->where('attempt_type', 'send_payment_link')->count(),
            (int) $auditEntries->where('action', CrmAuditAction::PAYMENT_SEND_LINK)->count()
        );
        $rotationCount = max(0, $sendLinkAttemptCount - 1);
        $tokenExpiresAt = $this->safeDateTimeString($linkProxy['token_expires_at'] ?? null);
        $latestProviderCheck = $attempts->first(function ($attempt) {
            return in_array($attempt->attempt_type, ['provider_status_check', 'reconciliation_check', 'sandbox_reconcile'], true);
        });

        return [
            'mode' => $linkProxy['mode'] ?? PaymentLinkService::MODE_PROXY_HOSTED_CHECKOUT,
            'provider_key' => $linkProxy['provider_key'] ?? $payment->provider_key,
            'provider_config_key' => $linkProxy['provider_config_key'] ?? null,
            'environment' => $linkProxy['environment'] ?? $payment->provider_environment,
            'token_status' => $this->resolveLinkProxyTokenStatus($linkProxy, $rotationCount),
            'token_expires_at' => $tokenExpiresAt,
            'rotation_count' => $rotationCount,
            'sent_at' => $this->safeDateTimeString($linkProxy['sent_at'] ?? null),
            'opened_at' => $this->safeDateTimeString($linkProxy['opened_at'] ?? null),
            'open_count' => (int) ($linkProxy['open_count'] ?? 0),
            'initialized_at' => $this->safeDateTimeString($linkProxy['initialized_at'] ?? null),
            'redirect_url' => $linkProxy['redirect_url'] ?? null,
            'provider_reference' => $linkProxy['provider_reference'] ?? $payment->transaction_reference ?? null,
            'callback_at' => $payment->completed_at?->toDateTimeString(),
            'session_status' => $this->resolveLinkProxySessionStatus($payment, $linkProxy),
            'last_provider_check' => $latestProviderCheck ? [
                'status' => $latestProviderCheck->status,
                'message' => $latestProviderCheck->error_message,
                'checked_at' => optional($latestProviderCheck->created_at)->toDateTimeString(),
            ] : null,
        ];
    }

    private function resolveLinkProxyTokenStatus(array $linkProxy, int $rotationCount): string
    {
        $expiresAt = $linkProxy['token_expires_at'] ?? null;
        if ($expiresAt) {
            try {
                if (now()->greaterThan(Carbon::parse((string) $expiresAt))) {
                    return 'expired';
                }
            } catch (\Throwable) {
            }
        }

        return $rotationCount > 0 ? 'rotated' : 'active';
    }

    private function resolveLinkProxySessionStatus(Payment $payment, array $linkProxy): string
    {
        if ($payment->status === 'completed') {
            return 'completed';
        }

        if ($payment->status === 'failed' && !empty($linkProxy['initialized_at'])) {
            return 'failed';
        }

        if (!empty($linkProxy['initialized_at'])) {
            return 'checkout_initialized';
        }

        if (!empty($linkProxy['opened_at']) || ((int) ($linkProxy['open_count'] ?? 0)) > 0) {
            return 'opened';
        }

        if (($this->resolveLinkProxyTokenStatus($linkProxy, 0)) === 'expired') {
            return 'expired';
        }

        return 'sent';
    }

    private function safeDateTimeString($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function verifyProviderStatus(Payment $payment): array
    {
        $payment->loadMissing(['platform', 'client', 'product']);
        $provider = strtolower(trim((string) $payment->provider_key));

        if (!in_array($provider, ['paystack', 'pesapal'], true)) {
            throw new InvalidArgumentException('Live provider checks are available only for Paystack and Pesapal payments.');
        }

        $linkProxy = is_array(data_get($payment->payment_data, 'link_proxy'))
            ? data_get($payment->payment_data, 'link_proxy')
            : null;
        if (is_array($linkProxy)
            && ($linkProxy['mode'] ?? null) === PaymentLinkService::MODE_PROXY_HOSTED_CHECKOUT
            && empty($linkProxy['initialized_at'])
        ) {
            throw new InvalidArgumentException('Hosted checkout has not been initialized yet for this payment.');
        }

        $context = $this->billingModeService->providerContext(
            $payment->platform,
            $provider,
            requireEnabled: false,
            environmentOverride: $payment->provider_environment
        );

        $result = match ($provider) {
            'paystack' => $this->hostedCheckoutService->verifyPaystackTransaction(
                $payment,
                $context,
                (string) $payment->reference_number
            ),
            'pesapal' => $this->hostedCheckoutService->verifyPesapalTransaction(
                $payment,
                $context,
                $this->resolvePesapalTrackingId($payment)
            ),
            default => throw new InvalidArgumentException('Live provider checks are available only for Paystack and Pesapal payments.'),
        };

        return [
            'payment_id' => (int) $payment->id,
            'provider' => $provider,
            'provider_environment' => $payment->provider_environment,
            'provider_reference' => $provider === 'pesapal'
                ? $this->resolvePesapalTrackingId($payment)
                : ($payment->transaction_reference ?: $payment->reference_number),
            'status' => (string) ($result['status'] ?? 'failed'),
            'message' => $result['message'] ?? null,
            'checked_at' => now()->toDateTimeString(),
            'data' => is_array($result['data'] ?? null) ? $result['data'] : [],
        ];
    }

    private function resolvePesapalTrackingId(Payment $payment): string
    {
        $trackingId = trim((string) (
            $payment->transaction_reference
            ?? data_get($payment->raw_payload, 'pesapal.order_tracking_id')
            ?? data_get($payment->payment_data, 'link_proxy.provider_reference')
            ?? ''
        ));

        if ($trackingId === '') {
            throw new InvalidArgumentException('Pesapal status checks require an initialized provider reference.');
        }

        return $trackingId;
    }

    private function assertSandboxReconcileEligibility(Payment $payment): void
    {
        if (strtolower(trim((string) $payment->source)) !== 'gateway') {
            throw new InvalidArgumentException('Sandbox reconcile is available only for gateway payments.');
        }

        if (strtolower(trim((string) $payment->provider_environment)) !== 'sandbox' && !data_get($payment->payment_data, 'test_mode')) {
            throw new InvalidArgumentException('Sandbox reconcile is available only for sandbox payments.');
        }

        if (!in_array(strtolower(trim((string) $payment->provider_key)), ['paystack', 'pesapal'], true)) {
            throw new InvalidArgumentException('Sandbox reconcile is available only for Paystack and Pesapal hosted checkout payments.');
        }

        if (!in_array((string) $payment->status, ['initiated', 'pending'], true) && !$this->isSandboxReconcileTerminal($payment)) {
            throw new InvalidArgumentException('Only initiated or pending sandbox payments can be reconciled.');
        }
    }

    private function isSandboxReconcileTerminal(Payment $payment): bool
    {
        return (bool) data_get($payment->payment_data, 'test_mode', false)
            && in_array((string) $payment->status, ['completed', 'failed'], true);
    }

    private function sandboxReconcileState(Payment $payment): array
    {
        return [
            'status' => $payment->status,
            'transaction_reference' => $payment->transaction_reference,
            'failure_reason' => $payment->failure_reason,
            'test_mode' => (bool) data_get($payment->payment_data, 'test_mode', false),
            'test_result' => data_get($payment->payment_data, 'test_result'),
            'side_effects_skipped' => (bool) data_get($payment->payment_data, 'side_effects_skipped', false),
        ];
    }

    private function authorizePaymentAccess(Request $request, Payment $payment): void
    {
        if ($payment->platform_id && !$this->marketAuthorizationService->userCanAccessPlatform($request->user(), (int) $payment->platform_id)) {
            abort(403, 'You do not have access to this payment market.');
        }
    }

    public function mpesaReview(Request $request)
    {
        $platformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($request);

        $perPage = min(max((int) ($request->query('per_page') ?: 50), 10), 200);
        $search = trim((string) ($request->query('search') ?? ''));
        $confidenceFilter = trim((string) ($request->query('confidence') ?? ''));

        $query = Payment::query()
            ->with(['platform:id,name,phone_prefix,currency_code', 'client:id,name,phone_normalized,profile_status,wp_post_id'])
            ->where('source', 'mpesa_xml_import')
            ->whereNotNull('client_id')
            ->whereNull('deal_id')
            ->whereIn('platform_id', $platformIds);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('phone', 'like', "%{$search}%")
                    ->orWhere('transaction_reference', 'like', "%{$search}%")
                    ->orWhereHas('client', fn($cq) => $cq->where('name', 'like', "%{$search}%"));
            });
        }

        if (in_array($confidenceFilter, ['high', 'medium', 'low'], true)) {
            $query->where('reconciliation_confidence', $confidenceFilter);
        }

        $payments = $query->orderByDesc('created_at')->paginate($perPage);

        $matchingService = app(PaymentMatchingService::class);
        $payments->getCollection()->transform(function (Payment $payment) use ($matchingService) {
            $data = $payment->toArray();
            $data['product_estimates'] = $matchingService->estimateProductByAmount(
                (float) $payment->amount,
                $payment->platform_id ? (int) $payment->platform_id : null,
                $payment->currency
            );
            $senderName = null;
            if (is_array($payment->raw_payload)) {
                $normalizedRow = $payment->raw_payload['normalized_row'] ?? [];
                $senderName = $normalizedRow['sender_name'] ?? null;
            }
            $data['sender_name'] = $senderName;
            return $data;
        });

        $totalReview = Payment::query()
            ->where('source', 'mpesa_xml_import')
            ->whereNotNull('client_id')
            ->whereNull('deal_id')
            ->whereIn('platform_id', $platformIds)
            ->count();

        return response()->json([
            'data' => $payments->items(),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
                'total_review' => $totalReview,
            ],
        ]);
    }

    public function mpesaConfirmSubscriptions(Request $request)
    {
        $validated = $request->validate([
            'selections' => 'required|array|min:1|max:500',
            'selections.*.payment_id' => 'required|integer|exists:payments,id',
            'selections.*.product_id' => 'required|integer|exists:products,id',
            'selections.*.duration_key' => 'required|string|in:weekly,biweekly,monthly',
            'reason' => 'nullable|string|max:500',
        ]);

        $actorId = (int) $request->user()->id;
        $reason = $validated['reason'] ?? 'MPESA subscription confirmation';
        $created = 0;
        $createdActive = 0;
        $createdExpired = 0;
        $skipped = 0;
        $failed = 0;
        $dealIds = [];

        $durationDays = [
            'weekly' => 7,
            'biweekly' => 14,
            'monthly' => 30,
        ];

        foreach ($validated['selections'] as $selection) {
            $payment = Payment::find($selection['payment_id']);
            if (!$payment || $payment->deal_id) {
                $skipped += 1;
                continue;
            }

            if (!$payment->client_id) {
                $failed += 1;
                continue;
            }

            $this->authorizePaymentAccess($request, $payment);

            $product = Product::find($selection['product_id']);
            if (!$product) {
                $failed += 1;
                continue;
            }

            $durKey = $selection['duration_key'];
            $days = $durationDays[$durKey] ?? 30;

            $planType = 'basic';
            $nameLower = strtolower((string) $product->name);
            if (str_contains($nameLower, 'vip')) {
                $planType = 'vip';
            } elseif (str_contains($nameLower, 'premium')) {
                $planType = 'premium';
            }

            try {
                $activatedAt = $payment->created_at ? \Carbon\Carbon::parse($payment->created_at) : now();
                $expiresAt = $activatedAt->copy()->addDays($days);

                // Derive correct status: if expiry is in the past, deal is expired
                $status = $expiresAt->isPast() ? 'expired' : 'active';

                // Pause renewal reminders for expired MPESA imports to prevent campaign targeting
                $pauseReminders = ($status === 'expired');

                $deal = Deal::create([
                    'platform_id' => (int) $payment->platform_id,
                    'client_id' => (int) $payment->client_id,
                    'payment_id' => (int) $payment->id,
                    'product_id' => $product->id,
                    'plan_type' => $planType,
                    'amount' => (float) $payment->amount,
                    'currency' => $product->currency ?: ($payment->currency ?: 'KES'),
                    'duration' => $durKey,
                    'status' => $status,
                    'activated_at' => $activatedAt,
                    'expires_at' => $expiresAt,
                    'assigned_to' => $actorId,
                    'origin' => 'mpesa_import',
                    'payment_reference' => $payment->transaction_reference,
                    'renewal_reminders_paused' => $pauseReminders,
                ]);

                $payment->update([
                    'deal_id' => $deal->id,
                    'match_confidence' => $payment->match_confidence ?: 'manual',
                    'confirmed_by' => $actorId,
                    'confirmed_at' => now(),
                    'reconciliation_state' => 'resolved',
                ]);

                $dealIds[] = $deal->id;
                $created += 1;
                if ($status === 'active') {
                    $createdActive += 1;
                } else {
                    $createdExpired += 1;
                }
            } catch (\Throwable $e) {
                Log::error('MPESA subscription confirm failed', [
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ]);
                $failed += 1;
            }
        }

        if ($created > 0) {
            $this->auditService->fromRequest(
                $request,
                null,
                CrmAuditAction::PAYMENT_MPESA_CONFIRM_SUBSCRIPTION,
                'deal',
                $dealIds[0] ?? 0,
                null,
                ['created' => $created, 'skipped' => $skipped, 'failed' => $failed],
                $reason
            );
        }

        return response()->json([
            'created' => $created,
            'created_active' => $createdActive,
            'created_expired' => $createdExpired,
            'skipped' => $skipped,
            'failed' => $failed,
            'deal_ids' => $dealIds,
        ]);
    }

    public function importCandidates(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'required|integer|exists:platforms,id',
            'search' => 'required|string|min:2|max:120',
        ]);

        $platformId = (int) $validated['platform_id'];
        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            $platformId,
            'You do not have access to this payment market.'
        );

        $search = trim($validated['search']);
        $candidates = Client::where('platform_id', $platformId)
            ->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('phone_normalized', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
                if (ctype_digit($search)) {
                    $builder->orWhere('id', (int) $search)
                        ->orWhere('wp_post_id', (int) $search)
                        ->orWhere('wp_user_id', (int) $search);
                }
            })
            ->select(['id', 'wp_post_id', 'wp_user_id', 'name', 'phone_normalized', 'email', 'city', 'profile_status', 'premium', 'featured', 'verified'])
            ->orderBy('name')
            ->limit(25)
            ->get();

        return response()->json(['data' => $candidates]);
    }

    public function updateImportRowMatch(Request $request)
    {
        $validated = $request->validate([
            'row_id' => 'required|integer|exists:payment_import_rows,id',
            'client_id' => 'required|integer|exists:clients,id',
        ]);

        $row = PaymentImportRow::with('batch')->findOrFail((int) $validated['row_id']);
        $batch = $row->batch;

        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            (int) $batch->platform_id,
            'You do not have access to this payment market.'
        );

        if ($batch->status === 'committed') {
            return response()->json(['message' => 'Batch already committed.'], 422);
        }

        $client = Client::findOrFail((int) $validated['client_id']);
        if ((int) $client->platform_id !== (int) $batch->platform_id) {
            return response()->json(['message' => 'Client does not belong to this market.'], 422);
        }

        $row->update([
            'suggested_match' => [
                'confidence' => 'manual',
                'basis' => 'manual_import_match',
                'client_id' => $client->id,
                'client_name' => $client->name,
            ],
        ]);

        return response()->json([
            'row_id' => $row->id,
            'suggested_match' => $row->fresh()->suggested_match,
        ]);
    }

    private function resolveReconciliationConfidence(Payment $payment): string
    {
        $current = trim((string) ($payment->reconciliation_confidence ?? ''));
        if (in_array($current, ['high', 'medium', 'low'], true)) {
            return $current;
        }

        return match ($payment->match_confidence) {
            'manual', 'auto_high' => 'high',
            'auto_low' => 'medium',
            default => 'low',
        };
    }
}
