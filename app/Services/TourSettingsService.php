<?php

namespace App\Services;

use App\Models\Platform;
use App\Models\TourMarketSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** CRM owns the catalogue; WordPress serves the last independently verified publication. */
class TourSettingsService
{
    public function defaults(): array
    {
        return [
            'enabled' => false,
            'environment' => 'sandbox',
            'local_enabled' => true,
            'local_destination_depth' => 0,
            'receiver_enabled' => false,
            'receiver_sources' => [],
            'allow_custom_contact' => true,
            'max_stops' => 10,
            'max_span_days' => 90,
            'passes' => [],
        ];
    }

    public function validate(Platform $platform, array $input): array
    {
        $validated = Validator::make(['settings' => $input], [
            'settings' => ['required', 'array:enabled,environment,local_enabled,local_destination_depth,receiver_enabled,receiver_sources,allow_custom_contact,max_stops,max_span_days,passes'],
            'settings.enabled' => ['required', 'boolean'],
            'settings.environment' => ['required', Rule::in(['sandbox', 'production'])],
            'settings.local_enabled' => ['required', 'boolean'],
            'settings.local_destination_depth' => ['required', 'integer', 'between:0,2'],
            'settings.receiver_enabled' => ['required', 'boolean'],
            'settings.receiver_sources' => ['present', 'array', 'max:100'],
            'settings.receiver_sources.*' => ['required', 'array:platform_id,origin,media_origins'],
            'settings.receiver_sources.*.platform_id' => ['required', 'integer', 'min:1', 'distinct:strict', 'exists:platforms,id'],
            'settings.receiver_sources.*.origin' => ['required', 'string', 'max:255'],
            'settings.receiver_sources.*.media_origins' => ['required', 'array', 'min:1', 'max:8'],
            'settings.receiver_sources.*.media_origins.*' => ['required', 'string', 'max:255', 'distinct:strict'],
            'settings.allow_custom_contact' => ['required', 'boolean'],
            'settings.max_stops' => ['required', 'integer', 'between:1,10'],
            'settings.max_span_days' => ['required', 'integer', 'between:1,90'],
            'settings.passes' => ['present', 'array', 'max:32'],
            'settings.passes.*' => ['required', 'array:code,name,region,currency,amount_minor,days,market_limit,destination_platform_ids,enabled'],
            'settings.passes.*.code' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/', 'distinct:strict'],
            'settings.passes.*.name' => ['required', 'string', 'max:80'],
            'settings.passes.*.region' => ['required', Rule::in(['africa', 'gulf', 'europe', 'hub'])],
            'settings.passes.*.currency' => ['required', Rule::in($platform->supportedCurrencies())],
            'settings.passes.*.amount_minor' => ['required', 'integer', 'between:1,1000000000000'],
            'settings.passes.*.days' => ['required', 'integer', 'between:1,90'],
            'settings.passes.*.market_limit' => ['present', 'nullable', 'integer', 'between:1,100'],
            'settings.passes.*.destination_platform_ids' => ['present', 'array', 'max:100'],
            'settings.passes.*.destination_platform_ids.*' => ['integer', 'min:1', 'exists:platforms,id'],
            'settings.passes.*.enabled' => ['required', 'boolean'],
        ])->validate()['settings'];

        foreach (['enabled', 'local_enabled', 'receiver_enabled', 'allow_custom_contact'] as $key) {
            $validated[$key] = (bool) $validated[$key];
        }
        $validated['receiver_sources'] = array_values($validated['receiver_sources']);
        foreach ($validated['receiver_sources'] as $index => &$source) {
            $source['platform_id'] = (int) $source['platform_id'];
            $sourcePlatform = Platform::find($source['platform_id']);
            $origin = $this->originForPlatform($sourcePlatform);
            if ($source['platform_id'] === (int) $platform->id || ! $origin || $source['origin'] !== $origin) {
                throw ValidationException::withMessages(["settings.receiver_sources.$index.origin" => 'Choose a different registered home market with its configured HTTPS website.']);
            }
            foreach ($source['media_origins'] as $mediaOrigin) {
                if ($this->safeOrigin($mediaOrigin) !== $mediaOrigin) {
                    throw ValidationException::withMessages(["settings.receiver_sources.$index.media_origins" => 'Media websites must be exact public HTTPS origins without paths, credentials or IP addresses.']);
                }
            }
            $source['media_origins'] = array_values(array_unique($source['media_origins']));
        }
        unset($source);
        if ($validated['receiver_enabled'] && $validated['receiver_sources'] === []) {
            throw ValidationException::withMessages(['settings.receiver_sources' => 'Choose at least one trusted home market before accepting visiting profiles.']);
        }
        $validated['local_destination_depth'] = (int) $validated['local_destination_depth'];
        $validated['max_stops'] = (int) $validated['max_stops'];
        $validated['max_span_days'] = (int) $validated['max_span_days'];
        $validated['passes'] = array_values($validated['passes']);
        foreach ($validated['passes'] as $index => &$pass) {
            $pass['amount_minor'] = (int) $pass['amount_minor'];
            $pass['days'] = (int) $pass['days'];
            $pass['market_limit'] = $pass['market_limit'] === null ? null : (int) $pass['market_limit'];
            $pass['enabled'] = (bool) $pass['enabled'];
            $pass['name'] = trim($pass['name']);
            $pass['destination_platform_ids'] = array_values(array_unique(array_map('intval', $pass['destination_platform_ids'])));
            sort($pass['destination_platform_ids']);
            if (in_array((int) $platform->id, $pass['destination_platform_ids'], true)) {
                throw ValidationException::withMessages(["settings.passes.$index.destination_platform_ids" => 'Choose destination markets other than the home market.']);
            }
            if ($pass['name'] === '' || ($pass['enabled'] && $pass['destination_platform_ids'] === [])) {
                throw ValidationException::withMessages(["settings.passes.$index.destination_platform_ids" => 'An available pass needs a name and at least one destination market.']);
            }
            if ($pass['enabled'] && Platform::whereIn('id', $pass['destination_platform_ids'])->where('is_active', false)->exists()) {
                throw ValidationException::withMessages(["settings.passes.$index.destination_platform_ids" => 'Available passes can include active destination markets only.']);
            }
        }
        unset($pass);

        return $validated;
    }

    public function read(Platform $platform): array
    {
        $row = TourMarketSetting::where('platform_id', $platform->id)->first();
        $revision = (int) ($row?->revision ?? 0);

        return [
            'platform_id' => (int) $platform->id,
            'settings' => $row?->settings ?? $this->defaults(),
            'revision' => $revision,
            'synced_revision' => $row?->synced_revision,
            'last_synced_at' => $row?->last_synced_at?->toIso8601String(),
            'last_sync_error' => $row?->last_sync_error,
            'publication_status' => $row?->last_sync_error ? 'failed' : ($revision > 0 && $row?->synced_revision === $revision ? 'published' : 'unpublished'),
            'capabilities' => ['local' => true, 'paid_checkout' => class_exists(\App\Services\Tours\CheckoutService::class), 'remote_distribution' => class_exists(\App\Services\Tours\DistributionService::class)],
            'currencies' => $platform->supportedCurrencies(),
            'starter_passes' => $this->starterPasses($platform),
        ];
    }

    public function save(Platform $platform, array $settings, int $expectedRevision, int $actorId): array
    {
        $settings = $this->validate($platform, $settings);
        DB::transaction(function () use ($platform, $settings, $expectedRevision, $actorId) {
            Platform::whereKey($platform->id)->lockForUpdate()->firstOrFail();
            $row = TourMarketSetting::where('platform_id', $platform->id)->lockForUpdate()->first();
            if ((int) ($row?->revision ?? 0) !== $expectedRevision) {
                throw new ConflictHttpException('These settings changed in another session. Reload before saving.');
            }
            $row ??= new TourMarketSetting(['platform_id' => $platform->id]);
            $row->fill(['settings' => $settings, 'revision' => $expectedRevision + 1, 'updated_by' => $actorId, 'last_sync_error' => null]);
            $row->save();
        });

        return $this->read($platform);
    }

    public function publish(Platform $platform, int $expectedRevision): array
    {
        $lock = Cache::lock('tour-settings-publish:'.$platform->id, 240);
        if (! $lock->get()) {
            throw new ConflictHttpException('A publication is already in progress for this market.');
        }
        try {
            $row = TourMarketSetting::where('platform_id', $platform->id)->first();
            if (! $row || $row->revision !== $expectedRevision) {
                throw new ConflictHttpException('Save the current settings before publishing.');
            }
            $snapshot = ['revision' => $row->revision, 'settings' => $row->settings];
            try {
                $wp = new WpSyncService($platform);
                $before = $wp->getTourConfiguration();
                if ((int) ($before['revision'] ?? -1) > $row->revision) {
                    throw new \RuntimeException('The website has a newer revision. Reconcile it before publishing.');
                }
                $wp->publishTourConfiguration($snapshot + ['expected_revision' => (int) ($before['revision'] ?? 0)]);
                $after = $wp->getTourConfiguration();
                if ((int) ($after['revision'] ?? -1) !== $snapshot['revision'] || ! $this->sameSettings($after['settings'] ?? [], $snapshot['settings'])) {
                    throw new \RuntimeException('The website did not confirm the complete saved settings.');
                }
                TourMarketSetting::whereKey($row->id)->update([
                    'synced_revision' => $snapshot['revision'], 'last_synced_at' => now(), 'last_sync_error' => null,
                ]);
            } catch (\Throwable $exception) {
                TourMarketSetting::whereKey($row->id)->update(['last_sync_error' => 'Website publication was not verified. Check the connection and retry.']);
                report($exception);
                throw new \RuntimeException('Settings are saved in CRM, but publication to the website was not verified. Check the connection and retry.', 0, $exception);
            }

            return $this->read($platform);
        } finally {
            $lock->release();
        }
    }

    public function originForPlatform(?Platform $platform): ?string
    {
        $domain = trim((string) $platform?->domain);

        return $this->safeOrigin(str_contains($domain, '://') ? $domain : 'https://'.$domain);
    }

    private function safeOrigin(string $origin): ?string
    {
        $parts = parse_url($origin);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment']) || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || ! str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP) || ! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || preg_match('/\.(?:localhost|local|internal|test|invalid|arpa)$/D', $host)) {
            return null;
        }

        return 'https://'.$host;
    }

    private function sameSettings(array $left, array $right): bool
    {
        // Normalize object key order without losing ordered pass-catalogue semantics.
        $sort = function ($value) use (&$sort) {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($sort, $value);
        };

        return $sort($left) === $sort($right);
    }

    private function starterPasses(Platform $platform): array
    {
        // Approved Kenya examples are offered on demand, never auto-published or enabled.
        if ($platform->primaryCurrency() !== 'KES' || ! in_array(strtolower(trim((string) $platform->country)), ['kenya', 'ke', 'ken'], true)) {
            return [];
        }
        $destinations = Platform::get(['id', 'domain'])->mapWithKeys(function ($item) {
            $domain = strtolower(trim((string) $item->domain));
            $host = parse_url(str_contains($domain, '://') ? $domain : 'https://'.$domain, PHP_URL_HOST);

            return [preg_replace('/^www\./', '', (string) $host) => (int) $item->id];
        });
        $groups = [
            'dubai' => array_values(array_filter(array_map(fn ($host) => $destinations[$host] ?? null, ['bahrainhookups.com', 'qatarhookups.com', 'saudihookups.com', 'uaehookups.com']))),
            'europe' => array_values(array_filter([$destinations['exotic-escorts.com'] ?? null])),
        ];
        $rows = [];
        foreach ([['dubai', 'Dubai', 'gulf', [7 => 2000, 14 => 4000, 30 => 8000]], ['europe', 'Europe', 'europe', [7 => 1500, 14 => 3000, 30 => 6000]]] as [$code, $name, $region, $prices]) {
            foreach ($prices as $days => $amount) {
                $rows[] = ['code' => $code.'_'.$days.'d', 'name' => $name.' · '.($days === 30 ? '1 month (30 days)' : ($days === 14 ? '2 weeks' : '1 week')), 'region' => $region, 'currency' => 'KES', 'amount_minor' => $amount * 100, 'days' => $days, 'market_limit' => null, 'destination_platform_ids' => $groups[$code], 'enabled' => false];
            }
        }
        foreach ([['single', 'Single', 500, 7, 1], ['plus', 'Plus', 2000, 7, 3], ['unlimited', 'Unlimited', 6000, 30, null]] as [$code, $name, $amount, $days, $limit]) {
            $rows[] = ['code' => 'africa_'.$code, 'name' => $name, 'region' => 'africa', 'currency' => 'KES', 'amount_minor' => $amount * 100, 'days' => $days, 'market_limit' => $limit, 'destination_platform_ids' => [], 'enabled' => false];
        }

        return $rows;
    }
}
