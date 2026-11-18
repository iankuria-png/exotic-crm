<?php

namespace App\Services\Collections;

use App\Models\Collections\CollectionPayerLink;
use App\Models\Collections\CollectionReceipt;

/** Shared advisory evidence. Never reserves a purchase or impersonates staff. */
class CollectionMatchService
{
    public const RULE_VERSION = 'collection-match-v4';

    /** Reasons that keep a clear suggestion for staff instead of automatic activation. */
    public const GUARDS = ['payer_funds_several_profiles', 'learned_payer_conflicts_with_other_evidence', 'suggested_profile_dormant'];

    public function evidence(CollectionReceipt $receipt, array $history, array $payer, array $valid): array
    {
        // A machine-selected catalogue intent cannot raise its own identity score.
        $valid = array_values(array_filter($valid, fn ($intent) => ! in_array($intent->authority, ['matched_catalogue', 'matched_receipt_history'], true)));
        $platforms = $history['scope']['platform_ids'];
        $links = $receipt->payer_fingerprint ? CollectionPayerLink::where('account_id', $receipt->account_id)
            ->where('payer_fingerprint', $receipt->payer_fingerprint)->whereNotNull('reviewed_at')
            ->whereNull('revoked_at')->where('valid_until', '>', now())->get() : collect();
        $phoneClients = $receipt->payer_phone ? $this->selectable()->whereIn('platform_id', $platforms)->where('phone_normalized', $receipt->payer_phone)->get() : collect();
        $counts = $history['client_counts'];
        $referenceClients = $this->referenceClients($payer['payer_reference'] ?? null, $platforms);
        $ids = array_unique(array_merge(array_keys($counts), $links->pluck('client_id')->all(), $phoneClients->pluck('id')->all(), array_map(fn ($i) => $i->client_id, $valid), $referenceClients->pluck('id')->all()));
        $clients = $this->selectable()->whereIn('platform_id', $platforms)->whereIn('id', $ids)->get()->keyBy('id');
        $excluded = app(CollectionMatchExclusionService::class)->clientIds($receipt);
        $evidenceIds = [];
        $suggestions = [];
        $guards = [];
        $unresolved = count(array_diff($ids, $clients->keys()->all(), $excluded)) > 0;
        foreach ($clients as $client) {
            $score = 0;
            $reasons = [];
            $rules = [];
            $link = $links->first(fn ($l) => (int) $l->client_id === $client->id
                && (int) $l->wp_user_id === (int) $client->wp_user_id && (int) $l->wp_post_id === (int) $client->wp_post_id);
            if ($link) {
                $score = 95;
                $rules[] = 'reviewed_payer_link';
                $reasons[] = 'A staff member previously reviewed this payer for this profile.';
            }
            if ($count = ($counts[$client->id] ?? 0)) {
                $score = max($score, $count >= 3 ? 90 : 65);
                $rules[] = $count >= 3 ? 'payment_history_3plus' : 'payment_history';
                $reasons[] = $count.' prior cash '.($count === 1 ? 'payment is' : 'payments are').' linked to this profile.';
            }
            if ($phoneClients->contains('id', $client->id)) {
                $score = max($score, 80);
                $rules[] = 'advert_phone';
                $reasons[] = 'The full SMS payer phone matches this profile’s advert phone.';
            }
            if ($referenceClients->contains('id', $client->id)) {
                // Below every automatic threshold: a typed name helps staff choose, never decides alone.
                $score = max($score, 60);
                $rules[] = 'typed_reference';
                $reasons[] = 'The payer’s payment reference names this profile.';
            }
            if (count($valid) === 1 && (int) $valid[0]->client_id === $client->id) {
                $score = max($score, 95);
                $rules[] = 'current_purchase';
                $reasons[] = 'A current reviewed purchase names this profile for this receipt.';
            }
            if (! $score) {
                continue;
            }
            if (in_array((int) $client->id, $excluded, true)) {
                $score = 0;
                $rules[] = 'staff_rejected';
                $reasons[] = 'A staff correction rejected this payer-to-profile match. A fresh explicit review is required.';
            } else {
                $evidenceIds[] = $client->id;
            }
            $suggestions[] = ['id' => $client->id, 'name' => $client->name, 'platform_id' => (int) $client->platform_id, 'wp_post_id' => (int) $client->wp_post_id]
                + ['score' => $score, 'raw_score' => $score, 'reasons' => $reasons, 'rules' => $rules];
        }
        $conflict = count($evidenceIds) > 1 || $unresolved || $history['identity_conflict'] || ! $history['complete'] || ! $payer['complete'] || (bool) $receipt->conflicts;
        if ($conflict) {
            foreach ($suggestions as &$suggestion) {
                $suggestion['score'] = min(49, $suggestion['score']);
                $suggestion['reasons'][] = ! $history['complete'] ? 'History coverage is incomplete; choose the profile explicitly.' : 'The available identity evidence needs a staff choice.';
            }
            unset($suggestion);
        }
        $learned = app(CollectionPackageHistoryService::class)->payer($receipt);
        $learnedIds = array_values(array_unique(array_map('intval', array_column($learned['clients'], 'client_id'))));
        $states = app(CollectionSubscriptionState::class)->forClients(array_merge(array_column($suggestions, 'id'), $learnedIds), $receipt->provider_at);
        if ($learnedIds) {
            $explicitIds = $links->filter(function ($link) {
                $client = $this->selectable()->find($link->client_id);

                return $client && (int) $link->wp_post_id === (int) $client->wp_post_id && (int) $link->wp_user_id === (int) $client->wp_user_id;
            })->pluck('client_id')->map(fn ($id) => (int) $id)->all();
            $authorityIds = array_values(array_unique([...$learnedIds, ...$explicitIds, ...array_map(fn ($i) => (int) $i->client_id, $valid)]));
            $learnedClients = $this->selectable()->whereIn('platform_id', $platforms)->whereIn('id', $authorityIds)->get()->keyBy('id');
            $states += app(CollectionSubscriptionState::class)->forClients(array_diff($authorityIds, array_keys($states)), $receipt->provider_at);
            $unclear = $learnedClients->count() !== count($authorityIds) || ! $learned['complete'] || ! $payer['complete']
                || (bool) $receipt->conflicts || count(array_intersect($authorityIds, $excluded)) > 0;
            // Independent evidence (phone, payment history, reviewed link) for a profile the payer has never been confirmed for.
            $independent = array_values(array_filter($suggestions, fn ($s) => ! in_array((int) $s['id'], $authorityIds, true)
                && ! in_array((int) $s['id'], $excluded, true) && ($s['raw_score'] ?? 0) >= 65));
            $several = count($authorityIds) > 1;
            $due = $several ? array_values(array_filter($authorityIds, fn ($id) => CollectionSubscriptionState::renewing($states[$id] ?? null)
                && ($states[$id]['last_amount_minor'] ?? null) === (int) $receipt->principal_minor)) : [];
            $pick = ! $unclear && ! $independent ? ($several ? (count($due) === 1 ? $due[0] : null) : $authorityIds[0]) : null;
            if ($several) {
                $guards[] = 'payer_funds_several_profiles';
            }
            if (! $unclear && ! $several && $independent) {
                $guards[] = 'learned_payer_conflicts_with_other_evidence';
            }
            $conflict = $pick === null;
            // Legacy Payment.phone is advisory: observed SMS plus exact staff confirmation is stronger authority.
            foreach ($suggestions as &$suggestion) {
                if ($conflict) {
                    $suggestion['score'] = min(49, $suggestion['score']);
                    if (in_array((int) $suggestion['id'], array_map('intval', array_column($independent, 'id')), true)) {
                        $suggestion['reasons'][] = 'This payer was confirmed for a different profile. Check which profile this payment is for.';
                    }
                } else {
                    $suggestion['score'] = 0;
                    $suggestion['role'] = 'alternative';
                    $suggestion['reasons'][] = 'Weaker evidence than the payer’s staff-confirmed profile.';
                }
            }
            unset($suggestion);
            foreach ($learnedClients as $client) {
                $existing = array_search($client->id, array_column($suggestions, 'id'), true);
                $paidFor = count($authorityIds);
                [$score, $reason, $rules] = match (true) {
                    $pick === $client->id && $several => [85, 'This payer pays for '.$paidFor.' profiles. This one is due now and its last package matches this amount.', ['staff_confirmed_payer', 'several_profiles_due']],
                    $pick === $client->id => [95, 'This SMS payer previously paid this profile in a staff-confirmed receipt.', ['staff_confirmed_payer']],
                    $pick !== null => [0, 'Also paid for by this payer, but not due now.', ['staff_confirmed_payer']],
                    $unclear => [49, 'Confirmed SMS payer evidence conflicts; choose the client explicitly.', ['staff_confirmed_payer']],
                    $several => [49, 'This payer pays for '.$paidFor.' profiles. Choose which one this payment is for.', ['staff_confirmed_payer']],
                    default => [49, 'This payer was confirmed for this profile, but other evidence points to a different profile.', ['staff_confirmed_payer']],
                };
                $row = ['id' => $client->id, 'name' => $client->name, 'platform_id' => (int) $client->platform_id,
                    'wp_post_id' => (int) $client->wp_post_id, 'score' => $score, 'raw_score' => $score, 'reasons' => [$reason], 'rules' => $rules, 'source' => 'staff_confirmed_sms']
                    + ($pick !== null && $pick !== $client->id ? ['role' => 'alternative'] : []);
                if ($existing === false) {
                    $suggestions[] = $row;
                } else {
                    $suggestions[$existing] = $row;
                }
            }
            $clients = $clients->union($learnedClients);
        }
        foreach ($suggestions as &$suggestion) {
            $state = $states[$suggestion['id']] ?? null;
            $suggestion['subscription'] = $state;
            // A phone alone on a profile with no recent paid service is often an older or duplicate profile.
            if (array_diff($suggestion['rules'], ['advert_phone', 'typed_reference']) === [] && in_array('advert_phone', $suggestion['rules'], true)
                && CollectionSubscriptionState::dormant($state) && $suggestion['score'] > 65) {
                $suggestion['score'] = 65;
                $suggestion['reasons'][] = 'No paid subscription in the last 60 days. The phone may belong to an older or duplicate profile.';
            }
            unset($suggestion['raw_score']);
        }
        unset($suggestion);
        usort($suggestions, fn ($a, $b) => [$b['score'], CollectionSubscriptionState::rank($a['subscription'])] <=> [$a['score'], CollectionSubscriptionState::rank($b['subscription'])]);
        $positive = array_values(array_filter($suggestions, fn ($suggestion) => $suggestion['score'] > 0));
        $recommended = ! $conflict && count($positive) === 1 && $positive[0]['score'] >= 80 ? $positive[0]['id'] : null;
        if (count($positive) === 1 && CollectionSubscriptionState::dormant($positive[0]['subscription'])) {
            $guards[] = 'suggested_profile_dormant';
        }

        return ['suggestions' => $suggestions, 'recommended_client_id' => $recommended, 'needs_choice' => $conflict, 'clients' => $clients,
            'rule_version' => self::RULE_VERSION, 'learned_payer' => $learned, 'guards' => array_values(array_unique($guards))];
    }

    public function automatic(CollectionReceipt $receipt): array
    {
        $history = app(CollectionPaymentHistoryService::class)->forAccountReceipt($receipt);
        $payer = app(CollectionPayerEvidenceService::class)->forReceipt($receipt);
        $valid = [];
        foreach (\App\Models\Collections\CollectionPurchaseIntent::where('account_id', $receipt->account_id)
            ->where('provider_reference', $receipt->reference_norm)->whereNull('revoked_at')->limit(50)->get() as $intent) {
            try {
                app(CollectionPurchaseService::class)->validate($intent, $receipt, true);
                $valid[] = $intent;
            } catch (CollectionException) {
                // A stale or incompatible purchase cannot provide current identity evidence.
            }
        }
        $result = $this->evidence($receipt, $history, $payer, $valid);
        $threshold = data_get($receipt->account->policy, 'match_threshold', 80);
        $result['threshold'] = $threshold;
        // Guards keep a clear suggestion for staff: one payer for several profiles, conflicting evidence or no recent paid service.
        $result['selected_client_id'] = $result['guards'] ? null : self::select($result['suggestions'], $result['needs_choice'], $threshold);
        unset($result['clients']);

        return $result;
    }

    /** Rule score is not a probability; automatic selection is strictly above threshold. */
    public static function select(array $suggestions, bool $conflict, mixed $threshold): ?int
    {
        $suggestions = array_values(array_filter($suggestions, fn ($candidate) => ($candidate['score'] ?? 0) > 0));
        if ($conflict || ! is_int($threshold) || $threshold < 0 || $threshold > 100 || count($suggestions) !== 1) {
            return null;
        }
        $candidate = $suggestions[0];

        return is_int($candidate['score'] ?? null) && $candidate['score'] > $threshold ? (int) $candidate['id'] : null;
    }

    /** Profiles whose name the payer typed as the MoMo reference; ambiguous names give no hint. */
    private function referenceClients(?string $reference, array $platforms)
    {
        $text = mb_strtolower(trim(explode(',', (string) $reference)[0]));
        $letters = preg_replace('/[^\p{L}]/u', '', $text);
        if (mb_strlen($letters) < 3 || ! $platforms || preg_match('/^(?:payment|exotic|site|subscription|subscribe|gift|ads?|advert)$/u', $letters)) {
            return collect();
        }
        $matches = $this->selectable()->whereIn('platform_id', $platforms)->where('name', 'like', '%'.mb_substr(preg_split('/[^\p{L}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY)[0], 0, 40).'%')->limit(25)->get()
            ->filter(fn ($c) => preg_replace('/[^\p{L}]/u', '', mb_strtolower((string) $c->name)) === $letters);

        return $matches->count() >= 1 && $matches->count() <= 2 ? $matches->values() : collect();
    }

    private function selectable()
    {
        return app(CollectionPurchaseService::class)->selectableClients();
    }
}
