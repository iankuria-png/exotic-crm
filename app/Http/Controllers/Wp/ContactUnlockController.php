<?php

namespace App\Http\Controllers\Wp;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Platform;
use App\Services\ContactUnlockCheckoutService;
use App\Services\ContactUnlockEventService;
use App\Services\ContactUnlockPricingService;
use App\Services\ContactUnlockRevealService;
use App\Services\ContactUnlockUpgradeQuoteService;
use App\Support\ClientLifecycleState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactUnlockController extends Controller
{
    public function __construct(
        private readonly ContactUnlockPricingService $pricingService,
        private readonly ContactUnlockCheckoutService $checkoutService,
        private readonly ContactUnlockRevealService $revealService,
        private readonly ContactUnlockUpgradeQuoteService $upgradeQuoteService,
        private readonly ContactUnlockEventService $eventService
    ) {}

    public function config(Request $request): JsonResponse
    {
        $platform = $this->platform($request);
        $validated = $request->validate([
            'wp_post_id' => 'required|integer|min:1',
        ]);

        $client = Client::query()
            ->where('platform_id', (int) $platform->id)
            ->where('wp_post_id', (int) $validated['wp_post_id'])
            ->first();

        $enabled = $client !== null
            && $client->isPubliclyRestricted()
            && $this->pricingService->enabledForPlatform($platform);

        $rules = $enabled
            ? $this->pricingService->activeRules($platform)->map(fn ($rule) => [
                'id' => (int) $rule->id,
                'scope' => (string) $rule->scope,
                'label' => (string) $rule->label,
                'currency' => (string) $rule->currency,
                'amount' => (float) $rule->amount,
                'duration_days' => (int) $rule->duration_days,
            ])->values()
            : collect();
        $restrictedProfileCount = $enabled
            ? Client::query()
                ->where('platform_id', (int) $platform->id)
                ->where('profile_status', 'publish')
                ->whereIn('lifecycle_state', [ClientLifecycleState::EXPIRED, ClientLifecycleState::ARCHIVED])
                ->count()
            : 0;

        return $this->noStore([
            'enabled' => $enabled && $rules->isNotEmpty(),
            'disabled_reason' => $enabled
                ? ($rules->isEmpty() ? 'pricing_unavailable' : null)
                : 'profile_or_market_unavailable',
            'market' => [
                'id' => (int) $platform->id,
                'name' => (string) $platform->name,
                'currency' => (string) ($platform->currency_code ?: ''),
                'phone_prefix' => (string) ($platform->phone_prefix ?: ''),
                'restricted_profile_count' => (int) $restrictedProfileCount,
            ],
            'profile' => [
                'wp_post_id' => (int) $validated['wp_post_id'],
                'restricted' => $client?->isPubliclyRestricted() ?? false,
            ],
            'pricing_rules' => $rules,
            'providers' => $enabled ? $this->pricingService->providerOptions($platform) : [],
            'polling_interval_ms' => 4000,
        ]);
    }

    public function createIntent(Request $request): JsonResponse
    {
        $platform = $this->platform($request);
        $validated = $request->validate([
            'wp_post_id' => 'required|integer|min:1',
            'pricing_rule_id' => 'required|integer|min:1',
            'provider_key' => 'required|string|max:40',
            'visitor_phone' => 'required|string|max:40',
            'visitor_email' => 'nullable|email|max:190',
            'session_proof' => 'required|string|min:20|max:190',
            'visitor_context' => 'nullable|array',
            'upgrade_quote_token' => 'nullable|string|min:20|max:190',
        ]);

        $idempotencyKey = trim((string) $request->header('X-Idempotency-Key', ''));
        if ($idempotencyKey === '') {
            abort(422, 'X-Idempotency-Key is required.');
        }

        return $this->noStore($this->checkoutService->createIntent(
            $platform,
            (int) $validated['wp_post_id'],
            (int) $validated['pricing_rule_id'],
            (string) $validated['provider_key'],
            (string) $validated['visitor_phone'],
            $validated['visitor_email'] ?? null,
            (string) $validated['session_proof'],
            $idempotencyKey,
            $validated['visitor_context'] ?? [],
            $request,
            $validated['upgrade_quote_token'] ?? null
        ));
    }

    public function upgradeQuote(Request $request): JsonResponse
    {
        $platform = $this->platform($request);
        $validated = $request->validate([
            'wp_post_id' => 'required|integer|min:1',
            'target_scope' => 'nullable|string|in:market_inactive_profiles',
            'pricing_rule_id' => 'required|integer|min:1',
            'public_tokens' => 'nullable|array|max:10',
            'public_tokens.*' => 'string|min:20|max:190',
            'visitor_phone' => 'nullable|string|max:40',
            'session_proof' => 'required|string|min:20|max:190',
        ]);

        return $this->noStore($this->upgradeQuoteService->quote(
            $platform,
            (int) $validated['wp_post_id'],
            (int) $validated['pricing_rule_id'],
            $validated['public_tokens'] ?? [],
            $validated['visitor_phone'] ?? null,
            (string) $validated['session_proof']
        ));
    }

    public function event(Request $request): JsonResponse
    {
        $platform = $this->platform($request);
        $validated = $request->validate([
            'wp_post_id' => 'required|integer|min:1',
            'event_id' => 'required|string|max:120',
            'event_type' => 'required|string|max:40',
            'public_token' => 'nullable|string|min:20|max:190',
            'session_proof' => 'required|string|min:20|max:190',
            'visitor_context' => 'nullable|array',
        ]);

        return $this->noStore($this->eventService->record(
            $platform,
            (int) $validated['wp_post_id'],
            (string) $validated['event_id'],
            (string) $validated['event_type'],
            (string) $validated['session_proof'],
            $validated['visitor_context'] ?? [],
            $validated['public_token'] ?? null,
            $request
        ));
    }

    public function status(Request $request): JsonResponse
    {
        $platform = $this->platform($request);
        $validated = $request->validate([
            'public_token' => 'required|string|min:20|max:190',
            'session_proof' => 'required|string|min:20|max:190',
            'target_wp_post_id' => 'required|integer|min:1',
        ]);

        return $this->noStore($this->revealService->status(
            (string) $validated['public_token'],
            (string) $validated['session_proof'],
            (int) $validated['target_wp_post_id'],
            (int) $platform->id
        ));
    }

    public function reveal(Request $request): JsonResponse
    {
        $platform = $this->platform($request);
        $validated = $request->validate([
            'public_token' => 'required|string|min:20|max:190',
            'session_proof' => 'required|string|min:20|max:190',
            'target_wp_post_id' => 'required|integer|min:1',
        ]);

        return $this->noStore($this->revealService->reveal(
            (string) $validated['public_token'],
            (string) $validated['session_proof'],
            (int) $validated['target_wp_post_id'],
            (int) $platform->id
        ));
    }

    private function platform(Request $request): Platform
    {
        /** @var Platform $platform */
        $platform = $request->attributes->get('platform');

        return $platform;
    }

    private function noStore(array $payload): JsonResponse
    {
        return response()
            ->json($payload)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }
}
