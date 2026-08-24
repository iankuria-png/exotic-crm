<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ContactUnlockPricingRule;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\VisitorContactUnlock;
use App\Services\ContactUnlockPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ContactUnlockAdminController extends Controller
{
    public function __construct(
        private readonly ContactUnlockPricingService $pricingService
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
        ]);

        $markets = Platform::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'country', 'domain', 'currency_code', 'phone_prefix']);

        $rules = ContactUnlockPricingRule::query()
            ->with('platform:id,name,currency_code')
            ->orderBy('platform_id')
            ->orderBy('amount')
            ->get()
            ->map(fn (ContactUnlockPricingRule $rule) => $this->serializeRule($rule))
            ->values();

        $unlockStats = VisitorContactUnlock::query()
            ->select('status', DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy('status')
            ->pluck('aggregate_count', 'status');

        $paymentQuery = Payment::query()->contactUnlockRevenue();
        $confirmedQuery = (clone $paymentQuery)->where('status', 'completed');
        $recentUnlocks = $this->recentUnlocks($filters);

        return response()->json([
            'settings' => [
                'enabled' => $this->pricingService->globallyEnabled(),
                'market_ids' => $this->pricingService->enabledMarketIds(),
                'sandbox_only' => $this->pricingService->sandboxOnly(),
            ],
            'summary' => [
                'total_unlocks' => (int) VisitorContactUnlock::query()->count(),
                'active_unlocks' => (int) VisitorContactUnlock::query()->active()->count(),
                'pending_unlocks' => (int) ($unlockStats[VisitorContactUnlock::STATUS_PENDING_PAYMENT] ?? 0),
                'completed_payments' => (int) (clone $confirmedQuery)->count(),
                'confirmed_revenue_native' => $this->nativeRevenue((clone $confirmedQuery)),
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
            'pricing_rules' => $rules,
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
        ]);
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

    private function recentUnlocks(array $filters)
    {
        $query = VisitorContactUnlock::query()
            ->with(['platform:id,name,currency_code', 'client:id,name,wp_post_id,wp_profile_permalink', 'payment:id,status,amount,currency,reference_number,failure_reason,payment_data,provider_key,provider_environment']);

        if (! empty($filters['platform_id'])) {
            $query->where('platform_id', (int) $filters['platform_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (! empty($filters['scope'])) {
            $query->where('scope', (string) $filters['scope']);
        }

        if (! empty($filters['payment_status'])) {
            $query->whereHas('payment', fn ($paymentQuery) => $paymentQuery->where('status', (string) $filters['payment_status']));
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
            $query->where(function ($searchQuery) use ($like, $search): void {
                if (ctype_digit($search)) {
                    $searchQuery->orWhere('id', (int) $search);
                }

                $searchQuery
                    ->orWhere('visitor_phone_masked', 'like', $like)
                    ->orWhere('visitor_email_masked', 'like', $like)
                    ->orWhereHas('client', function ($clientQuery) use ($like): void {
                        $clientQuery
                            ->where('name', 'like', $like)
                            ->orWhere('wp_profile_permalink', 'like', $like);
                    })
                    ->orWhereHas('payment', function ($paymentQuery) use ($like): void {
                        $paymentQuery
                            ->where('reference_number', 'like', $like)
                            ->orWhere('transaction_reference', 'like', $like)
                            ->orWhere('provider_key', 'like', $like);
                    });
            });
        }

        $direction = (string) ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        match ((string) ($filters['sort'] ?? 'id')) {
            'amount' => $query->orderBy(Payment::query()
                ->select('amount')
                ->whereColumn('payments.id', 'visitor_contact_unlocks.payment_id')
                ->limit(1), $direction),
            'payment_status' => $query->orderBy(Payment::query()
                ->select('status')
                ->whereColumn('payments.id', 'visitor_contact_unlocks.payment_id')
                ->limit(1), $direction),
            'profile' => $query->orderBy(Client::query()
                ->select('name')
                ->whereColumn('clients.id', 'visitor_contact_unlocks.client_id')
                ->limit(1), $direction),
            'market' => $query->orderBy(Platform::query()
                ->select('name')
                ->whereColumn('platforms.id', 'visitor_contact_unlocks.platform_id')
                ->limit(1), $direction),
            'created_at' => $query->orderBy('created_at', $direction),
            'status' => $query->orderBy('status', $direction),
            'scope' => $query->orderBy('scope', $direction),
            'visitor' => $query->orderBy('visitor_phone_masked', $direction),
            default => $query->orderBy('id', $direction),
        };

        $query->orderBy('id', 'desc');

        return $query->paginate((int) ($filters['per_page'] ?? 10));
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
            'visitor_phone_masked' => (string) ($unlock->visitor_phone_masked ?? ''),
            'visitor_email_masked' => (string) ($unlock->visitor_email_masked ?? ''),
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
