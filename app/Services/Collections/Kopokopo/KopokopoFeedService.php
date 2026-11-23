<?php

namespace App\Services\Collections\Kopokopo;

use App\Billing\Support\KopoKopoRuntimeResolver;
use App\Models\BillingProviderProfile;
use App\Models\Collections\CollectionAccount;
use App\Models\Collections\CollectionProviderEvent;
use App\Models\Collections\CollectionProviderFeed;
use App\Models\Collections\CollectionProviderPoll;
use App\Models\Platform;
use App\Models\User;
use App\Services\Collections\CollectionException;
use App\Services\Collections\CollectionPrivacy;
use App\Services\MarketAuthorizationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class KopokopoFeedService
{
    public const PROVIDER = 'kopokopo_buygoods_ke';

    public function scope(User $actor, bool $manager = false): void
    {
        app(MarketAuthorizationService::class)->ensureUserCanAccessPlatform($actor, 1);
        if ($manager) {
            app(MarketAuthorizationService::class)->ensureManager($actor);
        }
    }

    public function feed(): ?CollectionProviderFeed
    {
        return Schema::hasTable('collection_provider_feeds') ? CollectionProviderFeed::where('account_id', config('collection_kopokopo.account_id'))->first() : null;
    }

    public function setup(User $actor): CollectionProviderFeed
    {
        $this->scope($actor, true);
        if (! config('collection_kopokopo.enabled') || ! config('collection.capture_enabled') || ! Schema::hasTable('collection_provider_feeds')) {
            throw new CollectionException('provider_setup_unavailable', 503);
        }
        $runtime = app(KopoKopoRuntimeResolver::class)->resolveConfig(Platform::findOrFail(1), 'production');
        $profile = $runtime['profile'];
        if (! $profile) {
            throw new CollectionException('production_kopokopo_profile_required', 422);
        }

        return DB::transaction(function () use ($actor, $profile) {
            Platform::whereKey(1)->lockForUpdate()->firstOrFail();
            $account = CollectionAccount::whereKey(config('collection_kopokopo.account_id'))->lockForUpdate()->first();
            if (! $account) {
                $account = CollectionAccount::create(['id' => config('collection_kopokopo.account_id'), 'label' => 'Kenya KopoKopo till 268491',
                    'country' => 'KE', 'provider_key' => self::PROVIDER, 'account_kind' => 'till', 'account_identifier' => '268491',
                    'account_fingerprint' => app(CollectionPrivacy::class)->fingerprint('kopokopo:till:268491'), 'currency' => 'KES',
                    'timezone' => 'Africa/Nairobi', 'ownership' => 'company', 'mode' => 'assisted', 'verified_at' => now(),
                    'policy' => ['version' => 1, 'automation_enabled' => false, 'purchase_matching' => 'reviewed_only']]);
                DB::table('collection_account_platforms')->insert(['account_id' => $account->id, 'platform_id' => 1,
                    'destination_snapshot' => json_encode(['provider' => 'kopokopo', 'till_number' => '268491', 'currency' => 'KES']), 'valid_from' => now()]);
            }
            if ($feed = CollectionProviderFeed::where('account_id', $account->id)->first()) {
                $this->configuration($feed);

                return $feed;
            }
            $feed = new CollectionProviderFeed(['id' => (string) Str::uuid(), 'account_id' => $account->id,
                'provider_profile_id' => $profile->id, 'till_number' => '268491', 'enabled' => true,
                'cutover_at' => now(), 'subscriptions' => [], 'version' => 1]);
            $this->configuration($feed);
            $feed->save();
            $this->audit($feed, $actor->id, 'kopokopo_receiving_till_prepared', ['till' => '268491', 'provider_profile_id' => $profile->id]);

            return $feed;
        }, 3);
    }

    public function configuration(CollectionProviderFeed $feed): array
    {
        $account = $feed->account;
        if ($feed->account_id !== config('collection_kopokopo.account_id') || $feed->till_number !== '268491'
            || ! $account || $account->provider_key !== self::PROVIDER || $account->country !== 'KE' || $account->currency !== 'KES'
            || $account->account_identifier !== '268491'
            || ! DB::table('collection_account_platforms')->where('account_id', $account->id)->where('platform_id', 1)->whereNull('valid_until')->exists()
            || DB::table('collection_account_platforms')->where('account_id', $account->id)->where('platform_id', '!=', 1)->exists()) {
            throw new CollectionException('provider_receiving_scope_mismatch', 409);
        }
        $profile = BillingProviderProfile::find($feed->provider_profile_id);
        if (! $profile || ! $profile->active || $profile->provider_type_key !== 'kopokopo' || $profile->environment !== 'production'
            || ($profile->market_id && (int) $profile->market_id !== 1)) {
            throw new CollectionException('production_kopokopo_profile_required', 503);
        }
        $config = array_merge((array) $profile->config_json, (array) $profile->secrets_json);
        if (rtrim((string) ($config['base_url'] ?? ''), '/') !== 'https://api.kopokopo.com') {
            throw new CollectionException('provider_environment_mismatch', 503);
        }
        foreach (['client_id', 'client_secret', 'api_key'] as $field) {
            if (! is_string($config[$field] ?? null) || trim($config[$field]) === '') {
                throw new CollectionException('provider_credentials_required', 503);
            }
            $config[$field] = trim($config[$field]);
        }

        return $config;
    }

    public function authenticate(CollectionProviderFeed $feed, string $body, string $signature): void
    {
        if (! config('collection_kopokopo.enabled') || ! config('collection.capture_enabled') || ! $feed->enabled) {
            throw new CollectionException('provider_capture_disabled', 404);
        }
        $config = $this->configuration($feed);
        if (! preg_match('/^[a-f0-9]{64}$/Di', $signature)
            || ! hash_equals(hash_hmac('sha256', $body, $config['api_key']), strtolower($signature))) {
            throw new CollectionException('provider_signature_invalid', 401);
        }
    }

    public function callback(CollectionProviderFeed $feed, ?CollectionProviderPoll $poll = null): string
    {
        $base = rtrim((string) config('app.url'), '/');
        if (! app()->environment('testing') && (parse_url($base, PHP_URL_SCHEME) !== 'https' || parse_url($base, PHP_URL_HOST) !== 'crm.exotic-online.com')) {
            throw new CollectionException('provider_https_callback_required', 503);
        }

        return $base.'/api/collection-events/v1/kopokopo/'.$feed->id.($poll ? '/polls/'.$poll->id : '');
    }

    public function health(CollectionProviderFeed $feed): array
    {
        $registered = collect([KopokopoReceiptParser::RECEIVED, KopokopoReceiptParser::REVERSED])->every(fn ($event) => data_get($feed->subscriptions, $event.'.state') === 'registered');
        $unresolved = CollectionProviderPoll::where('feed_id', $feed->id)->whereIn('state', ['requesting', 'needs_review'])->exists();
        $quarantined = CollectionProviderEvent::where('feed_id', $feed->id)->where('state', 'quarantined')->count();
        $fresh = $feed->last_poll_completed_at && $feed->last_poll_completed_at->gte(now()->subSeconds(config('collection_kopokopo.poll_stale_seconds')))
            && $feed->reconciled_until && $feed->reconciled_until->gte(now()->subSeconds(config('collection_kopokopo.coverage_stale_seconds')));
        $configured = true;
        try {
            $this->configuration($feed);
        } catch (CollectionException) {
            $configured = false;
        }
        $state = match (true) {
            ! config('collection_kopokopo.enabled') || ! config('collection.capture_enabled') || ! $feed->enabled => 'disabled',
            ! $configured => 'configuration_review',
            (bool) $feed->account->suspended_at || $quarantined > 0 => 'provider_review',
            ! $registered => 'setup_pending',
            $unresolved => 'request_review',
            ! $fresh => 'reconciliation_due',
            default => 'ready',
        };

        return ['state' => $state, 'capture_ready' => $state === 'ready', 'source' => 'kopokopo', 'label' => $feed->account->label,
            'last_contact_at' => $feed->last_delivery_at?->toIso8601String(), 'last_check_in_at' => $feed->last_poll_completed_at?->toIso8601String(),
            'reconciled_until' => $feed->reconciled_until?->toIso8601String(), 'quarantined_events' => $quarantined];
    }

    public function present(User $actor): array
    {
        $this->scope($actor);
        $feed = $this->feed();
        $manager = in_array($actor->role, ['admin', 'sub_admin'], true);
        $base = ['storage_ready' => Schema::hasTable('collection_provider_feeds'), 'can_manage' => $manager,
            'till' => '268491', 'checkout_till' => 'K317087', 'feed' => null];
        if (! $feed) {
            return $base;
        }
        $polls = CollectionProviderPoll::where('feed_id', $feed->id)->latest()->limit(5)->get();
        $base['feed'] = ['id' => $feed->id, 'account_id' => $feed->account_id, 'version' => $feed->version,
            'enabled' => $feed->enabled, 'automatic_enabled' => (bool) data_get($feed->account->policy, 'automation_enabled', false),
            'cutover_at' => $feed->cutover_at->toIso8601String(), 'health' => $this->health($feed),
            'last_error_code' => $feed->last_error_code,
            'subscriptions' => collect([KopokopoReceiptParser::RECEIVED, KopokopoReceiptParser::REVERSED])->map(fn ($event) => [
                'event' => $event, 'state' => data_get($feed->subscriptions, $event.'.state', 'not_registered')])->all(),
            'polls' => $polls->map(fn ($p) => ['id' => $p->id, 'state' => $p->state, 'from_at' => $p->from_at->toIso8601String(),
                'until_at' => $p->until_at->toIso8601String(), 'can_check' => (bool) ($p->location || $p->pending_result), 'error' => $p->last_error_code])->all()];
        if ($manager) {
            $base['feed']['callback_url'] = $this->callback($feed);
        }

        return $base;
    }

    public function audit(CollectionProviderFeed $feed, ?int $actor, string $action, array $context = []): void
    {
        DB::table('collection_audit')->insert(['account_id' => $feed->account_id, 'actor_id' => $actor, 'action' => $action,
            'context' => json_encode($context, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
