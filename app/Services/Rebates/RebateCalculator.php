<?php

namespace App\Services\Rebates;

class RebateCalculator
{
    public function quote(array $rules, array $input): array
    {
        $channel = $input['channel'] ?? 'topup';
        $amount = max(0, (float) ($input['amount'] ?? 0));
        $rate = 0;
        $bonus = 0;
        $reason = null;
        if ($channel === 'topup') {
            if (! data_get($rules, 'topup.on')) {
                $reason = 'channel_off';
            }
            foreach (data_get($rules, 'topup.tiers', []) as $tier) {
                if ($amount >= $tier['min']) {
                    $rate = (float) $tier['pct'];
                }
            }
            if (! $rate && ! $reason) {
                $reason = 'below_tier';
            }
            $cap = (float) data_get($rules, 'topup.cap', 0);
            if (! $reason && ! empty($input['first_topup']) && data_get($rules, 'first.on') && $amount >= (float) data_get($rules, 'first.min')) {
                $bonus = (float) data_get($rules, 'first.amount');
            }
        } else {
            $key = $channel === 'auto_renew' ? 'wallet' : $channel;
            if (! data_get($rules, 'self.on') || ! data_get($rules, "self.channels.{$key}.on", false)) {
                $reason = 'channel_off';
            }
            if (in_array($channel, ['wallet', 'auto_renew'], true) && ! data_get($rules, 'guard.stack', true)) {
                $reason = 'stacking_off';
            }
            $rate = (float) data_get($rules, "self.channels.{$key}.pct", 0);
            $cap = (float) data_get($rules, 'self.cap', 0);
            if ($channel === 'auto_renew' && data_get($rules, 'renew.on')) {
                $bonus = $this->wholePercent($amount, (float) data_get($rules, 'renew.pct'));
            }
        }
        if ($reason) {
            return ['total' => 0, 'calculated' => 0, 'rate' => $rate, 'bonus' => 0, 'base' => 0, 'lines' => [], 'capped_by' => null, 'reason' => $reason];
        }
        $raw = $this->wholePercent($amount, $rate);
        $base = min($raw, $cap);
        $calculated = $raw + $bonus;
        $total = $base + $bonus;
        $cappedBy = $base < $raw ? 'payment_cap' : null;
        foreach (['monthly_cap' => max(0, (float) data_get($rules, 'guard.monthly_cap') - (float) ($input['earned_this_month'] ?? 0)), 'budget' => max(0, (float) ($input['budget_remaining'] ?? data_get($rules, 'guard.budget')))] as $limit => $remaining) {
            if ($total > $remaining) {
                $total = $remaining;
                $cappedBy = $limit;
            }
        }
        $total = round($total, 2);
        $basePaid = min($base, $total);
        $bonusPaid = max(0, $total - $basePaid);

        return ['total' => $total, 'calculated' => $calculated, 'rate' => $rate, 'base' => $basePaid, 'bonus' => $bonusPaid, 'lines' => array_values(array_filter([
            ['label' => $channel === 'topup' ? 'Top-up rebate' : 'Activation rebate', 'rate' => $rate, 'amount' => $basePaid],
            $bonus ? ['label' => $channel === 'topup' ? 'First top-up bonus' : 'Auto-renew bonus', 'amount' => $bonusPaid] : null,
        ])), 'capped_by' => $cappedBy, 'reason' => $total > 0 ? null : ($cappedBy ?? 'zero_rate')];
    }

    private function wholePercent(float $amount, float $percent): int
    {
        return intdiv((int) round($amount * 100) * (int) round($percent * 100), 1000000);
    }
}
