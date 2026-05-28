<?php

namespace App\Services;

use App\Jobs\RecomputeSeoScoreJob;
use App\Models\Client;
use App\Models\KycSubject;
use App\Models\ClientSyncRun;
use App\Models\ClientSyncExclusion;
use App\Models\Platform;
use App\Support\CityNormalizer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClientSyncService
{
    private const DELTA_OVERLAP_MINUTES = 15;
    private const SAFETY_LAG_MINUTES = 2;

    private WpSyncService $wpSync;
    private Platform $platform;

    public function __construct(Platform $platform)
    {
        $this->platform = $platform;
        $this->wpSync = new WpSyncService($platform);
    }

    /**
     * Full sync: import all profiles from WordPress to CRM clients table
     * Returns count of created, updated, and total records
     */
    public function fullSync(int $perPage = 100): array
    {
        $page = 1;
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $total = 0;

        do {
            $response = $this->wpSync->getClients($page, $perPage);
            $clients = $response['data'] ?? [];
            $totalPages = $response['pages'] ?? 1;
            $chunk = $this->applyBulkClients($clients, 'legacy_full');
            $created += (int) ($chunk['created'] ?? 0);
            $updated += (int) ($chunk['updated'] ?? 0);
            $skipped += (int) ($chunk['skipped'] ?? 0);
            $total += (int) ($chunk['processed'] ?? 0);

            Log::info("ClientSync page {$page}/{$totalPages}", [
                'platform_id' => $this->platform->id,
                'records' => count($clients),
                'running_total' => $total,
            ]);

            $page++;
        } while ($page <= $totalPages);

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'total'   => $total,
        ];
    }

    /**
     * Delta sync: only import profiles modified after the last sync
     */
    public function deltaSync(int $perPage = 100): array
    {
        $modifiedAfter = $this->resolveDeltaModifiedAfter();

        $page = 1;
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $total = 0;

        do {
            $response = $this->wpSync->getClients($page, $perPage, $modifiedAfter);
            $clients = $response['data'] ?? [];
            $totalPages = $response['pages'] ?? 1;
            $chunk = $this->applyBulkClients($clients, 'legacy_delta');
            $created += (int) ($chunk['created'] ?? 0);
            $updated += (int) ($chunk['updated'] ?? 0);
            $skipped += (int) ($chunk['skipped'] ?? 0);
            $total += (int) ($chunk['processed'] ?? 0);

            $page++;
        } while ($page <= $totalPages);

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'total'   => $total,
        ];
    }

    /**
     * Sync a single client by WP post ID
     */
    public function syncOne(int $wpPostId): Client
    {
        $wpClient = $this->wpSync->getClient($wpPostId);
        $this->upsertClient($wpClient);

        return Client::where('platform_id', $this->platform->id)
            ->where('wp_post_id', $wpPostId)
            ->firstOrFail();
    }

    public function runBulkSync(ClientSyncRun $run, int $perPage = 100): array
    {
        $capability = $this->resolveCapabilityState($run);

        return match ($capability['protocol']) {
            'v2' => $this->runV2BulkSync($run, $perPage, $capability),
            'v1' => $this->runLegacyBulkSync($run, $perPage, $capability),
            default => throw new \RuntimeException('Unsupported client sync protocol negotiation result.'),
        };
    }

    private function upsertClient(array $wpClient): string
    {
        $wpPostId = (int) ($wpClient['wp_post_id'] ?? 0);
        if ($wpPostId > 0) {
            $isExcluded = ClientSyncExclusion::query()
                ->where('platform_id', (int) $this->platform->id)
                ->where('wp_post_id', $wpPostId)
                ->exists();

            if ($isExcluded) {
                return 'skipped';
            }
        }

        $phone = $this->normalizePhone($wpClient['phone'] ?? '', $this->platform->phone_prefix);

        // Truncate fields to fit column limits — WP data can have junk
        $phone = mb_substr($phone, 0, 20);
        $name = mb_substr($wpClient['name'] ?? '', 0, 255);
        $email = mb_substr($wpClient['email'] ?? '', 0, 255);
        $city = CityNormalizer::fromWpPayload($wpClient);
        $imageUrl = mb_substr($wpClient['main_image_url'] ?? '', 0, 500);
        $profilePermalink = mb_substr((string) ($wpClient['wp_profile_permalink'] ?? ''), 0, 500);
        $profileSlug = mb_substr((string) ($wpClient['wp_profile_slug'] ?? ''), 0, 255);
        $premiumExpire = $this->ensureUnixTimestamp($wpClient['premium_expire'] ?? null);
        $featuredExpire = $this->ensureUnixTimestamp($wpClient['featured_expire'] ?? null);
        $escortExpire = $this->resolveEscortExpiry($wpClient, $premiumExpire, $featuredExpire);

        $client = Client::firstOrNew([
            'platform_id' => $this->platform->id,
            'wp_post_id'  => $wpPostId,
        ]);
        $wasRecentlyCreated = !$client->exists;
        $previousVerified = (bool) ($client->verified ?? false);
        $previousVerifiedSource = (string) ($client->verified_source ?? '');

        $newBadgeMode = $this->resolveNewBadgeMode($wpClient);
        $incomingVerified = (bool) ($wpClient['verified'] ?? false);

        $syncData = [
            'wp_user_id'      => $wpClient['wp_user_id'] ?? null,
            'wp_profile_permalink' => $profilePermalink !== '' ? $profilePermalink : null,
            'wp_profile_slug' => $profileSlug !== '' ? $profileSlug : null,
            'client_type'     => 'escort',
            'name'            => $name ?: null,
            'phone_normalized'=> $phone ?: null,
            'email'           => $email ?: null,
            'city'            => $city ?? $client->city,
            'profile_status'  => $wpClient['post_status'] ?? 'private',
            'premium'         => (bool) ($wpClient['premium'] ?? false),
            'premium_expire'  => $premiumExpire,
            'featured'        => (bool) ($wpClient['featured'] ?? false),
            'featured_expire' => $featuredExpire,
            'escort_expire'   => $escortExpire,
            'verified'        => $incomingVerified,
            'force_new'       => $newBadgeMode === 'force_on',
            'new_badge_mode'  => $newBadgeMode,
            'last_online_at'  => $this->ensureUnixTimestamp($wpClient['last_online'] ?? null),
            'main_image_url'  => $imageUrl ?: null,
            'last_synced_at'  => now(),
            'wp_modified_at'  => $this->normalizeWpModifiedAt($wpClient['modified_at'] ?? null),
        ];

        if (array_key_exists('needs_payment', $wpClient)) {
            $syncData['needs_payment'] = (bool) ($wpClient['needs_payment'] ?? false);
        }

        if (array_key_exists('notactive', $wpClient)) {
            $syncData['notactive'] = (bool) ($wpClient['notactive'] ?? false);
        }

        // signup_source is a CRM-side attribution signal. 'field' is set by the field-sales
        // flow and must never be clobbered by a re-sync, because WP only ever reports
        // 'crm_provisioned' (see WpDirectProvisioningService::provisionEscort).
        $wpSignupSource = $wpClient['signup_source'] ?? null;
        if ($wpSignupSource !== null && $client->signup_source !== 'field') {
            $syncData['signup_source'] = $wpSignupSource;
        }

        $client->fill($syncData);
        $client->save();

        if ($incomingVerified && ($client->verified_source === null || $client->verified_source === '')) {
            $client->forceFill([
                'verified_source' => 'manual_wp',
                'verified_source_at' => now(),
                'verified_source_actor_id' => null,
                'verified_source_reason' => $client->verified_source_reason ?: 'Imported verified state from WordPress.',
            ])->save();

            $this->markSubjectApprovedFromWp($client);
        } elseif ($previousVerifiedSource === 'kyc' && $previousVerified !== $incomingVerified) {
            app(AuditService::class)->record([
                'platform_id' => (int) $client->platform_id,
                'actor_id' => null,
                'action' => 'client.verified_conflict',
                'entity_type' => 'client',
                'entity_id' => (int) $client->id,
                'before_state' => [
                    'verified' => $previousVerified,
                    'verified_source' => $previousVerifiedSource,
                ],
                'after_state' => [
                    'verified' => $incomingVerified,
                    'verified_source' => 'manual_wp',
                ],
                'reason' => 'WordPress verified state overrode a KYC-derived verified state during sync.',
            ]);

            $client->forceFill([
                'verified_source' => 'manual_wp',
                'verified_source_at' => now(),
                'verified_source_actor_id' => null,
                'verified_source_reason' => 'WordPress verified state overrode a prior KYC-derived verified state.',
            ])->save();

            if ($incomingVerified) {
                $this->markSubjectApprovedFromWp($client);
            }
        }

        return $wasRecentlyCreated ? 'created' : 'updated';
    }

    private function markSubjectApprovedFromWp(Client $client): void
    {
        $subject = KycSubject::query()->firstOrCreate(
            ['client_id' => (int) $client->id],
            ['status' => KycSubject::STATUS_UNVERIFIED, 'grace_started_at' => now()]
        );

        $subject->forceFill([
            'status' => KycSubject::STATUS_APPROVED,
            'verified_at' => now(),
            'expires_at' => now()->addDays((int) config('kyc.reverify_interval_days', 365)),
            'last_reason_user' => 'Approved via WordPress manual verified status.',
        ])->save();

        \App\Models\KycSubjectSite::query()->updateOrCreate(
            [
                'subject_id' => (int) $subject->id,
                'platform_id' => (int) $client->platform_id,
                'wp_post_id' => (int) $client->wp_post_id,
            ],
            [
                'wp_user_id' => (int) ($client->wp_user_id ?? 0) ?: null,
            ]
        );
    }

    private function runLegacyBulkSync(ClientSyncRun $run, int $perPage, array $capability): array
    {
        $run->forceFill([
            'protocol' => 'v1',
            'fallback_reason' => $capability['fallback_reason'] ?? 'legacy_feed',
            'capability_snapshot' => $capability,
            'mode' => $run->mode === 'reconcile' ? 'full_legacy' : $run->mode,
        ])->save();

        $result = $run->mode === 'reconcile'
            ? $this->fullSync($perPage)
            : $this->deltaSync($perPage);

        $run->forceFill([
            'processed' => (int) ($result['total'] ?? 0),
            'created' => (int) ($result['created'] ?? 0),
            'updated' => (int) ($result['updated'] ?? 0),
            'skipped' => (int) ($result['skipped'] ?? 0),
            'last_heartbeat_at' => now(),
        ])->save();

        if ($run->mode === 'reconcile') {
            $this->platform->forceFill([
                'client_sync_last_reconciled_at' => now(),
                'client_sync_protocol' => 'v1',
            ])->save();
        } else {
            $this->platform->forceFill([
                'client_sync_protocol' => 'v1',
            ])->save();
        }

        return [
            'created' => (int) ($result['created'] ?? 0),
            'updated' => (int) ($result['updated'] ?? 0),
            'skipped' => (int) ($result['skipped'] ?? 0),
            'processed' => (int) ($result['total'] ?? 0),
            'tombstones_processed' => 0,
            'checkpoint_after_run' => null,
        ];
    }

    private function runV2BulkSync(ClientSyncRun $run, int $perPage, array $capability): array
    {
        $run->forceFill([
            'protocol' => 'v2',
            'fallback_reason' => null,
            'capability_snapshot' => $capability,
        ])->save();

        $runService = app(ClientSyncRunService::class);
        $mode = $run->mode === 'reconcile' ? 'reconcile' : 'delta';
        $checkpoint = $this->platform->client_sync_checkpoint_at;
        $modifiedAfter = $mode === 'delta' && $checkpoint
            ? $checkpoint->copy()->subMinutes(self::DELTA_OVERLAP_MINUTES)->toIso8601String()
            : null;

        $cursorModifiedAt = null;
        $cursorPostId = null;
        $runUpperBound = null;
        $summary = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'processed' => 0,
            'tombstones_processed' => 0,
        ];

        do {
            $response = $this->wpSync->getCursorClients(array_filter([
                'limit' => $perPage,
                'modified_after' => $modifiedAfter,
                'modified_before' => $runUpperBound,
                'cursor_modified_at' => $cursorModifiedAt,
                'cursor_post_id' => $cursorPostId,
                'mode' => $mode,
            ], static fn ($value) => $value !== null && $value !== ''));

            $runUpperBound = $runUpperBound ?: (string) ($response['run_upper_bound_modified_at'] ?? null);
            if (!$runUpperBound) {
                throw new \RuntimeException('WordPress v2 sync feed did not return a run upper bound.');
            }

            $run->forceFill([
                'run_upper_bound_modified_at' => Carbon::parse($runUpperBound, 'UTC')->utc(),
            ])->save();

            $chunk = $this->applyBulkClients(
                is_array($response['data'] ?? null) ? $response['data'] : [],
                $mode
            );

            $summary['created'] += (int) ($chunk['created'] ?? 0);
            $summary['updated'] += (int) ($chunk['updated'] ?? 0);
            $summary['skipped'] += (int) ($chunk['skipped'] ?? 0);
            $summary['processed'] += (int) ($chunk['processed'] ?? 0);

            $cursorModifiedAt = $response['next_cursor_modified_at'] ?? $cursorModifiedAt;
            $cursorPostId = isset($response['next_cursor_post_id']) ? (int) $response['next_cursor_post_id'] : $cursorPostId;

            $run = $runService->recordProgress($run, [
                'created' => (int) ($chunk['created'] ?? 0),
                'updated' => (int) ($chunk['updated'] ?? 0),
                'skipped' => (int) ($chunk['skipped'] ?? 0),
                'processed' => (int) ($chunk['processed'] ?? 0),
                'cursor_modified_at' => $cursorModifiedAt ? Carbon::parse((string) $cursorModifiedAt, 'UTC')->utc() : null,
                'cursor_post_id' => $cursorPostId,
            ]);
        } while (!empty($response['has_more']));

        if ($mode === 'reconcile') {
            $summary['tombstones_processed'] = $this->processV2Tombstones($run, $perPage);
            $this->advanceMissingClientCountsForReconcile($run->started_at ?: now());
        }

        $checkpointAfterRun = Carbon::parse($runUpperBound, 'UTC')
            ->subMinutes(self::SAFETY_LAG_MINUTES)
            ->utc();

        $platformUpdate = [
            'client_sync_checkpoint_at' => $checkpointAfterRun,
            'client_sync_checkpoint_post_id' => null,
            'client_sync_protocol' => 'v2',
            'client_sync_contract_version' => (string) ($capability['meta']['sync_contract_version'] ?? '2'),
        ];

        if ($mode === 'reconcile') {
            $platformUpdate['client_sync_last_reconciled_at'] = now();
        }

        $this->platform->forceFill($platformUpdate)->save();

        return array_merge($summary, [
            'checkpoint_after_run' => $checkpointAfterRun,
        ]);
    }

    private function processV2Tombstones(ClientSyncRun $run, int $perPage): int
    {
        $runService = app(ClientSyncRunService::class);
        $removedAfter = $this->platform->client_sync_tombstone_checkpoint_at
            ? $this->platform->client_sync_tombstone_checkpoint_at->toIso8601String()
            : null;
        $cursorRemovedAt = null;
        $cursorPostId = null;
        $removedUpperBound = null;
        $processed = 0;

        do {
            $response = $this->wpSync->getClientTombstones(array_filter([
                'limit' => $perPage,
                'removed_after' => $removedAfter,
                'removed_before' => $removedUpperBound,
                'cursor_removed_at' => $cursorRemovedAt,
                'cursor_post_id' => $cursorPostId,
            ], static fn ($value) => $value !== null && $value !== ''));

            $removedUpperBound = $removedUpperBound ?: (string) ($response['removed_upper_bound'] ?? null);
            $rows = is_array($response['data'] ?? null) ? $response['data'] : [];
            $applied = $this->applyTombstones($rows);
            $processed += $applied;

            $cursorRemovedAt = $response['next_cursor_removed_at'] ?? $cursorRemovedAt;
            $cursorPostId = isset($response['next_cursor_post_id']) ? (int) $response['next_cursor_post_id'] : $cursorPostId;

            $run = $runService->recordProgress($run, [
                'tombstones_processed' => $applied,
                'tombstone_cursor_removed_at' => $cursorRemovedAt ? Carbon::parse((string) $cursorRemovedAt, 'UTC')->utc() : null,
                'tombstone_cursor_post_id' => $cursorPostId,
            ]);
        } while (!empty($response['has_more']));

        if ($removedUpperBound) {
            $this->platform->forceFill([
                'client_sync_tombstone_checkpoint_at' => Carbon::parse($removedUpperBound, 'UTC')->utc(),
                'client_sync_tombstone_checkpoint_post_id' => null,
            ])->save();
        }

        return $processed;
    }

    private function applyBulkClients(array $wpClients, string $mode): array
    {
        if (empty($wpClients)) {
            return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'processed' => 0];
        }

        $wpPostIds = collect($wpClients)
            ->pluck('wp_post_id')
            ->filter(fn ($value) => (int) $value > 0)
            ->map(fn ($value) => (int) $value)
            ->values()
            ->all();

        $excludedIds = ClientSyncExclusion::query()
            ->where('platform_id', (int) $this->platform->id)
            ->whereIn('wp_post_id', $wpPostIds)
            ->pluck('wp_post_id')
            ->map(fn ($value) => (int) $value)
            ->all();
        $excludedLookup = array_fill_keys($excludedIds, true);

        $existingClients = Client::query()
            ->where('platform_id', (int) $this->platform->id)
            ->whereIn('wp_post_id', $wpPostIds)
            ->get()
            ->keyBy('wp_post_id');

        $now = now();
        $rows = [];
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($wpClients as $wpClient) {
            $wpPostId = (int) ($wpClient['wp_post_id'] ?? 0);
            if ($wpPostId <= 0) {
                $skipped++;
                continue;
            }

            if (isset($excludedLookup[$wpPostId])) {
                $skipped++;
                continue;
            }

            /** @var Client|null $existing */
            $existing = $existingClients->get($wpPostId);
            $rows[] = $this->buildBulkClientRow($wpClient, $existing, $mode, $now);

            if ($existing) {
                $updated++;
            } else {
                $created++;
            }
        }

        if (!empty($rows)) {
            Client::withoutRetentionRefresh(function () use ($rows): void {
                Client::query()->upsert(
                    $rows,
                    ['platform_id', 'wp_post_id'],
                    [
                        'wp_user_id',
                        'wp_profile_permalink',
                        'wp_profile_slug',
                        'client_type',
                        'name',
                        'phone_normalized',
                        'email',
                        'city',
                        'profile_status',
                        'needs_payment',
                        'notactive',
                        'premium',
                        'premium_expire',
                        'featured',
                        'featured_expire',
                        'escort_expire',
                        'verified',
                        'force_new',
                        'new_badge_mode',
                        'main_image_url',
                        'last_online_at',
                        'last_synced_at',
                        'wp_modified_at',
                        'signup_source',
                        'source_presence_status',
                        'source_missing_at',
                        'source_missing_count',
                        'last_seen_in_reconcile_at',
                        'seo_score',
                        'seo_score_breakdown',
                        'seo_score_updated_at',
                        'updated_at',
                    ]
                );
            });
        }

        // Dispatch recompute jobs for any clients whose WP bio was edited directly
        $this->dispatchStaleRecomputes($wpClients, (int) $this->platform->id);

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'processed' => $created + $updated + $skipped,
        ];
    }

    private function dispatchStaleRecomputes(array $wpClients, int $platformId): void
    {
        $stalePostIds = [];
        foreach ($wpClients as $wpClient) {
            if (!empty($wpClient['seo_quality_score_stale'])) {
                $postId = (int) ($wpClient['wp_post_id'] ?? 0);
                if ($postId > 0) {
                    $stalePostIds[] = $postId;
                }
            }
        }

        if (empty($stalePostIds)) {
            return;
        }

        $clientIds = Client::query()
            ->where('platform_id', $platformId)
            ->whereIn('wp_post_id', $stalePostIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($clientIds as $clientId) {
            RecomputeSeoScoreJob::dispatch($clientId)->onQueue('default');
        }
    }

    private function buildBulkClientRow(array $wpClient, ?Client $existing, string $mode, Carbon $now): array
    {
        $phone = mb_substr($this->normalizePhone($wpClient['phone'] ?? '', $this->platform->phone_prefix), 0, 20);
        $name = mb_substr((string) ($wpClient['name'] ?? ''), 0, 255);
        $email = mb_substr((string) ($wpClient['email'] ?? ''), 0, 255);
        $city = CityNormalizer::fromWpPayload($wpClient);
        $imageUrl = mb_substr((string) ($wpClient['main_image_url'] ?? ''), 0, 500);
        $profilePermalink = mb_substr((string) ($wpClient['wp_profile_permalink'] ?? ''), 0, 500);
        $profileSlug = mb_substr((string) ($wpClient['wp_profile_slug'] ?? ''), 0, 255);
        $premiumExpire = $this->ensureUnixTimestamp($wpClient['premium_expire'] ?? null);
        $featuredExpire = $this->ensureUnixTimestamp($wpClient['featured_expire'] ?? null);
        $newBadgeMode = $this->resolveNewBadgeMode($wpClient);
        // Preserve 'field' attribution against WP overrides (WP only knows 'crm_provisioned').
        $signupSource = ($existing?->signup_source === 'field')
            ? 'field'
            : ($wpClient['signup_source'] ?? $existing?->signup_source);

        return [
            'platform_id' => (int) $this->platform->id,
            'wp_post_id' => (int) ($wpClient['wp_post_id'] ?? 0),
            'wp_user_id' => $wpClient['wp_user_id'] ?? null,
            'wp_profile_permalink' => $profilePermalink !== '' ? $profilePermalink : null,
            'wp_profile_slug' => $profileSlug !== '' ? $profileSlug : null,
            'client_type' => 'escort',
            'name' => $name !== '' ? $name : null,
            'phone_normalized' => $phone !== '' ? $phone : null,
            'email' => $email !== '' ? $email : null,
            'city' => $city ?? $existing?->city,
            'profile_status' => $wpClient['post_status'] ?? 'private',
            'needs_payment' => array_key_exists('needs_payment', $wpClient)
                ? (bool) ($wpClient['needs_payment'] ?? false)
                : (bool) ($existing?->needs_payment ?? false),
            'notactive' => array_key_exists('notactive', $wpClient)
                ? (bool) ($wpClient['notactive'] ?? false)
                : (bool) ($existing?->notactive ?? false),
            'premium' => (bool) ($wpClient['premium'] ?? false),
            'premium_expire' => $premiumExpire,
            'featured' => (bool) ($wpClient['featured'] ?? false),
            'featured_expire' => $featuredExpire,
            'escort_expire' => $this->resolveEscortExpiry($wpClient, $premiumExpire, $featuredExpire),
            'verified' => (bool) ($wpClient['verified'] ?? false),
            'force_new' => $newBadgeMode === 'force_on',
            'new_badge_mode' => $newBadgeMode,
            'main_image_url' => $imageUrl !== '' ? $imageUrl : null,
            'last_online_at' => $this->ensureUnixTimestamp($wpClient['last_online'] ?? null),
            'last_synced_at' => $now,
            'wp_modified_at' => $this->normalizeWpModifiedAt($wpClient['modified_at'] ?? null),
            'signup_source' => $signupSource,
            'source_presence_status' => 'present',
            'source_missing_at' => null,
            'source_missing_count' => 0,
            'last_seen_in_reconcile_at' => $mode === 'reconcile' ? $now : $existing?->last_seen_in_reconcile_at,
            // SEO score: preserve '' as null, preserve valid 0 score
            'seo_score' => (function () use ($wpClient, $existing) {
                $raw = $wpClient['seo_quality_score'] ?? null;
                if ($raw === null) {
                    return $existing?->seo_score;
                }
                return $raw === '' ? null : (int) $raw;
            })(),
            'seo_score_breakdown' => isset($wpClient['seo_quality_score_breakdown'])
                ? json_encode($wpClient['seo_quality_score_breakdown'])
                : ($existing?->seo_score_breakdown !== null ? json_encode($existing->seo_score_breakdown) : null),
            'seo_score_updated_at' => isset($wpClient['seo_quality_score']) ? $now : $existing?->seo_score_updated_at,
            'created_at' => $existing?->created_at ?: $now,
            'updated_at' => $now,
        ];
    }

    private function applyTombstones(array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        $processed = 0;
        foreach ($rows as $row) {
            $wpPostId = (int) ($row['wp_post_id'] ?? 0);
            if ($wpPostId <= 0) {
                continue;
            }

            $removedAt = $this->normalizeWpModifiedAt($row['removed_at'] ?? null) ?: now();

            $affected = Client::query()
                ->where('platform_id', (int) $this->platform->id)
                ->where('wp_post_id', $wpPostId)
                ->update([
                    'source_presence_status' => 'missing',
                    'source_missing_at' => DB::raw("COALESCE(source_missing_at, '" . $removedAt->format('Y-m-d H:i:s') . "')"),
                    'source_missing_count' => DB::raw('CASE WHEN source_missing_count < 1 THEN 1 ELSE source_missing_count END'),
                    'updated_at' => now(),
                ]);

            if ($affected > 0) {
                $processed += $affected;
            }
        }

        return $processed;
    }

    private function advanceMissingClientCountsForReconcile(Carbon $runStartedAt): void
    {
        Client::query()
            ->where('platform_id', (int) $this->platform->id)
            ->where('source_presence_status', 'missing')
            ->where(function ($query) use ($runStartedAt) {
                $query->whereNull('source_missing_at')
                    ->orWhere('source_missing_at', '<', $runStartedAt);
            })
            ->update([
                'source_missing_count' => DB::raw('LEAST(source_missing_count + 1, 2)'),
                'updated_at' => now(),
            ]);
    }

    private function resolveCapabilityState(ClientSyncRun $run): array
    {
        $checkedAt = $this->platform->client_sync_capability_checked_at;
        $cachedStatus = (string) ($this->platform->client_sync_capability_status ?? '');

        if ($checkedAt && $checkedAt->gt(now()->subHours(6)) && $cachedStatus !== '') {
            if ($cachedStatus === 'v2') {
                return [
                    'protocol' => 'v2',
                    'status' => 'v2',
                    'meta' => [
                        'sync_contract_version' => $this->platform->client_sync_contract_version ?: '2',
                    ],
                ];
            }

            if ($cachedStatus === 'legacy_not_found') {
                return [
                    'protocol' => 'v1',
                    'status' => $cachedStatus,
                    'fallback_reason' => 'sync_meta_404',
                    'meta' => null,
                ];
            }
        }

        $probe = $this->wpSync->probeClientSyncMeta();
        $status = (string) ($probe['status'] ?? 'unknown');
        $meta = is_array($probe['meta'] ?? null) ? $probe['meta'] : null;

        if ($status === 'v2') {
            $this->platform->forceFill([
                'client_sync_capability_checked_at' => now(),
                'client_sync_capability_status' => 'v2',
                'client_sync_protocol' => 'v2',
                'client_sync_contract_version' => (string) ($meta['sync_contract_version'] ?? '2'),
            ])->save();

            return [
                'protocol' => 'v2',
                'status' => 'v2',
                'meta' => $meta,
            ];
        }

        if ($status === 'legacy_not_found' || $status === 'legacy') {
            $this->platform->forceFill([
                'client_sync_capability_checked_at' => now(),
                'client_sync_capability_status' => 'legacy_not_found',
                'client_sync_protocol' => 'v1',
            ])->save();

            return [
                'protocol' => 'v1',
                'status' => $status,
                'fallback_reason' => 'sync_meta_404',
                'meta' => $meta,
            ];
        }

        throw new \RuntimeException('WordPress sync capability probe returned an unsupported state.');
    }

    private function resolveNewBadgeMode(array $wpClient): string
    {
        $mode = strtolower(trim((string) ($wpClient['new_badge_mode'] ?? '')));

        if (in_array($mode, ['auto', 'force_on', 'force_off'], true)) {
            return $mode;
        }

        return !empty($wpClient['force_new']) ? 'force_on' : 'auto';
    }

    /**
     * Normalize phone number to international format (e.g., 254712345678)
     */
    private function normalizePhone(?string $phone, string $prefix = '254'): string
    {
        if (!$phone) {
            return '';
        }

        // Remove all non-digit characters except leading +
        $phone = preg_replace('/[^\d+]/', '', $phone);

        // Remove leading +
        $phone = ltrim($phone, '+');

        // If starts with 0, replace with country prefix
        if (str_starts_with($phone, '0')) {
            $phone = $prefix . substr($phone, 1);
        }

        return $phone;
    }

    private function ensureUnixTimestamp($value): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        $ts = strtotime((string) $value);

        return $ts !== false ? $ts : null;
    }

    private function resolveEscortExpiry(array $wpClient, ?int $premiumExpire, ?int $featuredExpire): ?int
    {
        $directExpiry = $this->ensureUnixTimestamp($wpClient['escort_expire'] ?? null);
        if ($directExpiry !== null) {
            return $directExpiry;
        }

        $fallbacks = array_values(array_filter([
            $premiumExpire,
            $featuredExpire,
        ], static fn($value) => $value !== null));

        if (empty($fallbacks)) {
            return null;
        }

        return max($fallbacks);
    }

    private function resolveDeltaModifiedAfter(): ?string
    {
        $lastWpModifiedAt = Client::query()
            ->where('platform_id', $this->platform->id)
            ->whereNotNull('wp_modified_at')
            ->max('wp_modified_at');

        if (!$lastWpModifiedAt) {
            return null;
        }

        return Carbon::parse((string) $lastWpModifiedAt, 'UTC')
            ->subMinutes(self::DELTA_OVERLAP_MINUTES)
            ->toIso8601String();
    }

    private function normalizeWpModifiedAt($value): ?Carbon
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        try {
            return Carbon::parse((string) $value, 'UTC')->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
