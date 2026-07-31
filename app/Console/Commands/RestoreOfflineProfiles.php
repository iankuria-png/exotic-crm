<?php

namespace App\Console\Commands;

use App\Models\LifecycleRestoreRun;
use App\Models\Platform;
use App\Services\FeatureSettingsService;
use App\Services\ProfileLifecycleRestoreService;
use App\Support\ClientLifecycleState;
use App\Support\LifecycleRestorePacing;
use Illuminate\Console\Command;

/**
 * SEO Recovery from the CLI. Two shapes:
 *
 *   crm:restore-offline-profiles --platform=15 --dry-run
 *       one market, ad hoc — the safe way to size a backlog on production.
 *
 *   crm:restore-offline-profiles --trickle
 *       scheduled; every market on `daily_trickle` pacing does its daily quota.
 */
class RestoreOfflineProfiles extends Command
{
    protected $signature = 'crm:restore-offline-profiles
        {--platform= : Platform (market) ID}
        {--limit= : Maximum profiles to restore in this batch}
        {--state= : Force a landing state (expired|archived); default is the 90-day age rule}
        {--dry-run : Count candidates without touching WordPress}
        {--trickle : Run the daily quota for every market on daily_trickle pacing}';

    protected $description = 'Republish profiles taken offline by the legacy expiry sweep as Expired/Archived';

    public function handle(ProfileLifecycleRestoreService $restorer, FeatureSettingsService $settings): int
    {
        if ($this->option('trickle')) {
            return $this->runTrickle($restorer, $settings);
        }

        $platformId = (int) $this->option('platform');

        if ($platformId <= 0) {
            $this->error('--platform is required (or use --trickle).');

            return self::FAILURE;
        }

        $platform = Platform::query()->find($platformId);

        if (! $platform) {
            $this->error("Platform {$platformId} not found.");

            return self::FAILURE;
        }

        if (! $platform->lifecycleEnabled()) {
            $this->error("Platform {$platformId} ({$platform->name}) does not have the profile lifecycle enabled.");

            return self::FAILURE;
        }

        $state = $this->option('state');

        if ($state !== null && ! in_array($state, [ClientLifecycleState::EXPIRED, ClientLifecycleState::ARCHIVED], true)) {
            $this->error('--state must be "expired" or "archived".');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $run = LifecycleRestoreRun::create([
            'platform_id' => $platformId,
            'requested_by' => null,
            'mode' => $dryRun ? LifecycleRestoreRun::MODE_DRY : LifecycleRestoreRun::MODE_LIVE,
            'status' => LifecycleRestoreRun::STATUS_QUEUED,
            'target_state' => $state,
            'batch_limit' => (int) ($this->option('limit') ?: 200),
            'filters' => null, // defaults: paid_history + all safety toggles on
            'notes' => 'Started from the console.',
        ]);

        $this->info(sprintf(
            '%s SEO Recovery for %s (platform %d), cap %d…',
            $dryRun ? 'Previewing' : 'Running',
            $platform->name,
            $platformId,
            $run->batch_limit
        ));

        $result = $restorer->execute($run);

        $this->table(
            ['Candidates', 'Restored', 'Failed'],
            [[$result['candidates'], $result['restored'], $result['failed']]]
        );

        if ($dryRun) {
            $this->comment('Dry run — nothing was written to WordPress.');
        }

        return self::SUCCESS;
    }

    /**
     * Daily quota for every market configured to trickle. Each market gets its
     * own run row so the trickle is as auditable and revertible as a manual batch.
     */
    private function runTrickle(ProfileLifecycleRestoreService $restorer, FeatureSettingsService $settings): int
    {
        $platforms = Platform::query()->where('is_active', true)->get();
        $ran = 0;

        foreach ($platforms as $platform) {
            if (! $platform->lifecycleEnabled()) {
                continue;
            }

            $config = $settings->get(LifecycleRestorePacing::settingsKey((int) $platform->id));

            if (! is_array($config) || LifecycleRestorePacing::normalize($config['mode'] ?? null) !== LifecycleRestorePacing::DAILY_TRICKLE) {
                continue;
            }

            $quota = max((int) ($config['daily_quota'] ?? LifecycleRestorePacing::DEFAULT_DAILY_QUOTA), 1);

            $run = LifecycleRestoreRun::create([
                'platform_id' => (int) $platform->id,
                'requested_by' => null,
                'mode' => LifecycleRestoreRun::MODE_LIVE,
                'status' => LifecycleRestoreRun::STATUS_QUEUED,
                'target_state' => $config['target_state'] ?? null,
                'batch_limit' => $quota,
                'filters' => $config['filters'] ?? null,
                'notes' => 'Daily trickle.',
            ]);

            $result = $restorer->execute($run);
            $ran++;

            $this->info(sprintf(
                '%s: restored %d, failed %d, %d still eligible.',
                $platform->name,
                $result['restored'],
                $result['failed'],
                max($result['candidates'] - $result['restored'], 0)
            ));
        }

        if ($ran === 0) {
            $this->comment('No markets are on daily_trickle pacing.');
        }

        return self::SUCCESS;
    }
}
