<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Platform;
use App\Models\TimelineEvent;
use App\Support\BioContactScrubber;
use Illuminate\Support\Facades\Log;

/**
 * Redacts contact details from the WordPress bio of lifecycle-restricted
 * profiles, and restores the original when a subscription is renewed.
 *
 * Contact buttons are hidden by the theme when a profile is Expired/Archived,
 * but a phone number pasted into the bio text would keep a lapsed advert
 * working. This service rewrites the stored bio and keeps the untouched
 * original on the client record (plus in the timeline event, as an audit
 * backup) so reactivation restores it verbatim.
 *
 * Every mutation is gated on the market having the lifecycle policy enabled, so
 * markets that have not opted in are never touched.
 */
class ProfileBioScrubService
{
    /**
     * Scrub one profile's bio.
     *
     * @return array{client_id:int, wp_post_id:int, name:string, action:string, redactions:int, kinds:array<string,int>}
     */
    public function scrub(Client $client, ?int $actorId = null, bool $dryRun = false): array
    {
        $client->loadMissing('platform');

        $row = [
            'client_id' => (int) $client->id,
            'wp_post_id' => (int) ($client->wp_post_id ?? 0),
            'name' => (string) $client->name,
            'action' => 'skipped',
            'redactions' => 0,
            'kinds' => [],
        ];

        $platform = $client->platform ?? Platform::query()->find((int) $client->platform_id);
        if (! $platform || ! $platform->lifecycleEnabled()) {
            $row['action'] = 'market_not_enabled';

            return $row;
        }

        // Only Expired/Archived profiles are scrubbed — an active advertiser is
        // paying for leads and may legitimately publish contact details.
        if (! $client->isPubliclyRestricted()) {
            $row['action'] = 'not_restricted';

            return $row;
        }

        $wpPostId = (int) ($client->wp_post_id ?? 0);
        if ($wpPostId <= 0) {
            $row['action'] = 'no_wp_profile';

            return $row;
        }

        $wpSync = WpSyncService::forPlatform((int) $platform->id);
        $bio = (string) data_get($wpSync->getClientProfile($wpPostId), 'post.content', '');

        $result = BioContactScrubber::scrub($bio);
        $row['redactions'] = $result['redactions'];
        $row['kinds'] = $result['kinds'];

        // Nothing found: no WordPress write at all, so a re-run over thousands of
        // clean profiles costs one read each and changes nothing.
        if ($result['redactions'] === 0) {
            $row['action'] = 'clean';

            return $row;
        }

        if ($dryRun) {
            $row['action'] = 'would_scrub';

            return $row;
        }

        $wpSync->updateClientProfile($wpPostId, ['content' => $result['clean']], bypassBioGuard: true);

        Client::withoutRetentionRefresh(function () use ($client, $bio, $result): void {
            $client->forceFill([
                // Never overwrite a previously captured original: a second scrub
                // would otherwise store already-redacted text as the "original"
                // and the advertiser's real bio would be lost on renewal.
                'bio_original_html' => $client->bio_original_html ?: $bio,
                'bio_scrubbed_at' => now(),
                'bio_redactions' => $result['redactions'],
            ])->save();
        });

        TimelineEvent::create([
            'platform_id' => (int) $client->platform_id,
            'entity_type' => 'client',
            'entity_id' => (int) $client->id,
            'event_type' => 'profile_bio_scrubbed',
            'actor_id' => $actorId,
            'content' => [
                'redactions' => $result['redactions'],
                'kinds' => $result['kinds'],
                'lifecycle_state' => (string) $client->lifecycle_state,
                // Audit backup of the original, independent of the client column.
                'original_bio_html' => $bio,
            ],
            'created_at' => now(),
        ]);

        $row['action'] = 'scrubbed';

        return $row;
    }

    /**
     * Restore the pre-scrub bio. Called when a profile returns to Active.
     *
     * @return array{client_id:int, action:string}
     */
    public function restore(Client $client, ?int $actorId = null): array
    {
        $row = ['client_id' => (int) $client->id, 'action' => 'nothing_to_restore'];

        $original = (string) ($client->bio_original_html ?? '');
        $wpPostId = (int) ($client->wp_post_id ?? 0);
        if ($original === '' || $wpPostId <= 0) {
            return $row;
        }

        // bypassBioGuard: the guard would re-scrub the very text being restored
        // if the lifecycle columns have not been refreshed yet.
        WpSyncService::forPlatform((int) $client->platform_id)
            ->updateClientProfile($wpPostId, ['content' => $original], bypassBioGuard: true);

        Client::withoutRetentionRefresh(function () use ($client): void {
            $client->forceFill([
                'bio_original_html' => null,
                'bio_scrubbed_at' => null,
                'bio_redactions' => null,
            ])->save();
        });

        TimelineEvent::create([
            'platform_id' => (int) $client->platform_id,
            'entity_type' => 'client',
            'entity_id' => (int) $client->id,
            'event_type' => 'profile_bio_restored',
            'actor_id' => $actorId,
            'content' => ['restored_length' => strlen($original)],
            'created_at' => now(),
        ]);

        $row['action'] = 'restored';

        return $row;
    }

    /**
     * Scrub or restore as the client's current lifecycle state requires. Safe to
     * call from transition code without first working out which way to move.
     */
    public function syncToLifecycle(Client $client, ?int $actorId = null): array
    {
        return $client->isPubliclyRestricted()
            ? $this->scrub($client, $actorId)
            : $this->restore($client, $actorId);
    }

    /**
     * Scrub a batch of already Expired/Archived profiles — the backfill that
     * covers everything which lapsed before the scrubber existed. Shared by the
     * crm:scrub-bios command and the "Scrub expired bios" button.
     *
     * Uses chunkById: this can span thousands of profiles and an unbounded get()
     * is what has previously exhausted memory in scheduled commands.
     *
     * @param  callable|null  $onRow  Called with each result row, for CLI output.
     * @return array{processed:int, scrubbed:int, would_scrub:int, clean:int, skipped:int, failed:int, redactions:int}
     */
    public function runBatch(
        ?int $platformId = null,
        int $limit = 500,
        bool $dryRun = false,
        ?int $actorId = null,
        ?callable $onRow = null,
    ): array {
        $summary = [
            'processed' => 0,
            'scrubbed' => 0,
            'would_scrub' => 0,
            'clean' => 0,
            'skipped' => 0,
            'failed' => 0,
            'redactions' => 0,
        ];

        if (! \App\Support\LifecyclePolicy::masterEnabled()) {
            return $summary;
        }

        $query = Client::query()
            ->whereIn('lifecycle_state', [
                \App\Support\ClientLifecycleState::EXPIRED,
                \App\Support\ClientLifecycleState::ARCHIVED,
            ])
            ->whereHas('platform', fn ($q) => $q->where('lifecycle_policy_enabled', true))
            ->orderBy('id');

        if ($platformId) {
            $query->forPlatform($platformId);
        }

        $query->chunkById(100, function ($clients) use (&$summary, $limit, $dryRun, $actorId, $onRow): bool {
            foreach ($clients as $client) {
                if ($summary['processed'] >= $limit) {
                    return false;
                }

                $summary['processed']++;

                try {
                    $row = $this->scrub($client, $actorId, $dryRun);
                    $summary['redactions'] += (int) $row['redactions'];

                    $bucket = match ($row['action']) {
                        'scrubbed' => 'scrubbed',
                        'would_scrub' => 'would_scrub',
                        'clean' => 'clean',
                        default => 'skipped',
                    };
                    $summary[$bucket]++;

                    if ($onRow) {
                        $onRow($row, $client);
                    }
                } catch (\Throwable $exception) {
                    $summary['failed']++;
                    Log::error('Bio scrub batch failed for client', [
                        'client_id' => $client->id,
                        'error' => $exception->getMessage(),
                    ]);

                    if ($onRow) {
                        $onRow(['action' => 'failed', 'error' => $exception->getMessage()], $client);
                    }
                }
            }

            return true;
        });

        return $summary;
    }

    /**
     * Non-fatal wrapper for lifecycle transitions: a bio scrub must never block
     * an expiry or archive from completing.
     */
    public function scrubQuietly(Client $client, ?int $actorId = null): void
    {
        try {
            $this->scrub($client, $actorId);
        } catch (\Throwable $exception) {
            Log::warning('Profile bio scrub failed', [
                'client_id' => $client->id,
                'wp_post_id' => $client->wp_post_id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function restoreQuietly(Client $client, ?int $actorId = null): void
    {
        try {
            $this->restore($client, $actorId);
        } catch (\Throwable $exception) {
            Log::warning('Profile bio restore failed', [
                'client_id' => $client->id,
                'wp_post_id' => $client->wp_post_id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
