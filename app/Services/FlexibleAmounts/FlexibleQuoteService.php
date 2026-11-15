<?php

namespace App\Services\FlexibleAmounts;

use App\Models\Client;
use App\Models\Platform;
use App\Services\PayLink\EligibilityService;
use App\Services\SelfServiceIncentiveService;
use Illuminate\Http\Exceptions\HttpResponseException;

class FlexibleQuoteService
{
    public function state(Client $client): array
    {
        return app(EligibilityService::class)->snapshot($client);
    }

    public function catalogue(Platform $market, Client $client, string $surface, array $state): array
    {
        $rules = app(FlexibleRulesService::class)->rules($market);
        if ($rules['mode'] === 'off' || ! ($rules['surfaces'][$surface] ?? false)) {
            return ['mode' => 'off', 'plans' => []];
        }
        // Build bounded, non-authorizing editor previews once, using the same state/engine
        // as quotes. Review and payment still obtain and confirm a fresh native quote.
        $incentives = app(SelfServiceIncentiveService::class);
        $percent = $incentives->resolveForPlatform($market->id, $this->incentiveSource($surface));
        $start = \Carbon\Carbon::createFromTimestamp(max(now()->timestamp, (int) ($state['escort_expire'] ?? 0)), $market->timezone ?: 'Africa/Nairobi');
        $plans = [];
        foreach (app(FlexibleRulesService::class)->catalogue($market) as $plan) {
            if (! $plan['eligible'] || (($state['live'] ?? false) && $state['current_tier'] !== $plan['tier'])) {
                continue;
            }
            $engine = new FlexiblePricingEngine($plan, $rules);
            if (! $engine->checks()['passes']) {
                continue;
            }
            unset($plan['all_prices']);
            $plan['minimum_amount'] = $engine->exact($rules['min_days'])['charge'];
            $plan['quick_picks'] = array_map(fn ($d) => ['days' => $d, 'amount' => $engine->exact($d)['charge'], 'package' => $engine->exact($d)['package']], array_values(array_unique(array_filter([$rules['min_days'], 7, 14, 30], fn ($d) => $d >= $rules['min_days'] && $d <= $rules['max_days']))));
            $rows = [];
            for ($day = $engine->minDays; $day <= $engine->maxDays; $day++) {
                $exact = $engine->exact($day);
                $discount = $incentives->applyToAmount((float) $exact['charge'], $percent);
                $charge = $discount ? number_format($discount['amount'], 2, '.', '') : $exact['charge'];
                $rows[] = [$day, $engine->price($day), FlexiblePricingEngine::minor($charge), $exact['package'], $start->copy()->addDays($day)->endOfDay()->toIso8601String()];
            }
            // [days, base minor units, collectible minor units, exact package, end date]
            $plan['preview'] = ['version' => 1, 'rows' => $rows, 'incentive_percent' => $percent];
            $plans[] = $plan;
        }

        return ['mode' => $rules['mode'], 'rules_version' => $rules['rules_version'], 'min_days' => $rules['min_days'], 'max_days' => $rules['max_days'], 'rescue_enabled' => $rules['rescue_enabled'], 'plans' => $plans, 'live' => (bool) ($state['live'] ?? false), 'current_tier' => $state['current_tier'] ?? null, 'phone_masked' => \App\Services\PayLink\CheckoutService::mask($client->phone_normalized), 'currency' => $market->currency_code, 'market_timezone' => $market->timezone ?: 'Africa/Nairobi'];
    }

    public function quote(Platform $market, Client $client, array $input, string $surface = 'dashboard', ?array $state = null, bool $lock = false): array
    {
        $rulesService = app(FlexibleRulesService::class);
        if ($lock) {
            \App\Models\BillingSubscriptionRule::where('market_id', $market->id)->lockForUpdate()->first();
        }
        $rules = $rulesService->rules($market);
        abort_unless($rules['mode'] !== 'off' && ($rules['surfaces'][$surface] ?? false), 404, 'Flexible amounts are off on this surface.');
        $state = $state ?? $this->state($client);
        $catalogue = $rulesService->catalogue($market, $lock);
        $plan = collect($catalogue)->firstWhere('product_id', (int) ($input['product_id'] ?? 0));
        abort_unless($plan && $plan['eligible'], 422, 'This plan has no eligible monthly price.');
        abort_if(($state['live'] ?? false) && $state['current_tier'] !== $plan['tier'], 409, 'Add days to your current plan. You can switch plans after it expires.');
        $engine = new FlexiblePricingEngine($plan, $rules);
        abort_unless($engine->checks()['passes'], 409, 'Prices need a team check before flexible checkout can continue.');
        $amount = (string) ($input['amount'] ?? '0');
        try {
            $result = isset($input['requested_days']) ? $engine->exact((int) $input['requested_days']) : $engine->quote($amount);
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }
        $catalogueHash = hash('sha256', json_encode($catalogue, JSON_THROW_ON_ERROR));
        $source = $this->incentiveSource($surface);
        $percent = app(SelfServiceIncentiveService::class)->resolveForPlatform($market->id, $source);
        $discount = $result['valid'] ? app(SelfServiceIncentiveService::class)->applyToAmount((float) $result['charge'], $percent) : null;
        $original = $result['charge'];
        if ($discount) {
            $result['charge'] = number_format($discount['amount'], 2, '.', '');
        }
        // Provider prompts in pilot currencies collect whole units, just as packages do.
        // Discount uses the existing service's cents precision; provider preflight decides support.
        $base = max(now()->timestamp, (int) ($state['escort_expire'] ?? 0));
        $start = \Carbon\Carbon::createFromTimestamp($base, $market->timezone ?: 'Africa/Nairobi');
        $quote = $result + ['plan' => $plan['plan'], 'tier' => $plan['tier'], 'product_id' => $plan['product_id'], 'base_product_price_id' => $plan['base_product_price_id'],
            'currency' => $plan['currency'], 'rules_version' => $rules['rules_version'], 'catalogue_hash' => $catalogueHash,
            'starts_at' => $start->toIso8601String(), 'ends_at' => $result['valid'] ? $start->copy()->addDays($result['days'])->endOfDay()->toIso8601String() : null,
            'locale' => in_array($input['locale'] ?? '', ['en', 'fr', 'sw'], true) ? $input['locale'] : (strtolower($market->country) === 'senegal' ? 'fr' : 'en'),
            'typed_amount' => $amount, 'pricing_mode' => 'flexible', 'entry_mode' => $surface, 'rescue_payment_id' => null,
            'rates' => ['monthly_price' => $plan['monthly_price'], 'uplift_percent' => $rules['uplift_percent'], 'rounding_step' => $rules['rounding_step']],
            'original_charge' => $original, 'self_service_incentive' => $discount, 'current_tier' => $state['current_tier'] ?? null, 'live' => (bool) ($state['live'] ?? false)];
        $quote['confirmation_hash'] = hash('sha256', json_encode([$plan['product_id'], $rules, $catalogueHash, $quote['days'], $quote['charge'], $discount, $state['current_tier'] ?? null, (bool) ($state['live'] ?? false), (int) ($state['escort_expire'] ?? 0)], JSON_THROW_ON_ERROR));

        return $quote;
    }

    private function incentiveSource(string $surface): string
    {
        return $surface === 'dashboard' ? 'self_checkout' : ($surface === 'crm_link' ? 'staff_link' : 'pay_link');
    }

    public function confirm(array $input, array $quote): void
    {
        if ((int) ($input['rules_version'] ?? -1) !== (int) $quote['rules_version'] || ! hash_equals($quote['catalogue_hash'], (string) ($input['catalogue_hash'] ?? '')) || ! hash_equals($quote['confirmation_hash'], (string) ($input['confirmation_hash'] ?? ''))) {
            throw new HttpResponseException(response()->json(['message' => 'Price updated. Review the fresh quote before paying.', 'code' => 'price_updated', 'quote' => $quote], 409));
        }
        abort_unless($quote['valid'] && (float) $quote['charge'] > 0, 422, 'Enter an amount that meets the minimum.');
    }
}
