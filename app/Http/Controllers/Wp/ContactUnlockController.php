<?php

namespace App\Http\Controllers\Wp;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Platform;
use App\Services\ContactUnlockCheckoutService;
use App\Services\ContactUnlockPricingService;
use App\Services\ContactUnlockRevealService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactUnlockController extends Controller
{
    public function __construct(
        private readonly ContactUnlockPricingService $pricingService,
        private readonly ContactUnlockCheckoutService $checkoutService,
        private readonly ContactUnlockRevealService $revealService
    ) {
    }

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

        return $this->noStore([
            'enabled' => $enabled && $rules->isNotEmpty(),
            'disabled_reason' => $enabled
                ? ($rules->isEmpty() ? 'pricing_unavailable' : null)
                : 'profile_or_market_unavailable',
            'market' => [
                'id' => (int) $platform->id,
                'name' => (string) $platform->name,
                'currency' => (string) ($platform->currency_code ?: ''),
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
