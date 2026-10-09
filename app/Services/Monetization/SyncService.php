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

    public function adminSend(ContentMonetizationSetting $s, string $route, array $payload): array
    {
        if (! $s->platform->wp_api_user || ! $s->platform->wp_api_password) {
            return ['status' => 'failed', 'code' => 'wordpress_admin_missing', 'message' => 'Set this market’s WordPress administrator application password in Settings → Markets, then retry Connect WordPress.'];
        }

        return $this->transport($s, $route, $payload, [], true);
    }

    public function send(ContentMonetizationSetting $s, string $route, array $payload, int $timeout = 15): array
    {
        $platform = $s->platform;
        if (! $platform->wp_api_url) {
            return ['status' => 'failed', 'message' => 'WordPress API URL is not configured.'];
        }
        $path = '/wp-json/exotic-crm-sync/v1'.$route;
        $time = (string) now()->timestamp;
        $key = (string) Str::uuid();
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $secret = app(WalletSettingsService::class)->wpToCrmHmacSecret($platform, $s->premium_access_environment);
        if (! $secret) {
            return ['status' => 'failed', 'message' => 'WordPress wallet authentication is not configured for this market environment. Use Connect WordPress, then re-check.'];
        }
        $signature = hash_hmac('sha256', implode("\n", [$time, 'POST', $path, (string) $platform->id, $key, hash('sha256', $body)]), $secret);

        return $this->transport($s, $route, $payload, ['X-Exotic-Platform-Id' => $platform->id, 'X-Exotic-Timestamp' => $time, 'X-Idempotency-Key' => $key, 'X-Exotic-Signature' => $signature], false, $timeout);
    }

    private function transport(ContentMonetizationSetting $s, string $route, array $payload, array $headers, bool $admin, int $timeout = 15): array
    {
        try {
            $guard = app(WordPressDestination::class);
            $base = $guard->base((string) $s->platform->wp_api_url);
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $http = Http::timeout($timeout)->acceptJson()->withOptions($guard->options($base))->withHeaders($headers);
                if ($admin) {
                    $http = $http->withBasicAuth($s->platform->wp_api_user, $s->platform->wp_api_password);
                }
                $response = $http->withBody(json_encode($payload, JSON_UNESCAPED_SLASHES), 'application/json')->post($base.$route);
                if (! in_array($response->status(), [301, 302, 307, 308], true) || $attempt === 1) {
                    break;
                }
                $location = (string) $response->header('Location');
                // Re-signing is unnecessary: the only accepted redirect changes the www origin.
                if (! str_ends_with($location, $route)) {
                    break;
                }
                $base = $guard->canonical((string) $s->platform->wp_api_url, substr($location, 0, -strlen($route)));
            }
            if ($response->successful()) {
                if ($route === '/premium-content/sync') {
                    if ((int) $response->json('applied_revision') !== (int) $s->config_revision) {
                        return ['status' => 'failed', 'code' => 'revision_unacknowledged', 'message' => 'WordPress did not acknowledge this settings revision. Upload the complete sync plugin and retry.'];
                    }
                    $s->update(['wp_revision' => $s->config_revision]);
                }

                return ['status' => 'synced', 'response' => $response->json()];
            }
            $code = $response->status();
            $message = match ($code) {
                401, 403 => 'WordPress refused authentication. Check the market’s application password and wallet credentials in Settings, then reconnect.',
                404 => 'WordPress does not have this setup endpoint. Upload the complete exotic-crm-sync plugin, then re-check.',
                409 => 'WordPress has a newer revision or different device credentials. Reconnect and re-check; do not rotate existing device credentials.',
                default => 'WordPress could not complete the check (HTTP '.$code.'). Ask the site administrator to check the plugin/server, then retry.',
            };

            return ['status' => 'failed', 'code' => 'wordpress_http_'.$code, 'message' => $message];
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ['status' => 'failed', 'code' => 'unsafe_wordpress_destination', 'message' => collect($e->errors())->flatten()->first()];
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'code' => 'wordpress_unreachable', 'message' => 'CRM could not reach WordPress. Check the market address, HTTPS and site availability, then retry.'];
        }
    }
}
