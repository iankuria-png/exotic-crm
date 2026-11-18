<?php

namespace App\Services\Collections;

use App\Models\Collections\CollectionAccount;
use App\Models\Collections\CollectionReceipt;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Customer-submitted payment claims checked against the receiving phone's own receipts.
 * Ian, 18 Nov 2026: FLAG ONLY — a failed check never holds, cancels or changes a subscription.
 */
class CollectionClaimCheckService
{
    public const MAX_CLAIMS = 600;

    public const MAX_RECEIPTS = 4000;

    /** Accounts whose customers self-submit the provider transaction ID. */
    public function supports(CollectionAccount $account): bool
    {
        return $account->provider_key === 'mtn_momo_gh' && $account->country === 'GH';
    }

    public function forAccount(CollectionAccount $account, int $days = 14, bool $flaggedOnly = true): array
    {
        if (! $this->supports($account)) {
            throw new CollectionException('claim_checks_not_supported', 422);
        }
        $platforms = DB::table('collection_account_platforms')->where('account_id', $account->id)->whereNull('valid_until')->pluck('platform_id')->map(fn ($id) => (int) $id)->all();
        $cutover = DB::table('collection_devices as d')->join('collection_sim_bindings as b', 'b.device_id', '=', 'd.id')
            ->where('b.account_id', $account->id)->whereNull('d.revoked_at')->min('d.cutover_at');
        $empty = ['account_id' => $account->id, 'claims' => [], 'counts' => [], 'complete' => true, 'checked_from' => null, 'checked_at' => now()->toIso8601String()];
        if (! $platforms || ! $cutover) {
            return $empty;
        }
        // Claims before the phone was capturing cannot be proven either way.
        $from = Carbon::parse($cutover, 'UTC')->max(now()->subDays(max(1, min($days, 90))));
        $empty['checked_from'] = $from->toIso8601String();
        $grace = now()->subMinutes((int) config('collection.claim_check_grace_minutes', 30));

        $payments = Payment::with(['client:id,name,wp_post_id,platform_id', 'deal:id,status,plan_type,activated_at,expires_at'])->withoutReceiptDuplicates()
            ->whereIn('platform_id', $platforms)->whereIn('status', Payment::SUCCESSFUL_STATUSES)->where('amount', '>', 0)
            ->where('currency', $account->currency)->where('created_at', '>=', $from)
            // Self-submitted and staff-confirmed manual claims only; provider-verified gateways prove themselves.
            ->where(fn ($q) => $q->whereNull('provider_key')->orWhere('provider_key', 'manual_confirmation'))
            ->orderBy('id')->limit(self::MAX_CLAIMS + 1)->get();
        $complete = $payments->count() <= self::MAX_CLAIMS;
        $payments = $payments->take(self::MAX_CLAIMS);

        $receipts = CollectionReceipt::where('account_id', $account->id)->where('currency', $account->currency)
            ->where('created_at', '>=', $from->copy()->subDays(2))->orderBy('id')->limit(self::MAX_RECEIPTS + 1)->get();
        $complete = $complete && $receipts->count() <= self::MAX_RECEIPTS;
        $receipts = $receipts->take(self::MAX_RECEIPTS)->keyBy('id');
        $byReference = [];
        foreach ($receipts as $r) {
            $byReference[$r->reference_norm] = $r->id;
        }
        foreach (DB::table('collection_receipt_aliases')->where('account_id', $account->id)->whereIn('receipt_id', $receipts->keys())->get() as $alias) {
            $byReference[$alias->reference] ??= (int) $alias->receipt_id;
        }

        $claimsByReceipt = [];
        $rows = [];
        foreach ($payments as $p) {
            $typed = $this->typed($p);
            $receiptId = null;
            foreach ($this->references($p) as $reference) {
                if (isset($byReference[$reference])) {
                    $receiptId = $byReference[$reference];
                    break;
                }
            }
            if ($receiptId) {
                $claimsByReceipt[$receiptId][] = $p->id;
            }
            $rows[$p->id] = ['payment' => $p, 'typed' => $typed, 'receipt_id' => $receiptId];
        }
        $claimed = array_fill_keys(array_keys($claimsByReceipt), true);
        $unclaimed = $receipts->reject(fn ($r) => isset($claimed[$r->id]) || in_array($r->funds_state, ['reversed', 'disputed'], true));
        $evidence = app(CollectionPayerEvidenceService::class);
        $names = [];

        $out = [];
        $counts = ['verified' => 0, 'pending' => 0, 'no_receipt' => 0, 'short_paid' => 0, 'receipt_reused' => 0, 'reversed' => 0];
        foreach ($rows as $row) {
            $p = $row['payment'];
            $receipt = $row['receipt_id'] ? $receipts[$row['receipt_id']] : null;
            $claimMinor = (int) round((float) $p->amount * 100);
            $suggestion = null;
            if ($receipt) {
                $siblings = $claimsByReceipt[$receipt->id];
                $claimedMinor = (int) round($payments->whereIn('id', $siblings)->sum(fn ($s) => (float) $s->amount) * 100);
                $status = match (true) {
                    in_array($receipt->funds_state, ['reversed', 'disputed'], true) => 'reversed',
                    count($siblings) > 1 && $claimedMinor > $receipt->principal_minor => 'receipt_reused',
                    $claimedMinor > $receipt->principal_minor => 'short_paid',
                    default => 'verified',
                };
            } elseif ($p->created_at->gt($grace)) {
                $status = 'pending';
            } else {
                $status = 'no_receipt';
                $suggestion = $this->suggest($p, $row['typed'], $claimMinor, $unclaimed, $evidence, $names);
            }
            $counts[$status]++;
            if ($flaggedOnly && in_array($status, ['verified', 'pending'], true)) {
                continue;
            }
            $out[] = ['status' => $status, 'payment_id' => $p->id, 'amount' => $p->amount, 'currency' => $p->currency,
                'claimed_at' => $p->created_at?->toIso8601String(), 'typed_reference' => $row['typed'],
                'client' => $p->client ? ['id' => $p->client->id, 'name' => $p->client->name, 'wp_post_id' => $p->client->wp_post_id] : null,
                'deal' => $p->deal?->only(['id', 'status', 'plan_type', 'expires_at']),
                'receipt' => $receipt ? $this->receiptRow($receipt) + ['claim_count' => count($claimsByReceipt[$receipt->id]),
                    'claimed_total_minor' => (int) round($payments->whereIn('id', $claimsByReceipt[$receipt->id])->sum(fn ($s) => (float) $s->amount) * 100)] : null,
                'suggestion' => $suggestion];
        }

        return ['account_id' => $account->id, 'claims' => array_reverse($out), 'counts' => $counts, 'complete' => $complete,
            'checked_from' => $from->toIso8601String(), 'grace_minutes' => (int) config('collection.claim_check_grace_minutes', 30), 'checked_at' => now()->toIso8601String()];
    }

    /** Exact transaction-ID candidates in what the customer typed. */
    public function references(Payment $p): array
    {
        $refs = [];
        foreach ([$p->transaction_reference, $p->reference_number, $p->reference_root] as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $refs[] = $value;
            preg_match_all('/(?<!\d)\d{9,13}(?!\d)/', $value, $m);
            array_push($refs, ...$m[0]);
        }

        return array_values(array_unique($refs));
    }

    private function typed(Payment $p): string
    {
        return mb_substr(trim((string) ($p->transaction_reference ?: $p->reference_number ?: $p->reference_root)), 0, 120);
    }

    /** Advisory only: one-edit ID typo, or payer name typed instead of the ID, near the claim time. */
    private function suggest(Payment $p, string $typed, int $claimMinor, Collection $unclaimed, CollectionPayerEvidenceService $evidence, array &$names): ?array
    {
        $at = $p->created_at;
        $window = $unclaimed->filter(function ($r) use ($at) {
            $paid = $r->provider_at ?? $r->first_received_at;

            return $paid && $paid->gte($at->copy()->subHours(48)) && $paid->lte($at->copy()->addHour());
        });
        if ($window->isEmpty()) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $typed);
        $missing = $window->reject(fn ($r) => array_key_exists($r->id, $names));
        if ($missing->isNotEmpty()) {
            foreach ($evidence->forReceipts($missing) as $id => $payer) {
                $names[$id] = $payer['name'] ?? $payer['party'] ?? null;
            }
        }
        $typedWords = $this->words($typed);
        $best = null;
        foreach ($window as $r) {
            $reasons = [];
            if (strlen($digits) >= 8 && $this->oneEdit($digits, $r->reference_norm)) {
                $reasons[] = 'transaction_id_one_digit_off';
            }
            if ($typedWords && array_intersect($typedWords, $this->words((string) ($names[$r->id] ?? '')))) {
                $reasons[] = 'payer_name_typed';
            }
            if (! $reasons) {
                continue;
            }
            if ($r->principal_minor === $claimMinor) {
                $reasons[] = 'same_amount';
            }
            $score = count($reasons) + (in_array('transaction_id_one_digit_off', $reasons, true) ? 1 : 0);
            if (! $best || $score > $best['score']) {
                $best = ['score' => $score, 'receipt' => $this->receiptRow($r) + ['payer_name' => $names[$r->id] ?? null], 'reasons' => $reasons];
            }
        }

        return $best;
    }

    private function receiptRow(CollectionReceipt $r): array
    {
        return ['id' => $r->id, 'reference' => $r->reference_norm, 'principal_minor' => $r->principal_minor, 'currency' => $r->currency,
            'funds_state' => $r->funds_state, 'paid_at' => ($r->provider_at ?? $r->first_received_at)?->toIso8601String()];
    }

    private function words(string $value): array
    {
        preg_match_all('/\p{L}{3,}/u', mb_strtolower($value), $m);

        return array_values(array_diff(array_unique($m[0]), ['from', 'vodafone', 'airtel', 'telecel', 'payment', 'exotic', 'site', 'the', 'and']));
    }

    private function oneEdit(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        $la = strlen($a);
        $lb = strlen($b);
        if (abs($la - $lb) > 1) {
            return false;
        }
        if ($la === $lb) {
            $diff = [];
            for ($i = 0; $i < $la; $i++) {
                if ($a[$i] !== $b[$i]) {
                    $diff[] = $i;
                }
            }

            return count($diff) === 1 || (count($diff) === 2 && $diff[1] === $diff[0] + 1 && $a[$diff[0]] === $b[$diff[1]] && $a[$diff[1]] === $b[$diff[0]]);
        }
        [$short, $long] = $la < $lb ? [$a, $b] : [$b, $a];
        for ($i = 0, $n = strlen($long); $i < $n; $i++) {
            if (substr($long, 0, $i).substr($long, $i + 1) === $short) {
                return true;
            }
        }

        return false;
    }
}
