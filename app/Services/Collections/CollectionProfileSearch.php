<?php

namespace App\Services\Collections;

use App\Models\Client;
use App\Models\Collections\CollectionPayerLink;
use App\Models\Collections\CollectionReceipt;
use App\Models\User;
use App\Services\ClientProfileUrlSearchService;
use Illuminate\Support\Facades\DB;

/**
 * Profile search for the receipt drawer, matching how staff search on the Clients page: pasted profile links
 * (resolved by the same ClientProfileUrlSearchService), names, phones, emails, slugs and IDs. Results stay
 * inside the receipt's receiving markets and the selectable-profile rules; each row says why it matched.
 */
class CollectionProfileSearch
{
    public const LIMIT = 20;

    public function search(CollectionReceipt $receipt, User $actor, string $term): array
    {
        $term = trim($term);
        $platforms = DB::table('collection_account_platforms')->where('account_id', $receipt->account_id)->whereNull('valid_until')
            ->pluck('platform_id')->map(fn ($id) => (int) $id)->all();
        $base = fn () => app(CollectionPurchaseService::class)->selectableClients()->whereIn('platform_id', $platforms);
        $resolution = null;
        $clients = collect();
        $reasons = [];

        if (preg_match('#^https?://#i', $term)) {
            // A stored permalink inside this payment's markets is the most direct link match.
            $direct = $base()->whereIn('wp_profile_permalink', array_unique([$term, rtrim($term, '/').'/', rtrim($term, '/')]))->limit(self::LIMIT)->get();
            if ($direct->isNotEmpty()) {
                return ['clients' => $this->rows($direct, $direct->mapWithKeys(fn ($c) => [$c->id => 'profile_link'])->all()),
                    'resolution' => ['mode' => 'exact', 'source' => 'stored_permalink', 'resolved_wp_post_id' => $direct->count() === 1 ? (int) $direct->first()->wp_post_id : null]];
            }
        }
        $link = app(ClientProfileUrlSearchService::class)->resolveClientSearch($term, $actor, count($platforms) === 1 ? $platforms[0] : null);
        if (is_array($link)) {
            $resolution = $link['resolution'] ?? null;
            $ids = array_values(array_filter(array_map('intval', (array) ($link['client_ids'] ?? []))));
            if ($ids) {
                $clients = $base()->whereIn('id', $ids)->limit(self::LIMIT)->get();
                foreach ($clients as $client) {
                    $reasons[$client->id] = 'profile_link';
                }
                if ($clients->isEmpty()) {
                    // The link names a real profile, but it cannot take this payment (other market, paused or closed).
                    $resolution = ['mode' => 'not_selectable'] + (array) $resolution;
                }
            } elseif ($terms = array_filter(array_map('trim', (array) ($link['fallback_terms'] ?? [])))) {
                $clients = $this->text($base(), $terms)->limit(self::LIMIT)->get();
                foreach ($clients as $client) {
                    $reasons[$client->id] = 'similar_link';
                }
            }

            return ['clients' => $this->rows($clients, $reasons), 'resolution' => $resolution];
        }

        $phone = app(CollectionParserRegistry::class)->anyPhone($term);
        $payerClients = $phone ? CollectionPayerLink::where('account_id', $receipt->account_id)
            ->where('payer_fingerprint', app(CollectionPrivacy::class)->fingerprint($phone))->whereNull('revoked_at')
            ->where('valid_until', '>', now())->pluck('client_id')->map(fn ($id) => (int) $id)->all() : [];
        $query = $this->text($base(), array_filter([$term, $phone]));
        if ($payerClients) {
            $query->orWhere(fn ($q) => $q->whereIn('platform_id', $platforms)->whereIn('id', $payerClients)
                ->whereIn('id', app(CollectionPurchaseService::class)->selectableClients()->select('id')));
        }
        $clients = $query->limit(200)->get();
        foreach ($clients as $client) {
            $reasons[$client->id] = $this->reason($client, $term, $phone, $payerClients);
        }
        $rank = ['profile_id' => 0, 'phone' => 1, 'payer' => 2, 'email' => 3, 'name_exact' => 4, 'profile_link' => 5, 'name' => 6, 'other' => 7];
        $clients = $clients->sortBy(fn ($c) => [$rank[$reasons[$c->id]] ?? 9, mb_strtolower((string) $c->name)])->take(self::LIMIT)->values();

        return ['clients' => $this->rows($clients, $reasons), 'resolution' => null];
    }

    /** Same fields as the Clients page text search, plus the payer's normalized phone. */
    private function text($query, array $terms)
    {
        return $query->where(function ($builder) use ($terms) {
            foreach (array_values(array_unique($terms)) as $term) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
                $builder->orWhere(function ($nested) use ($term, $like) {
                    $nested->where('name', 'like', $like)->orWhere('phone_normalized', 'like', $like)->orWhere('email', 'like', $like)
                        ->orWhere('wp_profile_permalink', 'like', $like)->orWhere('wp_profile_slug', 'like', $like);
                    if (ctype_digit($term)) {
                        $nested->orWhere('id', (int) $term)->orWhere('wp_post_id', (int) $term)->orWhere('wp_user_id', (int) $term);
                    }
                });
            }
        });
    }

    private function reason(Client $client, string $term, ?string $phone, array $payerClients): string
    {
        $digits = preg_replace('/\D/', '', $term);

        return match (true) {
            ctype_digit($term) && in_array((int) $term, [(int) $client->wp_post_id, (int) $client->id, (int) $client->wp_user_id], true) => 'profile_id',
            $phone && $client->phone_normalized === $phone, strlen($digits) >= 6 && str_contains((string) $client->phone_normalized, $digits) => 'phone',
            in_array((int) $client->id, $payerClients, true) => 'payer',
            str_contains($term, '@') && $client->email && mb_stripos((string) $client->email, $term) !== false => 'email',
            mb_strtolower(trim((string) $client->name)) === mb_strtolower($term) => 'name_exact',
            mb_stripos((string) $client->name, $term) !== false => 'name',
            $client->email && mb_stripos((string) $client->email, $term) !== false => 'email',
            mb_stripos((string) $client->wp_profile_permalink.' '.$client->wp_profile_slug, $term) !== false => 'profile_link',
            default => 'other',
        };
    }

    private function rows($clients, array $reasons): array
    {
        $rows = app(CollectionReviewContextService::class)->clientRows(collect($clients));

        return array_map(function ($row) use ($clients, $reasons) {
            $client = collect($clients)->firstWhere('id', $row['id']);

            return $row + ['matched_by' => $reasons[$row['id']] ?? 'other', 'phone' => $client?->phone_normalized ? '+'.ltrim($client->phone_normalized, '+') : null,
                'city' => $client?->city, 'profile_status' => $client?->profile_status, 'profile_url' => $client?->wp_profile_permalink];
        }, $rows);
    }
}
