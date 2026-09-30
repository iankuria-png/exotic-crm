<?php

namespace App\Services\Monetization;

use App\Models\Client;
use App\Models\ContentMonetizationSetting;
use App\Models\PremiumContentOffer;
use App\Services\MonetizationSettingsService;
use App\Services\WalletSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SyncService
{
    public function profile(Client $client): array
    {
        $s = app(MonetizationSettingsService::class)->forPlatform($client->platform);
        $testOffers = PremiumContentOffer::where('client_id', $client->id)->with(['assets', 'client'])->get()->filter(fn ($o) => app(OfferService::class)->available($o, $s, true));
        $offers = PremiumContentOffer::where('client_id', $client->id)->with(['assets', 'client'])->get()->filter(fn ($o) => app(OfferService::class)->available($o, $s));
        $pass = app(PassService::class)->current($client);
        $facts = app(ListingEligibility::class)->facts($client);
        $listingExpiry = $facts['legacy_expiry'] ?: $client->deals()->currentlyActive()->whereNotNull('expires_at')->max('expires_at');
        $creatorUntil = ! $pass || ! $listingExpiry ? 0 : min($pass->expires_at->timestamp, is_numeric($listingExpiry) ? (int) $listingExpiry : \Carbon\Carbon::parse($listingExpiry)->timestamp);
        // Items Exotic moved at expiry do not depend on a pass or listing term; a later lifecycle
        // change or offer edit re-syncs the profile, so a rolling horizon keeps discovery current.
        $automatedUntil = now()->addDays(365)->timestamp;
        $offerUntil = fn ($o) => $o->assets->every(fn ($a) => $a->origin === PremiumContentOffer::ORIGIN_EXPIRY) ? $automatedUntil : $creatorUntil;
        $until = (int) ($offers->map($offerUntil)->max() ?? 0);
        $testUntil = (int) ($testOffers->map(fn ($o) => $o->assets->every(fn ($a) => $a->origin === PremiumContentOffer::ORIGIN_EXPIRY) ? $automatedUntil : ($pass ? $pass->expires_at->timestamp : 0))->max() ?? 0);
        // Profile invalidations need their own monotonic sequence. They must never
        // advance the market configuration revision, which guards browser retries.
        $revision = DB::transaction(function () use ($client) {
            $locked = Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
            $locked->paid_media_revision = (int) $locked->paid_media_revision + 1;
            $locked->save();

            return (int) $locked->paid_media_revision;
        });

        return $this->push($s, ['profiles' => [['wp_post_id' => $client->wp_post_id, 'paid_media_revision' => $revision, 'paid_media_live_until' => $until, 'paid_media_test_until' => $testUntil, 'paid_media_types' => $offers->flatMap(fn ($o) => $o->assets->pluck('media_type'))->unique()->values()->all()]]]);
    }

    public function push(ContentMonetizationSetting $s, array $extra = []): array
    {
        return $this->send($s, '/premium-content/sync', ['config_revision' => $s->config_revision, 'effective' => app(MonetizationSettingsService::class)->runtime($s)] + $extra);
    }

    public function provision(ContentMonetizationSetting $s): array
    {
        return $this->send($s, '/wallet-credentials', ['grant_secret' => $s->grant_secret, 'device_pepper' => $s->device_pepper]);
    }

    public function send(ContentMonetizationSetting $s, string $route, array $payload, int $timeout = 15): array
    {
        $platform = $s->platform;
        $base = rtrim((string) $platform->wp_api_url, '/');
        if (! $base) {
            return ['status' => 'failed', 'message' => 'WordPress API URL is not configured.'];
        }
        if (! str_ends_with($base, '/exotic-crm-sync/v1')) {
            $base .= '/exotic-crm-sync/v1';
        }
        $path = '/wp-json/exotic-crm-sync/v1'.$route;
        $time = (string) now()->timestamp;
        $key = (string) Str::uuid();
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $secret = app(WalletSettingsService::class)->wpToCrmHmacSecret($platform, $s->premium_access_environment);
        if (! $secret) {
            return ['status' => 'failed', 'message' => 'Per-market HMAC secret is missing.'];
        }
        $signature = hash_hmac('sha256', implode("\n", [$time, 'POST', $path, (string) $platform->id, $key, hash('sha256', $body)]), $secret);
        try {
            $response = Http::timeout($timeout)->withHeaders(['X-Exotic-Platform-Id' => $platform->id, 'X-Exotic-Timestamp' => $time, 'X-Idempotency-Key' => $key, 'X-Exotic-Signature' => $signature])->withBody($body, 'application/json')->post($base.$route);
            if ($response->successful()) {
                if ($route === '/premium-content/sync') {
                    $s->update(['wp_revision' => $s->config_revision]);
                }

                return ['status' => 'synced', 'response' => $response->json()];
            }

            return ['status' => 'failed', 'message' => 'WordPress rejected sync ('.$response->status().').'];
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'message' => 'WordPress could not be reached.'];
        }
    }
}
