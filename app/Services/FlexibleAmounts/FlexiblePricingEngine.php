<?php

namespace App\Services\FlexibleAmounts;

use InvalidArgumentException;

/** Pure pricing, in integer minor units. One rounded flexible segment plus any packages. */
final class FlexiblePricingEngine
{
    private array $prices = [];

    private array $compositions = [];

    private array $packages;

    public readonly int $minDays;

    public readonly int $maxDays;

    private int $monthly;

    private int $upliftBasisPoints;

    private int $step;

    public static function minor(string|int|float $amount): int
    {
        $value = (string) $amount;
        if (! preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/D', $value, $m)) {
            throw new InvalidArgumentException('Use a positive amount with at most two decimal places.');
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    public static function money(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    public function __construct(array $catalogue, array $rules)
    {
        $this->monthly = self::minor($catalogue['monthly_price']);
        $this->upliftBasisPoints = (int) round((float) ($rules['uplift_percent'] ?? 10) * 100);
        $this->step = self::minor($rules['rounding_step'] ?? 10);
        $this->minDays = (int) ($rules['min_days'] ?? 3);
        $this->maxDays = (int) ($rules['max_days'] ?? 90);
        if ($this->monthly <= 0 || $this->monthly > 99999999900 || $this->step <= 0 || $this->minDays < 3 || $this->maxDays < $this->minDays || $this->maxDays > 365 || $this->upliftBasisPoints < 0 || $this->upliftBasisPoints > 100000) {
            throw new InvalidArgumentException('Invalid flexible pricing rules.');
        }
        $this->packages = array_values(array_filter($catalogue['packages'] ?? [], fn ($p) => in_array((int) $p['days'], [7, 14, 30], true) && self::minor($p['price']) > 0));
        usort($this->packages, fn ($a, $b) => [$a['days'], self::minor($a['price']), $a['product_price_id'] ?? 0] <=> [$b['days'], self::minor($b['price']), $b['product_price_id'] ?? 0]);
        $this->prices[0] = 0;
        $this->compositions[0] = [];
        for ($d = 1; $d <= $this->maxDays; $d++) {
            $this->prices[$d] = $this->flexPrice($d);
            $this->compositions[$d] = [['kind' => 'flexible', 'days' => $d, 'amount' => self::money($this->prices[$d])]];
            foreach ($this->packages as $p) {
                $n = (int) $p['days'];
                if ($n > $d) {
                    continue;
                }
                $cost = self::minor($p['price']) + $this->prices[$d - $n];
                // Prefer a package on ties, and fewer components thereafter.
                $composition = array_merge([['kind' => 'package', 'days' => $n, 'amount' => self::money(self::minor($p['price'])), 'product_price_id' => $p['product_price_id'] ?? null]], $this->compositions[$d - $n]);
                if ($cost < $this->prices[$d] || ($cost === $this->prices[$d] && count($composition) <= count($this->compositions[$d]))) {
                    $this->prices[$d] = $cost;
                    $this->compositions[$d] = $composition;
                }
            }
        }
    }

    public function flexPrice(int $days, ?int $upliftBasisPoints = null): int
    {
        $numerator = $days * $this->monthly * (10000 + ($upliftBasisPoints ?? $this->upliftBasisPoints));
        $denominator = 30 * 10000 * $this->step;

        return intdiv($numerator + $denominator - 1, $denominator) * $this->step;
    }

    public function price(int $days): int
    {
        if ($days < 0 || $days > $this->maxDays) {
            throw new InvalidArgumentException('Days are outside this market’s limits.');
        }

        return $this->prices[$days];
    }

    public function exact(int $days): array
    {
        if ($days < $this->minDays || $days > $this->maxDays) {
            throw new InvalidArgumentException('Choose a duration within this market’s limits.');
        }

        return ['valid' => true, 'days' => $days, 'charge' => self::money($this->price($days)), 'composition' => $this->compositions[$days], 'package' => count($this->compositions[$days]) === 1 && $this->compositions[$days][0]['kind'] === 'package'];
    }

    public function quote(string|int|float $amount): array
    {
        $budget = self::minor($amount);
        $days = 0;
        for ($d = $this->minDays; $d <= $this->maxDays; $d++) {
            if ($this->prices[$d] <= $budget) {
                $days = $d;
            }
        }
        $minimum = min(array_slice($this->prices, $this->minDays, null, true));
        if (! $days) {
            return ['valid' => false, 'days' => 0, 'charge' => null, 'composition' => [], 'minimum_amount' => self::money($minimum), 'nudge' => ['kind' => 'minimum', 'amount' => self::money($minimum), 'add' => self::money(max(0, $minimum - $budget)), 'days' => $this->minDays]];
        }
        $nudge = null;
        foreach ($this->packages as $p) {
            if ((int) $p['days'] < $this->minDays || (int) $p['days'] > $this->maxDays) {
                continue;
            }
            $cost = $this->prices[(int) $p['days']];
            if ($p['days'] > $days && $cost > $budget && ($cost - $budget) * 4 <= $cost) {
                $candidate = ['kind' => 'package', 'days' => (int) $p['days'], 'amount' => self::money($cost), 'add' => self::money($cost - $budget)];
                if (! $nudge || $cost < self::minor($nudge['amount'])) {
                    $nudge = $candidate;
                }
            }
        }
        if (! $nudge && $days < $this->maxDays) {
            $nudge = ['kind' => 'next_day', 'days' => $days + 1, 'amount' => self::money($this->prices[$days + 1]), 'add' => self::money($this->prices[$days + 1] - $budget)];
        }

        return $this->exact($days) + ['typed_amount' => self::money($budget), 'minimum_amount' => self::money($minimum), 'nudge' => $nudge];
    }

    public function checks(): array
    {
        $required = 0;
        $checks = [];
        foreach ($this->packages as $p) {
            $target = self::minor($p['price']);
            $lo = 0;
            $hi = 100000;
            while ($lo < $hi) {
                $mid = intdiv($lo + $hi, 2);
                if ($this->flexPrice((int) $p['days'], $mid) >= $target) {
                    $hi = $mid;
                } else {
                    $lo = $mid + 1;
                }
            }
            $required = max($required, $lo);
            $checks[] = ['days' => (int) $p['days'], 'product_price_id' => $p['product_price_id'] ?? null, 'package_price' => self::money($target), 'flex_price' => self::money($this->flexPrice((int) $p['days'])), 'passes' => $this->flexPrice((int) $p['days']) >= $target];
        }

        return ['passes' => ! in_array(false, array_column($checks, 'passes'), true), 'minimum_uplift_percent' => $required / 100, 'packages' => $checks];
    }
}
