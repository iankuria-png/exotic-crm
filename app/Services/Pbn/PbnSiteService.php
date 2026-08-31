<?php

namespace App\Services\Pbn;

use App\Models\PbnSite;
use App\Models\PbnSiteSource;
use App\Models\Platform;
use App\Models\User;
use App\Services\DynamicDatabaseService;
use App\Services\MarketAuthorizationService;
use App\Services\WpSyncService;
use App\Support\MarketTimezone;
use App\Support\WordPressSiteConnection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PbnSiteService
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService
    ) {
    }

    public function listFor(User $actor): array
    {
        $sitesQuery = PbnSite::query()
            ->with(['sourcePlatforms:id,name,country,domain', 'defaultSourcePlatform:id,name,country'])
            ->with(['seedBatches' => fn ($query) => $query->latest('id')->limit(1)])
            ->orderBy('name');

        $allowedPlatformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($actor);
        if (is_array($allowedPlatformIds)) {
            $sitesQuery->whereHas('sourcePlatforms', fn ($query) => $query->whereIn('platforms.id', $allowedPlatformIds));
            if ($actor->role === MarketAuthorizationService::ROLE_SALES) {
                $sitesQuery->where('is_active', true);
            }
        }

        $platformsQuery = Platform::query()
            ->select(['id', 'name', 'country', 'domain', 'is_active'])
            ->orderBy('name');
        if (is_array($allowedPlatformIds)) {
            $platformsQuery->whereIn('id', $allowedPlatformIds);
        }

        return [
            'sites' => $sitesQuery->get()->map(fn (PbnSite $site) => $this->serialize($site, $actor))->values(),
            'platforms' => $platformsQuery->get()->map(fn (Platform $platform) => [
                'platform_id' => (int) $platform->id,
                'platform_name' => (string) $platform->name,
                'country' => $platform->country,
                'domain' => $platform->domain,
                'is_active' => (bool) $platform->is_active,
            ])->values(),
        ];
    }

    public function create(array $validated, User $actor): PbnSite
    {
        $this->marketAuthorizationService->ensureManager(
            $actor,
            'Only admin or sub-admin users can create PBN site credentials.'
        );

        $payload = $this->writePayload($validated, null, $actor);

        return DB::transaction(function () use ($payload): PbnSite {
            $sourcePlatformIds = $payload['source_platform_ids'];
            unset($payload['source_platform_ids']);

            $site = PbnSite::create($payload);
            $this->syncSources($site, $sourcePlatformIds);

            return $site->fresh(['sourcePlatforms', 'defaultSourcePlatform']);
        });
    }

    public function update(PbnSite $site, array $validated, User $actor): PbnSite
    {
        $this->marketAuthorizationService->ensureManager(
            $actor,
            'Only admin or sub-admin users can update PBN site credentials.'
        );

        $payload = $this->writePayload($validated, $site, $actor);

        return DB::transaction(function () use ($site, $payload): PbnSite {
            $sourcePlatformIds = $payload['source_platform_ids'] ?? null;
            unset($payload['source_platform_ids']);

            $site->fill($payload)->save();
            if (is_array($sourcePlatformIds)) {
                $this->syncSources($site, $sourcePlatformIds);
            }

            return $site->fresh(['sourcePlatforms', 'defaultSourcePlatform']);
        });
    }

    public function serialize(PbnSite $site, User $actor): array
    {
        $site->loadMissing(['sourcePlatforms:id,name,country,domain', 'defaultSourcePlatform:id,name,country']);
        $latestSeed = $site->seedBatches()->latest('id')->first();
        $sourceIds = $site->sourcePlatforms->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        return [
            'id' => (int) $site->id,
            'name' => (string) $site->name,
            'domain' => (string) $site->domain,
            'status' => (string) ($site->last_status ?: 'draft'),
            'is_active' => (bool) $site->is_active,
            'country' => $site->country,
            'timezone' => $site->timezone,
            'currency_code' => $site->currency_code ?: 'KES',
            'phone_prefix' => $site->phone_prefix ?: '254',
            'default_source_platform_id' => $site->default_source_platform_id ? (int) $site->default_source_platform_id : null,
            'source_platform_ids' => $sourceIds,
            'sources' => $site->sourcePlatforms->map(fn (Platform $platform) => [
                'platform_id' => (int) $platform->id,
                'platform_name' => (string) $platform->name,
                'country' => $platform->country,
                'domain' => $platform->domain,
            ])->values(),
            'copy_policy' => $site->effectiveCopyPolicy(),
            'wp_sync' => [
                'credentials_ready' => filled($site->wp_api_url) && filled($site->wp_api_user) && filled($site->wp_api_password),
                'api_url' => $site->wp_api_url,
                'api_user' => $site->wp_api_user,
                'last_checked_at' => optional($site->last_checked_at)->toDateTimeString(),
                'last_error' => $site->last_error,
            ],
            'wp_provisioning' => [
                'credentials_ready' => $site->databaseCredentialsReady(),
                'db_host' => $site->db_host,
                'db_name' => $site->db_name,
                'db_user' => $site->db_user,
                'db_prefix' => $site->db_prefix,
                'db_pass_configured' => filled($site->db_pass),
            ],
            'latest_seed' => $latestSeed ? [
                'id' => (int) $latestSeed->id,
                'status' => (string) $latestSeed->status,
                'selected_count' => (int) $latestSeed->selected_count,
                'created_count' => (int) $latestSeed->created_count,
                'failed_count' => (int) $latestSeed->failed_count,
                'created_at' => optional($latestSeed->created_at)->toDateTimeString(),
            ] : null,
            'can_configure' => $this->marketAuthorizationService->isManager($actor),
            'can_seed' => $this->marketAuthorizationService->hasRole($actor, [
                MarketAuthorizationService::ROLE_ADMIN,
                MarketAuthorizationService::ROLE_SUB_ADMIN,
                MarketAuthorizationService::ROLE_SALES,
            ]) && (bool) $site->is_active,
        ];
    }

    public function testReadiness(PbnSite $site, User $actor): array
    {
        $checks = [
            'rest' => ['status' => 'pending', 'message' => 'Not checked'],
            'database' => ['status' => 'pending', 'message' => 'Not checked'],
            'schema' => ['status' => 'pending', 'message' => 'Not checked'],
            'locations' => ['status' => 'pending', 'message' => 'Not checked'],
        ];

        try {
            if (!filled($site->wp_api_url) || !filled($site->wp_api_user) || !filled($site->wp_api_password)) {
                throw ValidationException::withMessages([
                    'wp_api_url' => 'WordPress REST credentials are incomplete.',
                ]);
            }

            $locations = (new WpSyncService(WordPressSiteConnection::fromPbnSite($site)))->getLocations();
            $checks['rest'] = ['status' => 'ready', 'message' => 'REST credentials accepted.'];
            $checks['locations'] = [
                'status' => !empty($locations) ? 'ready' : 'warning',
                'message' => !empty($locations) ? 'Destination locations loaded.' : 'REST responded but no locations were returned.',
                'count' => is_countable($locations) ? count($locations) : 0,
            ];
        } catch (\Throwable $exception) {
            $checks['rest'] = ['status' => 'failed', 'message' => Str::limit($exception->getMessage(), 500, '')];
            $checks['locations'] = ['status' => 'failed', 'message' => 'Locations require a working REST connection.'];
        }

        try {
            if (!$site->databaseCredentialsReady()) {
                throw ValidationException::withMessages([
                    'db_host' => 'WordPress database credentials are incomplete.',
                ]);
            }

            $connectionName = 'pbn_readiness_' . (int) $site->id;
            DynamicDatabaseService::switchConnection($connectionName, $site->getConnectionConfig());
            $checks['database'] = ['status' => 'ready', 'message' => 'Database connection opened.'];

            $requiredTables = ['users', 'usermeta', 'posts', 'postmeta', 'options', 'terms', 'term_taxonomy', 'term_relationships', 'exotic_crm_provisions'];
            $missing = array_values(array_filter($requiredTables, fn (string $table): bool => !Schema::connection($connectionName)->hasTable($table)));
            $checks['schema'] = [
                'status' => empty($missing) ? 'ready' : 'failed',
                'message' => empty($missing)
                    ? 'Required WordPress and provisioning tables exist.'
                    : 'Missing required tables: ' . implode(', ', $missing) . '.',
                'missing' => $missing,
            ];
        } catch (\Throwable $exception) {
            $checks['database'] = ['status' => 'failed', 'message' => Str::limit($exception->getMessage(), 500, '')];
            $checks['schema'] = ['status' => 'failed', 'message' => 'Schema checks require a working database connection.'];
        }

        $failed = collect($checks)->contains(fn (array $check) => $check['status'] === 'failed');
        $warning = collect($checks)->contains(fn (array $check) => $check['status'] === 'warning');
        $status = $failed ? 'failed' : ($warning ? 'warning' : 'ready');
        $message = $failed
            ? collect($checks)->first(fn (array $check) => $check['status'] === 'failed')['message']
            : null;

        $site->forceFill([
            'last_checked_at' => now(),
            'last_status' => $status,
            'last_error' => $message,
        ])->save();

        return [
            'status' => $status,
            'checks' => $checks,
            'site' => $this->serialize($site->fresh(), $actor),
        ];
    }

    public function locations(PbnSite $site): array
    {
        return (new WpSyncService(WordPressSiteConnection::fromPbnSite($site)))->getLocations();
    }

    public function configuredSourceIdsFor(PbnSite $site, User $actor): array
    {
        $sourceIds = $site->sourcePlatforms()->pluck('platforms.id')->map(fn ($id) => (int) $id)->all();
        $allowed = $this->marketAuthorizationService->resolveAccessiblePlatformIds($actor);
        if (is_array($allowed)) {
            $sourceIds = array_values(array_intersect($sourceIds, $allowed));
        }

        return $sourceIds;
    }

    private function writePayload(array $validated, ?PbnSite $site, User $actor): array
    {
        $payload = collect($validated)->except(['reason'])->all();
        if (array_key_exists('domain', $payload)) {
            $payload['domain'] = $this->normalizeDomain($payload['domain']);
        }
        if (array_key_exists('currency_code', $payload) && filled($payload['currency_code'])) {
            $payload['currency_code'] = strtoupper(trim((string) $payload['currency_code']));
        }
        if (array_key_exists('timezone', $payload)) {
            $timezone = MarketTimezone::normalize(is_string($payload['timezone']) ? $payload['timezone'] : null);
            if ($timezone === null) {
                throw ValidationException::withMessages(['timezone' => MarketTimezone::validationMessage()]);
            }
            $payload['timezone'] = $timezone;
        }
        if ($site && array_key_exists('wp_api_password', $payload) && !filled($payload['wp_api_password'])) {
            unset($payload['wp_api_password']);
        }
        if ($site && array_key_exists('db_pass', $payload) && !filled($payload['db_pass'])) {
            unset($payload['db_pass']);
        }

        $defaultSourceId = isset($payload['default_source_platform_id']) ? (int) $payload['default_source_platform_id'] : (int) ($site?->default_source_platform_id ?? 0);
        $sourceIds = array_values(array_unique(array_filter(array_map(
            static fn ($value) => (int) $value,
            $payload['source_platform_ids'] ?? ($site ? $site->sourcePlatforms()->pluck('platforms.id')->all() : [])
        ), static fn (int $value) => $value > 0)));

        if ($defaultSourceId > 0 && !in_array($defaultSourceId, $sourceIds, true)) {
            array_unshift($sourceIds, $defaultSourceId);
        }
        if ($defaultSourceId <= 0 && !empty($sourceIds)) {
            $defaultSourceId = (int) $sourceIds[0];
        }
        if ($defaultSourceId > 0) {
            $payload['default_source_platform_id'] = $defaultSourceId;
        }
        if (count($sourceIds) > 5) {
            throw ValidationException::withMessages(['source_platform_ids' => 'Select at most 5 source markets.']);
        }
        foreach ($sourceIds as $sourceId) {
            $this->marketAuthorizationService->ensureUserCanAccessPlatform($actor, $sourceId);
        }

        if (!$site) {
            $defaultPlatform = $defaultSourceId > 0 ? Platform::query()->find($defaultSourceId) : null;
            $payload['is_active'] = (bool) ($payload['is_active'] ?? false);
            $payload['db_prefix'] = $payload['db_prefix'] ?? 'wp_';
            $payload['timezone'] = $payload['timezone'] ?? ($defaultPlatform?->timezone ?: 'Africa/Nairobi');
            $payload['currency_code'] = $payload['currency_code'] ?? ($defaultPlatform?->currency_code ?: 'KES');
            $payload['phone_prefix'] = $payload['phone_prefix'] ?? ($defaultPlatform?->phone_prefix ?: '254');
            $payload['copy_policy'] = array_replace_recursive(PbnSite::defaultCopyPolicy(), $payload['copy_policy'] ?? []);
        } elseif (array_key_exists('copy_policy', $payload)) {
            $payload['copy_policy'] = array_replace_recursive(PbnSite::defaultCopyPolicy(), $site->copy_policy ?: [], $payload['copy_policy'] ?: []);
        }

        $payload['source_platform_ids'] = $sourceIds;

        return $payload;
    }

    private function syncSources(PbnSite $site, array $sourcePlatformIds): void
    {
        $sourcePlatformIds = array_values(array_unique(array_filter(array_map('intval', $sourcePlatformIds))));
        $defaultId = $site->default_source_platform_id ? (int) $site->default_source_platform_id : ($sourcePlatformIds[0] ?? null);

        if ($defaultId && !in_array($defaultId, $sourcePlatformIds, true)) {
            $sourcePlatformIds[] = $defaultId;
        }

        PbnSiteSource::query()
            ->where('pbn_site_id', (int) $site->id)
            ->whereNotIn('platform_id', $sourcePlatformIds ?: [0])
            ->delete();

        foreach ($sourcePlatformIds as $platformId) {
            PbnSiteSource::updateOrCreate(
                ['pbn_site_id' => (int) $site->id, 'platform_id' => $platformId],
                ['is_default' => $defaultId && $platformId === (int) $defaultId, 'weight' => 100]
            );
        }
    }

    private function normalizeDomain(string $value): string
    {
        $value = trim($value);
        $host = parse_url($value, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            $host = explode('/', preg_replace('#^https?://#i', '', $value) ?? '')[0] ?? '';
        }

        $host = strtolower(trim($host));
        $host = preg_replace('/[^a-z0-9.-]/', '', $host) ?? '';
        if ($host === '') {
            throw ValidationException::withMessages(['domain' => 'Enter a valid PBN domain.']);
        }

        return $host;
    }
}
