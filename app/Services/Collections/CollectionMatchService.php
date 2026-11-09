<?php

namespace App\Services\Collections;

use App\Models\Collections\CollectionPayerLink;
use App\Models\Collections\CollectionReceipt;

/** Shared advisory evidence. Never reserves a purchase or impersonates staff. */
class CollectionMatchService
{
    public function evidence(CollectionReceipt $receipt, array $history, array $payer, array $valid): array
    {
        // A machine-selected catalogue intent cannot raise its own identity score.
        $valid = array_values(array_filter($valid, fn ($intent) => $intent->authority !== 'matched_catalogue'));
        $platforms = $history['scope']['platform_ids'];
        $links = $receipt->payer_fingerprint ? CollectionPayerLink::where('account_id', $receipt->account_id)
            ->where('payer_fingerprint', $receipt->payer_fingerprint)->whereNotNull('reviewed_at')
            ->whereNull('revoked_at')->where('valid_until', '>', now())->get() : collect();
        $phoneClients = $receipt->payer_phone ? $this->selectable()->whereIn('platform_id', $platforms)->where('phone_normalized', $receipt->payer_phone)->get() : collect();
        $counts = $history['client_counts'];
        $ids = array_unique(array_merge(array_keys($counts), $links->pluck('client_id')->all(), $phoneClients->pluck('id')->all(), array_map(fn ($i) => $i->client_id, $valid)));
        $clients = $this->selectable()->whereIn('platform_id', $platforms)->whereIn('id', $ids)->get()->keyBy('id');
        $excluded = app(CollectionMatchExclusionService::class)->clientIds($receipt);
        $evidenceIds = [];
        $suggestions = [];
        $unresolved = count(array_diff($ids, $clients->keys()->all(), $excluded)) > 0;
        foreach ($clients as $client) {
            $score = 0;
            $reasons = [];
            $link = $links->first(fn ($l) => (int) $l->client_id === $client->id
                && (int) $l->wp_user_id === (int) $client->wp_user_id && (int) $l->wp_post_id === (int) $client->wp_post_id);
            if ($link) {
                $score = 95;
                $reasons[] = 'A staff member previously reviewed this payer for this profile.';
            }
            if ($count = ($counts[$client->id] ?? 0)) {
                $score = max($score, $count >= 3 ? 90 : 65);
                $reasons[] = $count.' prior cash '.($count === 1 ? 'payment is' : 'payments are').' linked to this profile.';
            }
            if ($phoneClients->contains('id', $client->id)) {
                $score = max($score, 80);
                $reasons[] = 'The full SMS payer phone matches this profile’s advert phone.';
            }
            if (count($valid) === 1 && (int) $valid[0]->client_id === $client->id) {
                $score = max($score, 95);
                $reasons[] = 'A current reviewed purchase names this profile for this receipt.';
            }
            if (! $score) {
                continue;
            }
            if (in_array((int) $client->id, $excluded, true)) {
                $score = 0;
                $reasons[] = 'A staff correction rejected this payer-to-profile match. A fresh explicit review is required.';
            } else {
                $evidenceIds[] = $client->id;
            }
            $suggestions[] = ['id' => $client->id, 'name' => $client->name, 'platform_id' => (int) $client->platform_id, 'wp_post_id' => (int) $client->wp_post_id] + ['score' => $score, 'reasons' => $reasons];
        }
        $conflict = count($evidenceIds) > 1 || $unresolved || $history['identity_conflict'] || ! $history['complete'] || ! $payer['complete'] || (bool) $receipt->conflicts;
        if ($conflict) {
            foreach ($suggestions as &$suggestion) {
                $suggestion['score'] = min(49, $suggestion['score']);
                $suggestion['reasons'][] = ! $history['complete'] ? 'History coverage is incomplete; choose the profile explicitly.' : 'The available identity evidence needs a staff choice.';
            }
            unset($suggestion);
        }
        usort($suggestions, fn ($a, $b) => $b['score'] <=> $a['score']);
        $positive = array_values(array_filter($suggestions, fn ($suggestion) => $suggestion['score'] > 0));
        $recommended = ! $conflict && count($positive) === 1 && $positive[0]['score'] >= 80 ? $positive[0]['id'] : null;

        return ['suggestions' => $suggestions, 'recommended_client_id' => $recommended,
            'needs_choice' => $conflict, 'clients' => $clients, 'rule_version' => 'collection-match-v2'];
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
        $result['selected_client_id'] = self::select($result['suggestions'], $result['needs_choice'], $threshold);
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

    private function selectable()
    {
        return app(CollectionPurchaseService::class)->selectableClients();
    }
}
