<?php

namespace App\Services\Tours;

use App\Models\Platform;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class PricingService
{
    public function price(Platform $home, array $settings, array $snapshot): array
    {
        $remote = array_values(array_filter($snapshot['stops'] ?? [], fn ($s) => ($s['kind'] ?? '') === 'remote'));
        abort_unless(count($remote) > 0 && count($snapshot['stops']) <= ($settings['max_stops'] ?? 10), 422, 'Choose a supported destination route.');
        $all = $snapshot['stops'];
        $first = min(array_column($all, 'start'));
        $last = max(array_column($all, 'end'));
        $zone = $home->timezone ?: 'UTC';
        $span = (int) Carbon::createFromTimestamp($first, $zone)->startOfDay()->diffInDays(Carbon::createFromTimestamp($last, $zone)->startOfDay()) + 1;
        abort_unless($span <= ($settings['max_span_days'] ?? 90), 422, 'This trip is longer than the configured limit.');
        $groups = [];
        foreach ($remote as $stop) {
            $region = $stop['region'] ?? '';
            abort_unless(in_array($region, ['africa', 'gulf', 'europe', 'hub'], true) && ! empty($stop['key']) && (int) ($stop['end'] ?? 0) >= (int) ($stop['start'] ?? 0), 422, 'Invalid destination schedule.');
            $groups[$region][] = $stop;
        }
        $passes = [];
        $rows = [];
        $total = 0;
        $currency = $home->primaryCurrency();
        $now = now()->timestamp;
        foreach ($groups as $region => $stops) {
            $start = min(array_column($stops, 'start'));
            $end = max(array_column($stops, 'end'));
            abort_unless($start >= Carbon::createFromTimestamp($now, $home->timezone ?: 'UTC')->startOfDay()->timestamp, 422, 'This trip is no longer available to book.');
            $days = (int) Carbon::createFromTimestamp($start, $home->timezone ?: 'UTC')->startOfDay()->diffInDays(Carbon::createFromTimestamp($end, $home->timezone ?: 'UTC')->startOfDay()) + 1;
            $ids = array_values(array_unique(array_filter(array_map(fn ($s) => (int) ($s['platform_id'] ?? 0), $stops))));
            $candidates = array_values(array_filter($settings['passes'] ?? [], fn ($p) => ! empty($p['enabled']) && $p['region'] === $region && $p['currency'] === $currency && (int) $p['days'] >= $days && ! empty($p['destination_platform_ids']) && (! $p['market_limit'] || count($ids) <= $p['market_limit']) && ($region !== 'africa' || ($ids && ! array_diff($ids, $p['destination_platform_ids'])))));
            usort($candidates, fn ($a, $b) => [$a['amount_minor'], $a['days'], $a['code']] <=> [$b['amount_minor'], $b['days'], $b['code']]);
            if (! $candidates) {
                throw ValidationException::withMessages(['route' => 'No configured pass covers this route and its dates. Shorten or split the trip.']);
            }
            $pass = $candidates[0];
            $expires = Carbon::createFromTimestamp($start, $home->timezone ?: 'UTC')->startOfDay()->addDays($pass['days'])->subSecond()->timestamp;
            $expanded = [];
            foreach ($stops as $stop) {
                $targets = $region === 'africa' ? [(int) $stop['platform_id']] : $pass['destination_platform_ids'];
                foreach ($targets as $target) {
                    abort_if((int) $target === (int) $home->id, 422, 'Choose another destination market.');
                    $expanded[] = ['source_stop_key' => (string) $stop['key'], 'destination_platform_id' => (int) $target, 'city_id' => $stop['city_id'] ?? null, 'start' => (int) $stop['start'], 'end' => (int) $stop['end'], 'pass_expires_at' => $expires, 'phone' => (string) ($stop['phone'] ?? ''), 'whatsapp' => (string) ($stop['whatsapp'] ?? ''), 'display_name' => $stop['display_name'] ?? $pass['name']];
                }
            }
            usort($expanded, fn ($a, $b) => [$a['source_stop_key'], $a['destination_platform_id']] <=> [$b['source_stop_key'], $b['destination_platform_id']]);
            $n = count($expanded);
            abort_unless($n > 0 && $n <= 100, 422, 'Too many destination copies.');
            foreach ($expanded as $i => &$row) {
                $row['allocation_minor'] = intdiv($pass['amount_minor'], $n) + ($i < ($pass['amount_minor'] % $n) ? 1 : 0);
            }unset($row);
            $pass['starts_at'] = $start;
            $pass['expires_at'] = $expires;
            $passes[] = $pass;
            $rows = array_merge($rows, $expanded);
            $total += (int) $pass['amount_minor'];
        }
        abort_unless($total > 0 && count($rows) <= 100, 422, 'This route is outside supported limits.');

        return ['amount_minor' => $total, 'currency' => $currency, 'passes' => $passes, 'stops' => $rows, 'pass_expires_at' => max(array_column($passes, 'expires_at')), 'home_subscription_end' => $snapshot['home_subscription_end'] ?? null];
    }
}
