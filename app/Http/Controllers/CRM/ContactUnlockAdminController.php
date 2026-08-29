<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\ContactUnlockPricingRule;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\VisitorContactUnlock;
use App\Services\ContactUnlockPricingService;
use App\Services\ContactUnlockPulseService;
use App\Services\ContactUnlockQueryService;
use App\Services\ContactUnlockReadinessService;
use App\Services\MarketAuthorizationService;
use App\Services\ReportingCurrencyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ContactUnlockAdminController extends Controller
{
    public function __construct(
        private readonly ContactUnlockPricingService $pricingService,
        private readonly ContactUnlockReadinessService $readinessService,
        private readonly ContactUnlockPulseService $pulseService,
        private readonly ContactUnlockQueryService $unlockQueryService,
        private readonly MarketAuthorizationService $marketAuthorization,
        private readonly ReportingCurrencyService $reportingCurrencyService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:5|max:100',
            'platform_id' => 'nullable|integer|exists:platforms,id',
            'status' => 'nullable|string|max:40',
            'payment_status' => 'nullable|string|max:40',
            'scope' => ['nullable', Rule::in([
                VisitorContactUnlock::SCOPE_SINGLE_PROFILE,
                VisitorContactUnlock::SCOPE_MARKET_INACTIVE_PROFILES,
            ])],
            'search' => 'nullable|string|max:120',
            'sort' => ['nullable', Rule::in(['id', 'created_at', 'status', 'scope', 'amount', 'payment_status', 'visitor', 'profile', 'market'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'currency_mode' => ['nullable', Rule::in(['native', 'flat'])],
            'reporting_currency' => 'nullable|string|min:3|max:8',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $this->marketAuthorization->ensureRequestedPlatformIsAccessible($request);
        $platformIds = $this->marketAuthorization->resolveAccessiblePlatformIds($request->user());
        $canManage = $this->marketAuthorization->isManager($request->user());
        $targetCurrency = $this->reportingCurrencyService->resolveTargetCurrency($filters['reporting_currency'] ?? null);
        $currencyMode = $this->reportingCurrencyService->resolveMode($filters['currency_mode'] ?? null, true);
        $hasDateWindow = ! empty($filters['from']) || ! empty($filters['to']);

        $markets = Platform::query()
            ->where('is_active', true)
            ->when(is_array($platformIds), fn (Builder $query) => $query->whereIn('id', $platformIds))
            ->orderBy('name')
            ->get(['id', 'name', 'country', 'domain', 'currency_code', 'phone_prefix']);

        $rules = $canManage
            ? ContactUnlockPricingRule::query()
                ->with('platform:id,name,currency_code')
                ->orderBy('platform_id')
                ->orderBy('amount')
                ->get()
                ->map(fn (ContactUnlockPricingRule $rule) => $this->serializeRule($rule))
                ->values()
            : collect();

        $unlockStats = VisitorContactUnlock::query()
            ->select('status', DB::raw('COUNT(*) as aggregate_count'))
            ->when(is_array($platformIds), fn (Builder $query) => $query->whereIn('platform_id', $platformIds))
            ->when(! empty($filters['platform_id']), fn (Builder $query) => $query->where('platform_id', (int) $filters['platform_id']));
        $this->applyUnlockDateWindow($unlockStats, $filters);
        $unlockStats = $unlockStats->groupBy('status')->pluck('aggregate_count', 'status');

        $paymentQuery = Payment::query()
            ->contactUnlockRevenue()
            ->when(is_array($platformIds), fn (Builder $query) => $query->whereIn('platform_id', $platformIds))
            ->when(! empty($filters['platform_id']), fn (Builder $query) => $query->where('platform_id', (int) $filters['platform_id']));
        $this->applyPaymentDateWindow($paymentQuery, $filters);

        $confirmedQuery = (clone $paymentQuery)->whereIn('status', Payment::SUCCESSFUL_STATUSES);
        $confirmedNormalized = $this->reportingCurrencyService->normalizePaymentQuery(clone $confirmedQuery, $targetCurrency);
        $recentUnlocks = $this->recentUnlocks($filters, $platformIds);

        $totalUnlocksQuery = VisitorContactUnlock::query()
            ->when(is_array($platformIds), fn (Builder $query) => $query->whereIn('platform_id', $platformIds))
            ->when(! empty($filters['platform_id']), fn (Builder $query) => $query->where('platform_id', (int) $filters['platform_id']));
        $this->applyUnlockDateWindow($totalUnlocksQuery, $filters);

        $activeUnlocksQuery = VisitorContactUnlock::query()
            ->active()
            ->when(is_array($platformIds), fn (Builder $query) => $query->whereIn('platform_id', $platformIds))
            ->when(! empty($filters['platform_id']), fn (Builder $query) => $query->where('platform_id', (int) $filters['platform_id']));
        $this->applyUnlockDateWindow($activeUnlocksQuery, $filters);

        $payload = [
            'permissions' => ['can_manage' => $canManage],
            'summary' => [
                'window_label' => $hasDateWindow ? 'selected range' : 'all time',
                'total_unlocks' => (int) $totalUnlocksQuery->count(),
                'active_unlocks' => (int) $activeUnlocksQuery->count(),
                'pending_unlocks' => (int) ($unlockStats[VisitorContactUnlock::STATUS_PENDING_PAYMENT] ?? 0),
                'completed_payments' => (int) (clone $confirmedQuery)->count(),
                'confirmed_revenue_native' => $this->nativeRevenue((clone $confirmedQuery)),
                'confirmed_revenue_normalized' => $confirmedNormalized['normalized_total'],
                'confirmed_revenue_normalized_display' => $confirmedNormalized['normalized_display'],
                'confirmed_revenue_normalization_meta' => $confirmedNormalized['normalization_meta'],
                'normalized_currency' => $targetCurrency,
                'currency_mode' => $currencyMode,
            ],
            'markets' => $markets->map(fn (Platform $platform) => [
                'id' => (int) $platform->id,
                'name' => (string) $platform->name,
                'country' => (string) ($platform->country ?? ''),
                'domain' => (string) ($platform->domain ?? ''),
                'currency_code' => (string) ($platform->currency_code ?: ''),
                'phone_prefix' => (string) ($platform->phone_prefix ?: ''),
                'enabled' => $this->pricingService->enabledForPlatform($platform),
            ])->values(),
            'recent_unlocks' => $recentUnlocks->getCollection()
                ->map(fn (VisitorContactUnlock $unlock) => $this->serializeUnlock($unlock))
                ->values(),
            'recent_unlocks_meta' => [
                'current_page' => (int) $recentUnlocks->currentPage(),
                'last_page' => (int) $recentUnlocks->lastPage(),
                'per_page' => (int) $recentUnlocks->perPage(),
                'total' => (int) $recentUnlocks->total(),
                'from' => (int) ($recentUnlocks->firstItem() ?? 0),
                'to' => (int) ($recentUnlocks->lastItem() ?? 0),
            ],
        ];

        if ($canManage) {
            $payload['settings'] = [
                'enabled' => $this->pricingService->globallyEnabled(),
                'market_ids' => $this->pricingService->enabledMarketIds(),
                'sandbox_only' => $this->pricingService->sandboxOnly(),
            ];
            $payload['pricing_rules'] = $rules;
        }

        return response()->json($payload);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => 'required|boolean',
            'sandbox_only' => 'required|boolean',
            'market_ids' => 'array',
            'market_ids.*' => 'integer|exists:platforms,id',
            'pricing_rules' => 'array',
            'pricing_rules.*.id' => 'nullable|integer|exists:contact_unlock_pricing_rules,id',
            'pricing_rules.*.platform_id' => 'required|integer|exists:platforms,id',
            'pricing_rules.*.scope' => ['required', Rule::in([
                ContactUnlockPricingRule::SCOPE_SINGLE_PROFILE,
                ContactUnlockPricingRule::SCOPE_MARKET_INACTIVE_PROFILES,
            ])],
            'pricing_rules.*.label' => 'required|string|max:120',
            'pricing_rules.*.currency' => 'required|string|size:3',
            'pricing_rules.*.amount' => 'required|numeric|min:1|max:99999999',
            'pricing_rules.*.duration_days' => 'required|integer|min:1|max:366',
            'pricing_rules.*.is_active' => 'boolean',
        ]);

        DB::transaction(function () use ($validated, $request): void {
            $this->pricingService->updateAvailability(
                (bool) $validated['enabled'],
                $validated['market_ids'] ?? [],
                (bool) $validated['sandbox_only'],
                (int) $request->user()->id
            );

            foreach (($validated['pricing_rules'] ?? []) as $payload) {
                $attributes = [
                    'platform_id' => (int) $payload['platform_id'],
                    'scope' => (string) $payload['scope'],
                    'label' => trim((string) $payload['label']),
                    'currency' => strtoupper((string) $payload['currency']),
                    'amount' => number_format((float) $payload['amount'], 2, '.', ''),
                    'duration_days' => (int) $payload['duration_days'],
                    'is_active' => (bool) ($payload['is_active'] ?? true),
                ];

                if (! empty($payload['id'])) {
                    ContactUnlockPricingRule::query()
                        ->whereKey((int) $payload['id'])
                        ->update($attributes);
                } else {
                    ContactUnlockPricingRule::query()->create($attributes);
                }
            }
        });

        return $this->index($request);
    }

    public function destroyRule(Request $request, ContactUnlockPricingRule $rule): JsonResponse
    {
        if (VisitorContactUnlock::query()->where('pricing_rule_id', (int) $rule->id)->exists()) {
            $rule->forceFill(['is_active' => false])->save();
        } else {
            $rule->delete();
        }

        return $this->index($request);
    }

    public function readiness(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform_id' => 'nullable|integer|exists:platforms,id',
        ]);

        return response()->json($this->readinessService->check(
            ! empty($validated['platform_id']) ? (int) $validated['platform_id'] : null
        ));
    }

    public function pulse(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform_id' => 'nullable|integer|exists:platforms,id',
            'range' => ['nullable', Rule::in(['today', '7d', '30d', 'custom'])],
            'timezone' => 'nullable|string|max:80',
            'reporting_currency' => 'nullable|string|min:3|max:8',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $this->marketAuthorization->ensureRequestedPlatformIsAccessible($request);
        $platformIds = ! empty($validated['platform_id'])
            ? [(int) $validated['platform_id']]
            : $this->marketAuthorization->resolveAccessiblePlatformIds($request->user());

        return response()->json($this->pulseService->summary(
            $platformIds,
            (string) ($validated['range'] ?? 'today'),
            $validated['timezone'] ?? null,
            $validated['reporting_currency'] ?? null,
            $validated['from'] ?? null,
            $validated['to'] ?? null
        ));
    }

    private function nativeRevenue($query): array
    {
        return $query
            ->select('currency', DB::raw('SUM(amount) as aggregate_amount'), DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy('currency')
            ->get()
            ->map(fn ($row) => [
                'currency' => (string) ($row->currency ?: ''),
                'amount' => (float) $row->aggregate_amount,
                'count' => (int) $row->aggregate_count,
            ])
            ->values()
            ->all();
    }

    private function recentUnlocks(array $filters, ?array $platformIds)
    {
        return $this->unlockQueryService
            ->filtered($filters, $platformIds)
            ->paginate((int) ($filters['per_page'] ?? 10));
    }

    private function applyUnlockDateWindow(Builder $query, array $filters): void
    {
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }
    }

    private function applyPaymentDateWindow(Builder $query, array $filters): void
    {
        if (! empty($filters['from'])) {
            $query->where(DB::raw('COALESCE(completed_at, updated_at, created_at)'), '>=', Carbon::parse($filters['from'])->startOfDay());
        }

        if (! empty($filters['to'])) {
            $query->where(DB::raw('COALESCE(completed_at, updated_at, created_at)'), '<=', Carbon::parse($filters['to'])->endOfDay());
        }
    }

    private function serializeRule(ContactUnlockPricingRule $rule): array
    {
        return [
            'id' => (int) $rule->id,
            'platform_id' => (int) $rule->platform_id,
            'market_name' => (string) ($rule->platform?->name ?? ''),
            'scope' => (string) $rule->scope,
            'label' => (string) $rule->label,
            'currency' => (string) $rule->currency,
            'amount' => (float) $rule->amount,
            'duration_days' => (int) $rule->duration_days,
            'is_active' => (bool) $rule->is_active,
        ];
    }

    private function serializeUnlock(VisitorContactUnlock $unlock): array
    {
        $metadata = is_array($unlock->metadata_json) ? $unlock->metadata_json : [];
        $paymentData = is_array($unlock->payment?->payment_data) ? $unlock->payment->payment_data : [];
        $checkoutError = data_get($metadata, 'checkout_error', data_get($paymentData, 'checkout_error', []));
        $claims = $unlock->customerClaims ?? collect();
        $latestClaim = $claims->sortByDesc('id')->sortByDesc('claimed_at')->first();
        $feedback = $claims->flatMap(fn ($claim) => $claim->reachabilityFeedback ?? collect());
        $latestFeedback = $feedback->sortByDesc('id')->sortByDesc('submitted_at')->first();
        $pendingFeedback = $feedback->filter(fn ($row) => (string) $row->status === 'pending_review');

        return [
            'id' => (int) $unlock->id,
            'status' => (string) $unlock->status,
            'scope' => (string) $unlock->scope,
            'market' => (string) ($unlock->platform?->name ?? ''),
            'profile' => [
                'name' => (string) ($unlock->client?->name ?? ''),
                'wp_post_id' => (int) ($unlock->wp_post_id ?: $unlock->client?->wp_post_id),
                'url' => (string) ($unlock->client?->wp_profile_permalink ?? ''),
            ],
            'payment' => [
                'id' => (int) ($unlock->payment?->id ?? 0),
                'status' => (string) ($unlock->payment?->status ?? ''),
                'amount' => (float) ($unlock->payment?->amount ?? 0),
                'currency' => (string) ($unlock->payment?->currency ?? ''),
                'reference' => (string) ($unlock->payment?->reference_number ?? ''),
                'provider_key' => (string) ($unlock->payment?->provider_key ?? ''),
                'provider_environment' => (string) ($unlock->payment?->provider_environment ?? ''),
                'failure_reason' => (string) ($unlock->payment?->failure_reason ?? data_get($checkoutError, 'message', '')),
                'error_reference' => (string) data_get($checkoutError, 'reference', ''),
            ],
            'pricing' => [
                'gross_amount' => (float) ($unlock->gross_amount ?? $unlock->payment?->amount ?? 0),
                'credit_amount' => (float) ($unlock->credit_amount ?? 0),
                'amount_due' => (float) ($unlock->amount_due ?? $unlock->payment?->amount ?? 0),
            ],
            'visitor_phone_masked' => (string) ($unlock->visitor_phone_masked ?? ''),
            'visitor_email_masked' => (string) ($unlock->visitor_email_masked ?? ''),
            'claim_review' => [
                'claimed' => $claims->isNotEmpty(),
                'claims_count' => $claims->count(),
                'latest_claimed_at' => optional($claims->max('claimed_at'))->toIso8601String(),
                'latest_source' => (string) ($latestClaim?->source ?? ''),
                'latest_customer' => [
                    'name' => (string) ($latestClaim?->customerAccount?->display_name ?? ''),
                    'email' => (string) ($latestClaim?->customerAccount?->email ?? ''),
                ],
                'reachability_feedback_count' => $feedback->count(),
                'pending_reachability_reviews' => $pendingFeedback->count(),
                'latest_reachability_outcome' => (string) ($latestFeedback?->outcome ?? ''),
                'latest_reachability_status' => (string) ($latestFeedback?->status ?? ''),
            ],
            'visitor_context' => $this->serializeVisitorContext($metadata),
            'expires_at' => $unlock->expires_at?->toIso8601String(),
            'created_at' => $unlock->created_at?->toIso8601String(),
        ];
    }

    private function serializeVisitorContext(array $metadata): array
    {
        $context = data_get($metadata, 'visitor_context', []);
        $browser = is_array(data_get($context, 'browser')) ? data_get($context, 'browser') : [];
        $request = is_array(data_get($context, 'request')) ? data_get($context, 'request') : [];

        return [
            'locale' => (string) data_get($browser, 'locale', ''),
            'languages' => array_values(array_filter((array) data_get($browser, 'languages', []))),
            'timezone' => (string) data_get($browser, 'timezone', ''),
            'timezone_offset_minutes' => data_get($browser, 'timezone_offset_minutes'),
            'platform' => (string) (data_get($browser, 'user_agent_platform') ?: data_get($browser, 'platform', '')),
            'mobile_hint' => data_get($browser, 'mobile_hint'),
            'viewport' => [
                'width' => data_get($browser, 'viewport.width'),
                'height' => data_get($browser, 'viewport.height'),
                'pixel_ratio' => data_get($browser, 'viewport.pixel_ratio'),
            ],
            'screen' => [
                'width' => data_get($browser, 'screen.width'),
                'height' => data_get($browser, 'screen.height'),
            ],
            'device' => [
                'max_touch_points' => data_get($browser, 'device.max_touch_points'),
                'hardware_concurrency' => data_get($browser, 'device.hardware_concurrency'),
                'device_memory_gb' => data_get($browser, 'device.device_memory_gb'),
            ],
            'brands' => array_values((array) data_get($browser, 'brands', [])),
            'current_path' => (string) data_get($browser, 'current_path', ''),
            'referrer_host' => (string) data_get($browser, 'referrer_host', ''),
            'referrer_path' => (string) data_get($browser, 'referrer_path', ''),
            'ip_masked' => (string) data_get($request, 'ip_masked', ''),
            'accept_language' => (string) data_get($request, 'accept_language', ''),
        ];
    }
}
