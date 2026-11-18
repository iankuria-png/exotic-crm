<?php

namespace App\Services\Collections;

use App\Models\Collections\CollectionMatchFeedback;
use App\Models\Collections\CollectionReceipt;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * How often the profile suggestion matched the profile staff actually activated.
 * Read-only: compares the saved suggestion (or a replay of the current rules) with real outcomes.
 */
class CollectionMatchAccuracyService
{
    public const TIMEZONE = 'Africa/Nairobi';

    public const RULES = [
        'current_purchase' => 'Saved purchase for this code',
        'reviewed_payer_link' => 'Staff-reviewed payer link',
        'several_profiles_due' => 'Several-profile payer · due profile',
        'staff_confirmed_payer' => 'Payer confirmed for this profile before',
        'payment_history_3plus' => '3 or more earlier payments',
        'advert_phone' => 'Advert phone matches payer',
        'payment_history' => '1–2 earlier payments',
        'typed_reference' => 'Payer typed the profile name',
    ];

    public const BANDS = ['95+', '90–94', '81–89', '80', '65–79', '50–64', '1–49'];

    public function report(array $accountIds, CarbonImmutable $from, CarbonImmutable $to, bool $fresh = false): array
    {
        sort($accountIds);
        $key = 'collection-match-accuracy:v1:'.md5(json_encode([$accountIds, $from->toIso8601String(), $to->toIso8601String()]));
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, 300, fn () => $this->build($accountIds, $from, $to));
    }

    public function build(array $accountIds, CarbonImmutable $from, CarbonImmutable $to, ?callable $predictor = null): array
    {
        $receipts = $this->receipts($accountIds, $from, $to);
        $outcomes = $this->outcomes($receipts);
        $feedback = CollectionMatchFeedback::whereIn('receipt_id', $receipts->pluck('id'))->get()->keyBy(fn ($f) => $f->receipt_id.':'.$f->client_id);
        $totals = ['receipts' => $receipts->count(), 'with_outcome' => 0, 'suggested' => 0, 'agreed' => 0, 'disagreed' => 0, 'in_list_not_top' => 0, 'no_suggestion' => 0];
        $auto = ['activated' => 0, 'moved_by_staff' => 0];
        $wouldAuto = ['count' => 0, 'correct' => 0];
        $bands = array_fill_keys(self::BANDS, ['n' => 0, 'correct' => 0]);
        $rules = [];
        $guards = [];
        $daily = [];
        $cases = ['disagreed' => [], 'no_suggestion' => []];
        $explained = 0;
        for ($day = $from->setTimezone(self::TIMEZONE)->startOfDay(); $day->lte($to->setTimezone(self::TIMEZONE)); $day = $day->addDay()) {
            $daily[$day->toDateString()] = ['date' => $day->toDateString(), 'with_outcome' => 0, 'suggested' => 0, 'agreed' => 0];
        }
        foreach ($receipts as $r) {
            $truth = $outcomes[$r->id] ?? null;
            $matching = $predictor ? $predictor($r) : data_get($r->candidate_snapshot, 'matching');
            if ($r->allocation && $r->allocation->decision_source === 'automatic') {
                $auto['activated']++;
                if ($truth['moved'] ?? false) {
                    $auto['moved_by_staff']++;
                }
            }
            foreach ($matching['guards'] ?? [] as $guard) {
                $guards[$guard] = ($guards[$guard] ?? 0) + 1;
            }
            if (! $truth || ! $truth['clients']) {
                continue;
            }
            $chosen = $truth['clients'];
            $totals['with_outcome']++;
            $day = CarbonImmutable::parse($r->provider_at ?? $r->created_at)->setTimezone(self::TIMEZONE)->toDateString();
            $daily[$day] ??= ['date' => $day, 'with_outcome' => 0, 'suggested' => 0, 'agreed' => 0];
            $daily[$day]['with_outcome']++;
            $suggestions = collect($matching['suggestions'] ?? [])->filter(fn ($s) => ($s['score'] ?? 0) > 0)->sortByDesc('score')->values();
            $top = $suggestions->first();
            if (! empty($matching['selected_client_id'])) {
                $wouldAuto['count']++;
                $wouldAuto['correct'] += in_array((int) $matching['selected_client_id'], $chosen, true) ? 1 : 0;
            }
            $reason = null;
            foreach ($chosen as $id) {
                $reason ??= $feedback[$r->id.':'.$id]->reason ?? null;
            }
            if (! $top) {
                $totals['no_suggestion']++;
                $cases['no_suggestion'][] = $this->case($r, null, $chosen, $reason, $truth);

                continue;
            }
            $totals['suggested']++;
            $daily[$day]['suggested']++;
            $agreed = in_array((int) $top['id'], $chosen, true);
            $band = $this->band((int) $top['score']);
            $bands[$band]['n']++;
            $rule = $this->primaryRule($top);
            $rules[$rule] ??= ['n' => 0, 'correct' => 0];
            $rules[$rule]['n']++;
            if ($agreed) {
                $totals['agreed']++;
                $daily[$day]['agreed']++;
                $bands[$band]['correct']++;
                $rules[$rule]['correct']++;
            } else {
                $totals['disagreed']++;
                $explained += $reason ? 1 : 0;
                if ($suggestions->contains(fn ($s) => in_array((int) $s['id'], $chosen, true))) {
                    $totals['in_list_not_top']++;
                }
                $cases['disagreed'][] = $this->case($r, $top, $chosen, $reason, $truth);
            }
        }
        $ids = collect($cases)->flatten(1)->flatMap(fn ($c) => [...$c['chosen'], $c['suggested']['id'] ?? null])->filter()->unique();
        $names = DB::table('clients')->whereIn('id', $ids)->pluck('name', 'id');
        foreach ($cases as $kind => $rows) {
            usort($rows, fn ($a, $b) => strcmp($b['at'] ?? '', $a['at'] ?? ''));
            $cases[$kind] = array_map(function ($c) use ($names) {
                $c['chosen'] = array_map(fn ($id) => ['id' => $id, 'name' => $names[$id] ?? 'Profile '.$id], $c['chosen']);
                if ($c['suggested']) {
                    $c['suggested']['name'] = $names[$c['suggested']['id']] ?? 'Profile '.$c['suggested']['id'];
                }

                return $c;
            }, array_slice($rows, 0, 40));
        }
        $reasonCounts = $feedback->filter(fn ($f) => ! $f->agreed && $f->reason)->groupBy('reason')->map->count();
        $rate = fn ($a, $b) => $b ? round($a / $b, 4) : null;

        return [
            'range' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'timezone' => self::TIMEZONE],
            'generated_at' => now()->toIso8601String(), 'mode' => $predictor ? 'replay_current_rules' : 'saved_suggestions',
            'totals' => $totals,
            'rates' => ['coverage' => $rate($totals['suggested'], $totals['with_outcome']), 'agreement' => $rate($totals['agreed'], $totals['with_outcome']),
                'precision' => $rate($totals['agreed'], $totals['suggested'])],
            'automatic' => $auto + ['accuracy' => $auto['activated'] ? round(1 - $auto['moved_by_staff'] / $auto['activated'], 4) : null],
            'would_auto' => $wouldAuto + ['precision' => $rate($wouldAuto['correct'], $wouldAuto['count'])],
            'bands' => collect($bands)->map(fn ($v, $k) => ['band' => $k] + $v + ['precision' => $rate($v['correct'], $v['n'])])->values()->all(),
            'rules' => collect($rules)->map(fn ($v, $k) => ['rule' => $k, 'label' => self::RULES[$k] ?? 'Other evidence'] + $v + ['precision' => $rate($v['correct'], $v['n'])])
                ->sortByDesc('n')->values()->all(),
            'guards' => collect($guards)->map(fn ($n, $k) => ['guard' => $k, 'count' => $n])->sortByDesc('count')->values()->all(),
            'daily' => array_values($daily),
            'reasons' => collect(CollectionMatchFeedbackService::REASONS)->map(fn ($label, $k) => ['reason' => $k, 'label' => $label, 'count' => (int) ($reasonCounts[$k] ?? 0)])->values()->all(),
            'feedback' => ['disagreements' => $totals['disagreed'], 'explained' => $explained],
            'cases' => $cases,
        ];
    }

    /** Receipts in the window that can activate service (archives and test rows excluded). */
    public function receipts(array $accountIds, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return CollectionReceipt::with('allocation')->whereIn('account_id', $accountIds)->where('historical_only', false)
            ->where(fn ($q) => $q->whereBetween('provider_at', [$from, $to])->orWhere(fn ($q) => $q->whereNull('provider_at')->whereBetween('created_at', [$from, $to])))
            ->orderBy('id')->limit(5000)
            ->get(['id', 'account_id', 'principal_minor', 'currency', 'provider_at', 'created_at', 'reference_norm', 'candidate_snapshot', 'decision_state', 'funds_state']);
    }

    /** @return array<int, array{clients: int[], source: string, moved: bool}> */
    public function outcomes(Collection $receipts): array
    {
        $out = [];
        if ($receipts->isEmpty()) {
            return $out;
        }
        $ids = $receipts->pluck('id')->all();
        foreach (array_chunk($ids, 500) as $chunk) {
            DB::table('collection_sales_items as i')->join('collection_sales_sessions as s', 's.id', '=', 'i.session_id')
                ->whereIn('s.receipt_id', $chunk)->whereNotIn('i.state', ['canceled', 'draft'])->get(['s.receipt_id', 'i.client_id'])
                ->each(function ($row) use (&$out) {
                    $out[$row->receipt_id] ??= ['clients' => [], 'source' => 'receipt_sale', 'moved' => false];
                    $out[$row->receipt_id]['clients'][] = (int) $row->client_id;
                });
        }
        $moved = DB::table('collection_corrections as c')->join('collection_allocations as a', 'a.id', '=', 'c.allocation_id')
            ->whereIn('a.receipt_id', $ids)->where('c.action', 'reassign')->whereIn('c.state', ['verified', 'confirmed'])->pluck('a.receipt_id')->flip();
        foreach ($receipts as $r) {
            $client = (int) data_get($r->allocation?->purchase_snapshot, 'client_id');
            if ($client && $r->allocation->state !== 'unassigned' && ! isset($out[$r->id])) {
                $out[$r->id] = ['clients' => [$client], 'source' => 'allocation_'.$r->allocation->decision_source, 'moved' => isset($moved[$r->id])];
            }
        }
        $byRef = [];
        foreach ($receipts as $r) {
            $byRef[$r->account_id][$r->reference_norm] = $r->id;
        }
        foreach (DB::table('collection_receipt_aliases')->whereIn('receipt_id', $ids)->get(['receipt_id', 'account_id', 'reference']) as $alias) {
            $byRef[$alias->account_id][$alias->reference] = (int) $alias->receipt_id;
        }
        $platforms = DB::table('collection_account_platforms')->whereIn('account_id', array_keys($byRef))->get(['account_id', 'platform_id'])->groupBy('account_id');
        foreach ($byRef as $account => $refs) {
            $accountPlatforms = ($platforms[$account] ?? collect())->pluck('platform_id')->all();
            foreach (array_chunk(array_keys($refs), 300) as $chunk) {
                Payment::query()->withoutReceiptDuplicates()->whereIn('platform_id', $accountPlatforms)->whereIn('status', Payment::SUCCESSFUL_STATUSES)
                    ->whereNotNull('client_id')->where(fn ($q) => $q->whereIn('reference_root', $chunk)->orWhereIn('transaction_reference', $chunk)->orWhereIn('reference_number', $chunk))
                    ->get(['id', 'client_id', 'reference_root', 'transaction_reference', 'reference_number'])
                    ->each(function ($p) use (&$out, $refs) {
                        foreach ([$p->reference_root, $p->transaction_reference, $p->reference_number] as $ref) {
                            if ($ref !== null && isset($refs[$ref])) {
                                $rid = $refs[$ref];
                                $out[$rid] ??= ['clients' => [], 'source' => 'recorded_payment', 'moved' => false];
                                if ($out[$rid]['source'] === 'recorded_payment' && ! in_array((int) $p->client_id, $out[$rid]['clients'], true)) {
                                    $out[$rid]['clients'][] = (int) $p->client_id;
                                }

                                return;
                            }
                        }
                    });
            }
        }
        foreach ($out as &$row) {
            $row['clients'] = array_values(array_unique($row['clients']));
        }

        return $out;
    }

    public function band(int $score): string
    {
        return match (true) {
            $score >= 95 => '95+', $score >= 90 => '90–94', $score > 80 => '81–89', $score === 80 => '80',
            $score >= 65 => '65–79', $score >= 50 => '50–64', default => '1–49',
        };
    }

    /** The rule that produced a suggestion's score; older saved suggestions are read from their reasons. */
    public function primaryRule(array $suggestion): string
    {
        $rules = $suggestion['rules'] ?? [];
        if (! $rules) {
            $text = implode(' ', $suggestion['reasons'] ?? []);
            $rules = array_keys(array_filter([
                'current_purchase' => str_contains($text, 'current reviewed purchase'),
                'reviewed_payer_link' => str_contains($text, 'previously reviewed this payer'),
                'staff_confirmed_payer' => ($suggestion['source'] ?? null) === 'staff_confirmed_sms',
                'payment_history_3plus' => (bool) preg_match('/\b([3-9]|\d{2,}) prior cash payments/', $text),
                'advert_phone' => str_contains($text, 'advert phone'),
                'payment_history' => (bool) preg_match('/\b[12] prior cash payment/', $text),
                'typed_reference' => str_contains($text, 'payment reference names'),
            ]));
        }
        foreach (array_keys(self::RULES) as $rule) {
            if (in_array($rule, $rules, true)) {
                return $rule;
            }
        }

        return 'other';
    }

    private function case(CollectionReceipt $r, ?array $top, array $chosen, ?string $reason, array $truth): array
    {
        return ['receipt_id' => $r->id, 'at' => ($r->provider_at ?? $r->created_at)?->toIso8601String(), 'amount_minor' => (int) $r->principal_minor,
            'currency' => $r->currency, 'suggested' => $top ? ['id' => (int) $top['id'], 'score' => (int) $top['score'], 'rule' => $this->primaryRule($top)] : null,
            'chosen' => $chosen, 'reason' => $reason, 'reason_label' => CollectionMatchFeedbackService::REASONS[$reason] ?? null,
            'source' => $truth['source'], 'automatic_moved' => $truth['moved']];
    }
}
