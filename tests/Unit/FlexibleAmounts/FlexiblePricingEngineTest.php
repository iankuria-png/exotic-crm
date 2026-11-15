<?php

namespace Tests\Unit\FlexibleAmounts;

use App\Services\FlexibleAmounts\FlexiblePricingEngine as Engine;
use PHPUnit\Framework\TestCase;

class FlexiblePricingEngineTest extends TestCase
{
    private function engine(int $monthly = 6000, array $packages = [7 => 1500, 14 => 3000, 30 => 6000], int $step = 10): Engine
    {
        return new Engine(['monthly_price' => $monthly, 'packages' => array_map(fn ($d, $p) => ['days' => $d, 'price' => $p], array_keys($packages), $packages)], ['rounding_step' => $step]);
    }

    public function test_all_approved_reference_vectors_and_nudges(): void
    {
        $vip = $this->engine();
        $this->assertFalse($vip->quote(500)['valid']);
        $this->assertSame('660.00', $vip->quote(500)['minimum_amount']);
        foreach ([[660, 3, 660], [1000, 4, 880], [1300, 5, 1100], [1500, 7, 1500], [2000, 9, 1940], [6300, 31, 6220], [12000, 60, 12000]] as [$a,$d,$charge]) {
            $q = $vip->quote($a);
            $this->assertSame($d, $q['days']);
            $this->assertSame($charge * 100, Engine::minor($q['charge']));
        }
        $this->assertSame(['kind' => 'next_day', 'days' => 5, 'amount' => '1100.00', 'add' => '100.00'], $vip->quote(1000)['nudge']);
        $this->assertSame(['kind' => 'package', 'days' => 7, 'amount' => '1500.00', 'add' => '200.00'], $vip->quote(1300)['nudge']);
        foreach ([[12000, [7 => 3000, 30 => 12000], 3000, 7, 3000], [4000, [7 => 1000, 30 => 4000], 1000, 7, 1000], [3000, [7 => 750, 30 => 3000], 1000, 9, 970]] as [$monthly,$packs,$a,$d,$charge]) {
            $q = $this->engine($monthly, $packs)->quote($a);
            $this->assertSame($d, $q['days']);
            $this->assertSame($charge * 100, Engine::minor($q['charge']));
        }
    }

    public function test_bounded_budget_monotonicity_packages_and_segment_rounding_for_all_pilot_steps(): void
    {
        foreach ([10, 25, 500] as $step) {
            $engine = $this->engine(6000, [7 => 1500, 14 => 3000, 30 => 6000], $step);
            $previous = 0;
            for ($amount = 0; $amount <= 22000; $amount += 17) {
                $q = $engine->quote($amount);
                $this->assertGreaterThanOrEqual($previous, $q['days']);
                $this->assertLessThanOrEqual(90, $q['days']);
                if ($q['valid']) {
                    $this->assertGreaterThanOrEqual(3, $q['days']);
                    $this->assertLessThanOrEqual($amount * 100, Engine::minor($q['charge']));
                    $sum = 0;
                    $days = 0;
                    foreach ($q['composition'] as $part) {
                        $sum += Engine::minor($part['amount']);
                        $days += $part['days'];
                        if ($part['kind'] === 'flexible') {
                            $this->assertSame(0, Engine::minor($part['amount']) % ($step * 100));
                        }
                    }
                    $this->assertSame(Engine::minor($q['charge']), $sum);
                    $this->assertSame($q['days'], $days);
                }
                $previous = $q['days'];
            }
            foreach ([7, 14, 30] as $d) {
                $this->assertLessThanOrEqual($engine->flexPrice($d), $engine->price($d));
            }
        }
    }

    public function test_custom_duration_rows_are_excluded_and_exact_days_do_not_invert_budget(): void
    {
        $engine = new Engine(['monthly_price' => 6000, 'packages' => [['days' => 3, 'price' => 500], ['days' => 7, 'price' => 1000], ['days' => 30, 'price' => 6000]]], ['rounding_step' => 10]);
        $this->assertSame(66000, $engine->price(3));
        $this->assertSame(6, $engine->exact(6)['days']);
        $this->assertSame('1320.00', $engine->exact(6)['charge']);
        $this->assertSame(8, $engine->quote(1320)['days']);
    }

    public function test_minimum_uplift_uses_rounded_price_at_one_hundredth_percent_precision(): void
    {
        $engine = $this->engine(180000, [7 => 60000, 14 => 90000, 30 => 180000], 500);
        $check = $engine->checks();
        $this->assertFalse($check['passes']);
        $basis = (int) round($check['minimum_uplift_percent'] * 100);
        $this->assertGreaterThanOrEqual(6000000, $engine->flexPrice(7, $basis));
        $this->assertLessThan(6000000, $engine->flexPrice(7, $basis - 1));
        $this->assertTrue($this->engine()->checks()['passes']);
    }
}
