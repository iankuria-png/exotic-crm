<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
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
    ) {
    }

    public function index(Request $request): JsonResponse
    {
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
            'recent_unlocks' => VisitorContactUnlock::query()
                ->with(['platform:id,name,currency_code', 'client:id,name,wp_post_id,wp_profile_permalink', 'payment:id,status,amount,currency,reference_number,failure_reason,payment_data,provider_key,provider_environment'])
                ->latest('id')
                ->limit(25)
                ->get()
                ->map(fn (VisitorContactUnlock $unlock) => $this->serializeUnlock($unlock))
                ->values(),
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
            'expires_at' => $unlock->expires_at?->toIso8601String(),
            'created_at' => $unlock->created_at?->toIso8601String(),
        ];
    }
}
