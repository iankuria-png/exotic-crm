<?php

namespace App\Services\Collections;

use App\Models\Client;
use App\Models\Collections\CollectionPurchaseIntent;
use App\Models\Collections\CollectionReceipt;
use App\Models\Deal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Advisory read only. A reference insertion never claims funds or grants service. */
class CollectionReferenceSuggestionService
{
    public function forClient(User $actor, Client $client, Deal $deal, array $input): array
    {
        $accounts = array_values(array_intersect(
            app(CollectionAccessService::class)->accountIdsForView($actor, (int) $client->platform_id),
            DB::table('collection_account_platforms')->where('platform_id', $client->platform_id)
                ->where('valid_from', '<=', now())->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', now()))
                ->pluck('account_id')->all()
        ));
        sort($accounts);
        $search = strtoupper(preg_replace('/\s+/', '', trim($input['search'] ?? '')));
        $amount = (int) ($input['amount_minor'] ?? round((float) $deal->amount * 100));
        $scope = hash('sha256', json_encode([$actor->id, $client->id, $deal->id, $accounts, $deal->currency, $amount, $search]));
        $anchor = CarbonImmutable::now()->utc();
        $before = null;
        if (! empty($input['cursor'])) {
            try {
                $cursor = json_decode(Crypt::decryptString($input['cursor']), true, 512, JSON_THROW_ON_ERROR);
                if (($cursor['scope'] ?? null) !== $scope || ($cursor['expires'] ?? 0) < time()
                    || ! is_string($cursor['anchor'] ?? null) || ! is_string($cursor['before_time'] ?? null)
                    || ! is_int($cursor['before_id'] ?? null)) {
                    throw new \RuntimeException('Invalid cursor');
                }
                $anchor = CarbonImmutable::parse($cursor['anchor'])->utc();
                $before = [$cursor['before_time'], $cursor['before_id']];
            } catch (\Throwable $e) {
                throw ValidationException::withMessages(['cursor' => 'These payment suggestions expired or changed. Refresh them.']);
            }
        }
        $effective = 'COALESCE(provider_at, first_received_at, collection_receipts.created_at)';
        $query = CollectionReceipt::with(['account', 'allocation', 'sale.items'])->whereIn('account_id', $accounts)->where('currency', $deal->currency);
        if ($search !== '') {
            $query->where(fn ($q) => $q->where('reference_norm', $search)->orWhereExists(fn ($alias) => $alias->selectRaw('1')
                ->from('collection_receipt_aliases')->whereColumn('receipt_id', 'collection_receipts.id')
                ->whereColumn('account_id', 'collection_receipts.account_id')->where('reference', $search)));
        } else {
            $query->where('historical_only', false)->whereRaw($effective.' >= ?', [$anchor->subHours(48)->toDateTimeString()])
                ->whereRaw($effective.' <= ?', [$anchor->toDateTimeString()]);
        }
        if ($before) {
            $query->where(fn ($q) => $q->whereRaw($effective.' < ?', [$before[0]])
                ->orWhere(fn ($q) => $q->whereRaw($effective.' = ?', [$before[0]])->where('collection_receipts.id', '<', $before[1])));
        }
        $rows = $query->orderByRaw($effective.' DESC')->orderByDesc('collection_receipts.id')->limit(11)->get();
        $more = $rows->count() > 10;
        $rows = $rows->take(10)->values();
        $usage = app(CollectionReceiptStatusService::class)->forReceipts($rows);
        $payers = app(CollectionPayerEvidenceService::class)->forReceipts($rows);
        $items = [];
        $partial = false;
        $started = microtime(true);
        $scanned = 0;
        $lastScanned = null;
        $budget = 0.75; // Yield between receipts; never discard the remaining possibilities.
        $lookup = ['state' => $search === '' ? 'not_requested' : 'not_found', 'reference' => $search ?: null, 'reason' => null];
        foreach ($rows as $receipt) {
            if ($search === '' && $scanned > 0 && microtime(true) - $started >= $budget) {
                $more = true;
                break;
            }
            $scanned++;
            $lastScanned = $receipt;
            $reason = $this->unavailable($receipt, $usage[$receipt->id] ?? []);
            if ($reason) {
                if ($search !== '') {
                    $lookup = ['state' => 'unavailable', 'reference' => $search, 'reason' => $reason];
                }

                continue;
            }
            $payer = $payers[$receipt->id];
            // Same evidence-only preparation as the receipt drawer. Never evaluate/refresh a decision.
            $history = app(CollectionPaymentHistoryService::class)->forReceipt($receipt, $actor);
            $valid = [];
            $intents = CollectionPurchaseIntent::where('account_id', $receipt->account_id)->where('provider_reference', $receipt->reference_norm)
                ->whereNull('revoked_at')->latest('created_at')->limit(51)->get();
            foreach ($intents->take(50) as $intent) {
                try {
                    app(CollectionPurchaseService::class)->validate($intent, $receipt, false);
                    $valid[] = $intent;
                } catch (CollectionException $e) {
                    // Stale purchase choices do not establish identity.
                }
            }
            $matching = app(CollectionMatchService::class)->evidence($receipt, $history, $payer, $valid);
            $excluded = app(CollectionMatchExclusionService::class)->clientIds($receipt);
            $scopePlatforms = array_map('intval', $history['scope']['platform_ids']);
            $profiles = app(CollectionPurchaseService::class)->selectableClients()->whereIn('platform_id', $scopePlatforms)
                ->whereIn('id', array_column($matching['suggestions'], 'id'))->get()->keyBy('id');
            $possibilities = [];
            foreach ($matching['suggestions'] as $candidate) {
                $profile = $profiles->get($candidate['id']);
                if (! $profile || in_array((int) $profile->id, $excluded, true)) {
                    continue;
                }
                // Score zero can mean an alternative, not a rejected profile. Never filter by score.
                $possibilities[] = ['id' => $profile->id, 'name' => $profile->name, 'current_profile' => (int) $profile->id === (int) $client->id,
                    'role' => $candidate['role'] ?? (($candidate['score'] ?? 0) === 0 ? 'alternative' : 'suggested'),
                    'reasons' => array_values($candidate['reasons'] ?? [])];
            }
            $complete = ($history['complete'] ?? false) && ($payer['complete'] ?? false)
                && data_get($matching, 'learned_payer.complete', true) && $intents->count() < 51;
            $partial = $partial || ! $complete;
            if ($search === '' && ! collect($possibilities)->contains('current_profile', true)) {
                continue;
            }
            $amountReason = $amount !== (int) $receipt->principal_minor ? 'amount_mismatch' : null;
            $items[] = ['receipt_id' => $receipt->id, 'reference' => $receipt->reference_norm,
                'sender_name' => $payer['name'], 'sender_state' => $payer['name_state'],
                'principal_minor' => $receipt->principal_minor, 'currency' => $receipt->currency,
                'paid_at' => $receipt->provider_at?->toIso8601String(), 'received_at' => $receipt->first_received_at?->toIso8601String(),
                'time_basis' => $receipt->provider_at ? 'paid' : 'received', 'timezone' => $receipt->account->timezone ?: 'UTC',
                'account_label' => $receipt->account->label, 'possible_profiles' => $possibilities,
                'coverage' => $complete ? 'complete' : 'partial', 'use_code_available' => $amountReason === null,
                'unavailable_reason' => $amountReason, 'version' => (int) $receipt->version];
            if ($search !== '') {
                $lookup = ['state' => $amountReason ? 'unavailable' : 'selectable', 'reference' => $search, 'reason' => $amountReason];
            }
        }
        // Never guess when one normalized code identifies more than one receiving receipt.
        if ($search !== '' && $rows->count() > 1) {
            $items = [];
            $lookup = ['state' => 'unavailable', 'reference' => $search, 'reason' => 'conflicting'];
        }
        $last = $lastScanned;
        $next = $more && $last ? Crypt::encryptString(json_encode(['scope' => $scope, 'anchor' => $anchor->toIso8601String(),
            'before_time' => ($last->provider_at ?? $last->first_received_at ?? $last->created_at)->toDateTimeString(),
            'before_id' => (int) $last->id, 'expires' => time() + 300], JSON_THROW_ON_ERROR)) : null;

        return ['enabled' => true, 'items' => $items, 'next_cursor' => $next, 'scan_complete' => ! $more,
            'scanned_receipts' => $scanned, 'coverage' => $partial ? 'partial' : 'complete',
            'lookup' => $lookup, 'checked_at' => now()->toIso8601String()];
    }

    private function unavailable(CollectionReceipt $receipt, array $usage): ?string
    {
        if ($receipt->historical_only) {
            return 'historical';
        }
        if (in_array($receipt->funds_state, ['reversed', 'disputed'], true)) {
            return 'reversed';
        }
        if ($receipt->principal_minor <= 0 || $receipt->conflicts || ! in_array($receipt->funds_state, ['observed', 'statement_verified'], true)) {
            return 'conflicting';
        }
        if (! ($usage['complete'] ?? false)) {
            return 'incomplete';
        }
        if ($receipt->allocation || $receipt->sale) {
            return 'owned';
        }

        return ($usage['state'] ?? null) === 'unrecorded' ? null : 'used';
    }
}
