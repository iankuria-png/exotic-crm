<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Aggregate already-authorized, cent-normalized cash; never query or revalue payments here. */
final class CeoRevenueCollectionsPresenter
{
    public function present(Collection $cash, Collection $wallet, array $intervals, CarbonImmutable $end, string $currency, string $product, string $label, string $bucket, bool $peak): array
    {
        [$from, $to] = $intervals['selected'];
        [$priorFrom, $priorTo] = $intervals['prior'];
        $days = (int) $from->diffInDays($end);
        $bucket = $days === 1 ? 'hour' : ($bucket === 'auto' ? ($days <= 31 ? 'day' : ($days <= 100 ? 'week' : 'month')) : $bucket);
        $current = $this->within($cash, $from, $to);
        $previous = $this->within($cash, $priorFrom, $priorTo);
        $selected = $this->money($current, $currency);
        $prior = $this->money($previous, $currency);
        $shift = $priorFrom->diffInSeconds($from);
        $currentGroups = $current->groupBy(fn ($r) => $this->start($r->instant->setTimezone('Africa/Nairobi'), $bucket)->toIso8601String());
        $priorGroups = $previous->groupBy(fn ($r) => $this->start($r->instant->addSeconds($shift)->setTimezone('Africa/Nairobi'), $bucket)->toIso8601String());
        $window = ['from' => $from->toDateString(), 'to' => $to->subMicrosecond()->max($from)->toDateString(),
            'from_iso' => $from->toIso8601String(), 'to_iso' => $to->toIso8601String(),
            'timezone' => 'Africa/Nairobi', 'target_currency' => $currency, 'is_single_day' => $days === 1,
            'partial' => $to->lt($end), 'boundaries' => '[from,to)'];
        $points = [];
        $cursor = $this->start($from, $bucket);
        // A single-day chart keeps its 24-hour frame; other ranges stop at the cutoff.
        $plotEnd = $bucket === 'hour' ? $end : $to;
        while ($cursor->lt($plotEnd)) {
            abort_if(count($points) >= 9000, 422, 'Choose a coarser chart interval; the range exceeds 9,000 buckets.');
            $key = $cursor->toIso8601String();
            $now = $this->money($currentGroups->get($key, collect()), $currency);
            $before = $this->money($priorGroups->get($key, collect()), $currency);
            $future = $cursor->gte($to);
            $points[] = [
                'label' => $bucket === 'hour' ? $cursor->format('H:00') : $cursor->toDateString(),
                'occurred_at' => $key, 'future' => $future,
                'value' => $future ? null : $now['normalized_total'], 'prior_value' => $future ? null : $before['normalized_total'],
                'payments_count' => $now['payments_count'], 'prior_payments_count' => $before['payments_count'],
                'average_ticket' => $future ? null : $this->average($now), 'prior_average_ticket' => $future ? null : $this->average($before),
                'source_breakdown' => $now['native'], 'prior_source_breakdown' => $before['native'],
                'fx_coverage' => $now['fx_coverage'], 'prior_fx_coverage' => $before['fx_coverage'],
            ];
            $cursor = match ($bucket) {
                'hour' => $cursor->addHour(), 'week' => $cursor->addWeek(), 'month' => $cursor->addMonth(), default => $cursor->addDay(),
            };
        }

        return [
            'product' => $product, 'label' => $label, 'basis' => 'provisional_external_cash',
            'selected' => $selected, 'prior' => $prior, 'average_ticket' => $this->average($selected),
            'change_percent' => $selected['normalized_total'] !== null && $prior['normalized_total'] > 0
                ? round(100 * ($selected['normalized_total'] - $prior['normalized_total']) / $prior['normalized_total'], 1) : null,
            'wallet_funded' => $this->money($this->within($wallet, $from, $to), $currency),
            'trend' => ['window' => $window, 'bucket' => $bucket, 'points' => $points],
            'peak_hours' => $peak ? $this->peak($current, $from, $to, $currency, $window) : null,
        ];
    }

    private function within(Collection $rows, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $rows->filter(fn ($r) => $r->instant->gte($from) && $r->instant->lt($to));
    }

    private function start(CarbonImmutable $date, string $bucket): CarbonImmutable
    {
        return match ($bucket) {
            'hour' => $date->startOfHour(), 'week' => $date->startOfWeek(CarbonImmutable::MONDAY),
            'month' => $date->startOfMonth(), default => $date->startOfDay(),
        };
    }

    private function money(Collection $rows, string $currency): array
    {
        $missing = $rows->whereNull('normalized_minor');

        return [
            'native' => $rows->groupBy('currency')->map(fn ($r) => $r->sum('native_minor') / 100)->sortKeys()->all(),
            'normalized_total' => $missing->isNotEmpty() ? null : $rows->sum('normalized_minor') / 100,
            'normalized_currency' => $currency, 'payments_count' => $rows->count(),
            'fx_coverage' => ['complete' => $missing->isEmpty(), 'missing_payments_count' => $missing->count(),
                'missing_currencies' => $missing->pluck('currency')->unique()->values()->all()],
        ];
    }

    private function average(array $money): ?float
    {
        return $money['normalized_total'] === null ? null : ($money['payments_count'] > 0 ? round($money['normalized_total'] / $money['payments_count'], 2) : 0.0);
    }

    private function peak(Collection $rows, CarbonImmutable $from, CarbonImmutable $to, string $currency, array $window): array
    {
        $local = fn ($r) => $r->instant->setTimezone('Africa/Nairobi');
        $groups = $rows->groupBy(fn ($r) => ($local($r)->dayOfWeekIso - 1).'-'.$local($r)->hour);
        $cells = [];
        for ($day = 0; $day < 7; $day++) {
            for ($hour = 0; $hour < 24; $hour++) {
                $money = $this->money($groups->get($day.'-'.$hour, collect()), $currency);
                $cells[] = ['dow' => $day, 'hour' => $hour, 'value' => $money['normalized_total'],
                    'payments_count' => $money['payments_count'], 'source_breakdown' => $money['native'], 'fx_coverage' => $money['fx_coverage']];
            }
        }
        abort_if($from->diffInDays($to) > 9000, 422, 'Choose a shorter period for peak hours; the range exceeds 9,000 days.');
        $dates = collect();
        for ($day = $from->startOfDay(); $day->lt($to); $day = $day->addDay()) {
            $dates->push($day);
        }
        $weekdays = collect(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'])->map(function ($label, $dow) use ($rows, $local, $dates, $currency) {
            $group = $rows->filter(fn ($r) => $local($r)->dayOfWeekIso - 1 === $dow);
            $money = $this->money($group, $currency);
            $active = $group->map(fn ($r) => $local($r)->toDateString())->unique();
            $days = $dates->filter(fn ($d) => $d->dayOfWeekIso - 1 === $dow)->map(fn ($d) => $d->toDateString());
            $top = $group->groupBy(fn ($r) => match (mb_strtolower(trim($r->country))) {
                'rw', 'rwanda' => 'rwanda', default => mb_strtolower(trim($r->country))
            })
                ->map(fn ($r, $country) => ['country' => $country === 'rwanda' ? 'Rwanda' : $r->first()->country, ...$this->money($r, $currency)])
                ->sortByDesc(fn ($r) => $r['normalized_total'] ?? -1)->first();

            return ['dow' => $dow, 'label' => $label, 'value' => $money['normalized_total'], 'payments_count' => $money['payments_count'],
                'average_ticket' => $this->average($money), 'occurrences' => $days->count(), 'active_days' => $active->count(),
                'gap_count' => $days->diff($active)->count(), 'gap_dates' => $days->diff($active)->values()->all(),
                'top_country' => $top ? ['country' => $top['country'], 'value' => $top['normalized_total'], 'payments_count' => $top['payments_count'], 'average_ticket' => $this->average($top)] : null];
        })->all();

        return ['window' => $window, 'timezone' => 'Africa/Nairobi', 'target_currency' => $currency, 'cells' => $cells, 'weekdays' => $weekdays];
    }
}
