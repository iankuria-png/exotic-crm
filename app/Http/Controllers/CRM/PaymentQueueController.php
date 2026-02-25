<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Payment;
use App\Models\Client;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\PaymentMatchingService;
use App\Services\MarketAuthorizationService;
use App\Support\CrmAuditAction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaymentQueueController extends Controller
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly AuditService $auditService,
        private readonly NotificationService $notificationService
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

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

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

        $statsQuery = clone $query;
        $stats = [
            'total' => (clone $statsQuery)->count(),
            'pending' => (clone $statsQuery)->where('status', 'initiated')->count(),
            'confirmed' => (clone $statsQuery)->where('status', 'completed')->count(),
            'failed' => (clone $statsQuery)->where('status', 'failed')->count(),
            'matched' => (clone $statsQuery)->whereNotNull('client_id')->count(),
            'unmatched' => (clone $statsQuery)->whereNull('client_id')->count(),
            'unmatched_review' => (clone $statsQuery)->where('status', 'completed')->whereNull('client_id')->count(),
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

        if (!in_array($payment->status, ['failed', 'initiated'], true)) {
            return response()->json([
                'message' => 'Only failed or initiated payments can be retried.',
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

        $response = Http::timeout(30)->post("{$baseUrl}/initiate/", $payload);

        if ($response->successful()) {
            $data = $response->json();
            if (isset($data['message']) && $data['message'] === 'Payment initiated') {
                $payment->status = 'pending';
                $payment->save();

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

        if (!in_array($payment->status, ['failed', 'initiated'], true)) {
            return response()->json([
                'message' => 'Payment link can only be sent for failed or initiated payments.',
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

        $result = $this->notificationService->sendSms($phone, $message, [
            'purpose' => 'payment_link',
            'payment_id' => $payment->id,
            'platform_id' => $payment->platform_id,
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
