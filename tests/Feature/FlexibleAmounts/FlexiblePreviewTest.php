<?php

namespace Tests\Feature\FlexibleAmounts;

use App\Models\BillingSubscriptionRule;
use App\Services\FlexibleAmounts\FlexiblePricingEngine;
use App\Services\FlexibleAmounts\FlexibleQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FlexiblePreviewTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Support\FlexibleAmountsFixture;

    public function test_preview_rows_match_authoritative_quotes_with_surface_specific_incentives_and_short_limits(): void
    {
        $service = app(FlexibleQuoteService::class);
        foreach ([3, 6, 14, 20, 90] as $maximum) {
            $rule = BillingSubscriptionRule::where('market_id', $this->market->id)->first();
            $rules = $rule->flexible_amounts;
            $rules['max_days'] = $maximum;
            $rule->update(['flexible_amounts' => $rules, 'discount_json' => ['self_service_incentive' => ['enabled' => true, 'percent' => 10, 'sources' => ['self_checkout', 'staff_link']]]]);
            foreach (['dashboard', 'pay_link', 'crm_link'] as $surface) {
                DB::flushQueryLog();
                DB::enableQueryLog();
                $catalogue = $service->catalogue($this->market, $this->client, $surface, $this->state);
                $count = count(DB::getQueryLog());
                DB::disableQueryLog();
                $this->assertLessThan(20, $count, 'Catalogue must not query per preview day.');
                $plan = $catalogue['plans'][0];
                $this->assertCount($maximum - 2, $plan['preview']['rows']);
                foreach ($plan['preview']['rows'] as [$days, $base, $charge, $package, $until]) {
                    $exact = $service->quote($this->market, $this->client, ['product_id' => $this->product->id, 'requested_days' => $days], $surface, $this->state);
                    $this->assertSame([$days, FlexiblePricingEngine::minor($exact['original_charge']), FlexiblePricingEngine::minor($exact['charge']), $exact['package'], $exact['ends_at']], [$days, $base, $charge, $package, $until]);
                }
                // Budget selection must use base prices, even when collection is discounted.
                foreach ([0, 659, 660, 1000, 1300, 1500, 2700, 3000, 6000, 20000] as $budget) {
                    $expected = $service->quote($this->market, $this->client, ['product_id' => $this->product->id, 'amount' => (string) $budget], $surface, $this->state);
                    $affordable = array_values(array_filter($plan['preview']['rows'], fn ($r) => $r[1] <= $budget * 100));
                    $row = $affordable ? end($affordable) : null;
                    $this->assertSame($expected['days'], $row[0] ?? 0);
                    if ($row) {
                        $this->assertSame($expected['charge'], FlexiblePricingEngine::money($row[2]));
                    }
                    if ($expected['nudge']) {
                        $this->assertLessThanOrEqual($maximum, $expected['nudge']['days']);
                    }
                }
            }
        }
    }

    public function test_preview_remains_read_only_and_does_not_authorize_checkout_or_ignore_mode_and_tier(): void
    {
        $service = app(FlexibleQuoteService::class);
        $plan = $service->catalogue($this->market, $this->client, 'dashboard', $this->state)['plans'][0];
        $this->assertArrayNotHasKey('confirmation_hash', $plan['preview']);
        $state = $this->state;
        $state['current_tier'] = 'basic';
        $this->assertSame([], $service->catalogue($this->market, $this->client, 'dashboard', $state)['plans']);
        BillingSubscriptionRule::where('market_id', $this->market->id)->update(['flexible_amounts' => null]);
        $this->assertSame(['mode' => 'off', 'plans' => []], $service->catalogue($this->market, $this->client, 'dashboard', $this->state));
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('flexible_checkout_attempts', 0);
    }
}
