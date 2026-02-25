<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Payment;
use App\Models\Client;
use App\Models\AuditLog;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\PaymentMatchingService;
use App\Services\PaymentAttemptService;
use App\Services\MarketAuthorizationService;
use App\Support\CrmAuditAction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

class PaymentQueueController extends Controller
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly AuditService $auditService,
        private readonly NotificationService $notificationService,
        private readonly PaymentAttemptService $paymentAttemptService
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

        if ($request->filled('match_confidence')) {
            $query->where('match_confidence', $request->match_confidence);
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

        $phone = $this->normalizePhone($payment->phone);
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

        $service = new PaymentMatchingService();
        $result = $service->matchPayment($payment);
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
            && !$payment->deal_id;

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

        $service = new PaymentMatchingService();
        $beforeState = [
            'deal_id' => $payment->deal_id,
            'client_id' => $payment->client_id,
            'status' => $payment->status,
        ];

        $deal = $service->createDealFromPayment($payment, (int) $request->user()->id);
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

        $accessiblePlatformIds = null;

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
            ])
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        $latestFailedAttempt = $attempts->first(fn($attempt) => $attempt->status === 'failed');
        $latestAttempt = $attempts->first();
        $rawPayload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $manualCloseMeta = $rawPayload['manual_close'] ?? null;

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

        $failureStage = $this->resolveFailureStage($payment, $latestFailedAttempt, $latestAttempt, $manualCloseMeta);
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
            'recommendations' => $this->buildRecommendations($payment),
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

        $phone = $this->normalizePhone($payment->phone);
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

        $baseUrl = rtrim((string) config('services.django.base_url'), '/');
        if ($baseUrl === '') {
            Log::error('Retry STK: Django base URL not configured.');
            return response()->json([
                'message' => 'Payment service URL is not configured.',
            ], 503);
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

        $payload = [
            'organization_code' => '76',
            'payment_id' => $payment->id,
            'product_id' => $payment->product_id,
            'platform_id' => $payment->platform_id,
            'user_id' => $payment->user_id,
            'phone' => $phone,
            'amount' => $amount,
            'duration' => $duration,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
        ];

        $requestMeta = $this->paymentAttemptService->requestMetaFromRequest($request, [
            'channel' => 'stk',
            'phone' => $phone,
            'amount' => $amount,
            'duration' => $duration,
        ]);
        $attemptStartedAt = microtime(true);
        $response = Http::timeout(30)->post("{$baseUrl}/initiate/", $payload);
        $latencyMs = (int) round((microtime(true) - $attemptStartedAt) * 1000);

        if ($response->successful()) {
            $data = $response->json();
            if (isset($data['message']) && $data['message'] === 'Payment initiated') {
                $payment->status = 'pending';
                $payment->save();

                $this->paymentAttemptService->record($payment, 'retry_stk', 'success', [
                    'provider' => 'django_stk',
                    'http_status' => $response->status(),
                    'latency_ms' => $latencyMs,
                    'request_meta' => $requestMeta,
                    'response_meta' => [
                        'message' => $data['message'] ?? null,
                        'payment_id' => $data['payment_id'] ?? null,
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
                    ['after_status' => 'pending', 'django_response' => $data],
                    (string) ($validated['reason'] ?? 'Retry STK from CRM')
                );

                return response()->json([
                    'message' => 'STK push sent. Customer should complete the request on their phone.',
                    'payment' => $payment->fresh(['platform', 'product']),
                ]);
            }

            Log::warning('Retry STK: Django returned non-success message', ['data' => $data]);
            $payment->status = 'failed';
            $payment->save();

            $this->paymentAttemptService->record($payment, 'retry_stk', 'failed', [
                'provider' => 'django_stk',
                'error_code' => $data['code'] ?? $data['error_code'] ?? null,
                'error_message' => $data['error'] ?? $data['message'] ?? 'STK push could not be initiated.',
                'http_status' => $response->status(),
                'latency_ms' => $latencyMs,
                'request_meta' => $requestMeta,
                'response_meta' => is_array($data) ? $data : ['response' => $data],
                'created_by' => optional($request->user())->id,
            ]);

            $this->auditService->fromRequest(
                $request,
                (int) $payment->platform_id,
                CrmAuditAction::PAYMENT_RETRY_STK,
                'payment',
                (int) $payment->id,
                ['before_status' => $beforeStatus],
                ['after_status' => 'failed', 'django_response' => $data],
                (string) ($validated['reason'] ?? 'Retry STK from CRM')
            );

            return response()->json([
                'message' => $data['error'] ?? $data['message'] ?? 'STK push could not be initiated.',
            ], 400);
        }

        Log::error('Retry STK: Django HTTP error', [
            'payment_id' => $payment->id,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);
        $payment->status = 'failed';
        $payment->save();

        $this->paymentAttemptService->record($payment, 'retry_stk', 'failed', [
            'provider' => 'django_stk',
            'error_code' => 'http_error',
            'error_message' => 'Payment service unavailable.',
            'http_status' => $response->status(),
            'latency_ms' => $latencyMs,
            'request_meta' => $requestMeta,
            'response_meta' => [
                'body' => mb_substr($response->body(), 0, 2000),
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
            ['after_status' => 'failed', 'http_status' => $response->status()],
            (string) ($validated['reason'] ?? 'Retry STK from CRM')
        );

        return response()->json([
            'message' => 'Payment service unavailable. Please try again later.',
        ], 502);
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

        $payment->load(['product', 'platform']);
        $platform = $payment->platform;

        if (!$platform) {
            return response()->json([
                'message' => 'Payment has no platform.',
            ], 422);
        }

        $phone = $this->normalizePhone($validated['phone'] ?? $payment->phone);
        if (!$phone) {
            return response()->json([
                'message' => 'No valid phone number to send the link to.',
            ], 422);
        }

        $paymentUrl = $this->buildPaymentLinkUrl($platform, $validated['provider'] ?? null);
        if (!$paymentUrl) {
            return response()->json([
                'message' => 'Payment page URL could not be determined for this market.',
            ], 422);
        }

        $amount = (float) $payment->amount;
        $currency = $payment->currency ?? 'KES';
        $message = sprintf(
            'Complete your payment of %s %s here: %s',
            $currency,
            number_format($amount),
            $paymentUrl
        );

        $requestMeta = $this->paymentAttemptService->requestMetaFromRequest($request, [
            'channel' => $validated['channel'],
            'requested_provider' => $validated['provider'] ?? null,
            'phone' => $phone,
        ]);
        $attemptStartedAt = microtime(true);
        $result = $this->notificationService->sendSms($phone, $message, [
            'purpose' => 'payment_link',
            'payment_id' => $payment->id,
            'platform_id' => $payment->platform_id,
        ]);
        $latencyMs = (int) round((microtime(true) - $attemptStartedAt) * 1000);

        $attemptStatus = ($result['success'] ?? false) === true
            ? (($result['status'] ?? '') === 'disabled' ? 'disabled' : 'success')
            : 'failed';

        $this->paymentAttemptService->record($payment, 'send_payment_link', $attemptStatus, [
            'provider' => $result['provider'] ?? ($validated['provider'] ?? 'payment_link'),
            'error_code' => ($result['success'] ?? false) === true ? null : 'sms_send_failed',
            'error_message' => ($result['success'] ?? false) === true ? null : ($result['provider_response'] ?? 'SMS could not be sent.'),
            'latency_ms' => $latencyMs,
            'request_meta' => $requestMeta,
            'response_meta' => [
                'sms_status' => $result['status'] ?? null,
                'provider_response' => $result['provider_response'] ?? null,
                'payment_url' => $paymentUrl,
            ],
            'created_by' => optional($request->user())->id,
        ]);

        $this->auditService->fromRequest(
            $request,
            (int) $payment->platform_id,
            CrmAuditAction::PAYMENT_SEND_LINK,
            'payment',
            (int) $payment->id,
            [
                'channel' => $validated['channel'],
                'phone' => $phone,
                'provider' => $validated['provider'] ?? null,
            ],
            [
                'sms_success' => $result['success'] ?? false,
                'sms_status' => $result['status'] ?? null,
                'provider' => $validated['provider'] ?? null,
            ],
            (string) ($validated['reason'] ?? 'Send payment link from CRM')
        );

        if ($result['success'] !== true && ($result['status'] ?? '') !== 'disabled') {
            return response()->json([
                'message' => $result['provider_response'] ?? 'SMS could not be sent.',
            ], 502);
        }

        return response()->json([
            'message' => $result['status'] === 'disabled'
                ? 'Payment link message prepared (SMS is disabled in settings).'
                : 'Payment link sent by SMS.',
            'payment' => $payment->fresh(['platform', 'product']),
        ]);
    }

    private function resolveFailureStage(Payment $payment, $latestFailedAttempt, $latestAttempt, $manualCloseMeta): string
    {
        if (is_array($manualCloseMeta)) {
            return 'manually_closed';
        }

        if ($latestFailedAttempt) {
            return match ($latestFailedAttempt->attempt_type) {
                'retry_stk' => 'stk_initiation',
                'send_payment_link' => 'link_delivery',
                'callback_update' => 'callback_processing',
                default => $latestFailedAttempt->attempt_type,
            };
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

    private function buildRecommendations(Payment $payment): array
    {
        $ageHours = $payment->created_at
            ? now()->diffInHours($payment->created_at)
            : 0;

        if (in_array($payment->status, ['initiated', 'pending'], true)) {
            if ($ageHours < 1) {
                return [
                    ['key' => 'wait_callback', 'label' => 'Wait for callback', 'description' => 'Payment is still fresh; monitor callback status first.', 'recommended' => true],
                    ['key' => 'send_link', 'label' => 'Send payment link', 'description' => 'Share a direct payment URL if customer missed the STK prompt.', 'recommended' => false],
                ];
            }
            if ($ageHours < 24) {
                return [
                    ['key' => 'send_link', 'label' => 'Send payment link', 'description' => 'Best first recovery path for pending payments older than one hour.', 'recommended' => true],
                    ['key' => 'retry_stk', 'label' => 'Retry STK', 'description' => 'Retry STK once if customer confirms they can approve now.', 'recommended' => false],
                ];
            }
            if ($ageHours < 72) {
                return [
                    ['key' => 'retry_stk', 'label' => 'Retry STK', 'description' => 'Retry once, then shift to manual follow-up if callback still does not arrive.', 'recommended' => true],
                    ['key' => 'send_link', 'label' => 'Send payment link', 'description' => 'Provide self-serve payment route during follow-up.', 'recommended' => false],
                    ['key' => 'manual_close', 'label' => 'Close manually', 'description' => 'Use only if customer confirms no payment should proceed.', 'recommended' => false],
                ];
            }

            return [
                ['key' => 'manual_close', 'label' => 'Close manually', 'description' => 'Payment is stale (>72h). Close with a reason category after follow-up.', 'recommended' => true],
                ['key' => 'send_link', 'label' => 'Send payment link', 'description' => 'Optional final attempt before closure.', 'recommended' => false],
            ];
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
            return [
                ['key' => 'create_subscription', 'label' => 'Create subscription', 'description' => 'Link this payment to a new active subscription.', 'recommended' => true],
            ];
        }

        return [];
    }

    private function buildPaymentLinkUrl($platform, ?string $requestedProvider = null): ?string
    {
        if (is_array($platform->payment_link_providers)) {
            $configuredProvider = trim((string) ($platform->payment_link_providers['active_provider'] ?? ''));
            $activeProvider = trim((string) ($requestedProvider ?: $configuredProvider));
            $providers = $platform->payment_link_providers['providers'] ?? [];

            if ($activeProvider !== '' && is_array($providers) && isset($providers[$activeProvider]) && is_array($providers[$activeProvider])) {
                $provider = $providers[$activeProvider];
                $directUrl = rtrim(trim((string) ($provider['url'] ?? '')), '/');
                if ($directUrl !== '') {
                    return $directUrl;
                }

                $baseUrl = rtrim(trim((string) ($provider['base_url'] ?? '')), '/');
                if ($baseUrl !== '') {
                    $path = trim((string) ($provider['path'] ?? config('services.payment_link.path', '/pay')));
                    if ($path === '') {
                        $path = '/pay';
                    }
                    if (!str_starts_with($path, '/')) {
                        $path = '/' . $path;
                    }

                    return $baseUrl . $path;
                }
            }
        }

        $baseUrl = null;

        if (!empty($platform->wp_api_url)) {
            $baseUrl = preg_replace('#/wp-json/.*$#', '', (string) $platform->wp_api_url);
            $baseUrl = rtrim((string) $baseUrl, '/');
        }

        if (!$baseUrl && !empty($platform->domain)) {
            $domain = trim((string) $platform->domain);
            $baseUrl = str_starts_with($domain, 'http') ? $domain : 'https://' . $domain;
            $baseUrl = rtrim($baseUrl, '/');
        }

        if ($baseUrl === '' || $baseUrl === null) {
            return null;
        }

        $path = config('services.payment_link.path', '/pay');

        return $baseUrl . $path;
    }

    private function normalizePhone(?string $phone): ?string
    {
        if (!$phone) {
            return null;
        }

        $phone = preg_replace('/[^\d+]/', '', $phone);
        $phone = ltrim($phone, '+');

        if (str_starts_with($phone, '0')) {
            $phone = '254' . substr($phone, 1);
        }

        return $phone ?: null;
    }

    private function authorizePaymentAccess(Request $request, Payment $payment): void
    {
        if ($payment->platform_id && !$this->marketAuthorizationService->userCanAccessPlatform($request->user(), (int) $payment->platform_id)) {
            abort(403, 'You do not have access to this payment market.');
        }
    }
}
