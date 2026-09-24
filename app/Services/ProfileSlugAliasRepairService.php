<?php

namespace App\Services;

use App\Exceptions\MarketUnavailableException;
use App\Exceptions\ProfileUrlHealthUnavailableException;
use App\Models\Platform;
use App\Models\ProfileSlugAliasRepairRun;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * Checks and repairs old profile URLs (`_wp_old_slug` aliases) that send
 * visitors, or would send them, to the wrong profile. WordPress owns the rule
 * and does the work; the CRM paces it, shows it and keeps the backup.
 */
class ProfileSlugAliasRepairService
{
    /** Slugs WordPress repairs per request; each request re-plans the market. */
    public const SLICE_SIZE = 250;

    /** Rows sent back per restore request (the plugin accepts up to 1000). */
    public const RESTORE_SLICE_SIZE = 500;

    /** Most URLs one selected-URL run may name (the plugin's limit). */
    public const MAX_TARGETS = 500;

    public const CAPABILITY_SCOPED_REPAIR = 'scoped_repair';

    /** Whether an audit summary says the market can repair chosen URLs. */
    public static function supportsScopedRepair(array $summary): bool
    {
        return in_array(self::CAPABILITY_SCOPED_REPAIR, (array) ($summary['capabilities'] ?? []), true);
    }

    public function audit(Platform $platform, string $kind = '', int $page = 1, int $perPage = 25): array
    {
        return $this->call($platform, fn (WpSyncService $wp) => $wp->auditProfileSlugAliases($kind, $page, $perPage));
    }

    /** Repair one slice and return whether another should be queued. */
    public function processSlice(ProfileSlugAliasRepairRun $run): bool
    {
        $platform = Platform::query()->findOrFail((int) $run->platform_id);

        if (! $run->started_at) {
            $run->forceFill([
                'status' => ProfileSlugAliasRepairRun::STATUS_RUNNING,
                'started_at' => now(),
            ])->save();
        }

        $targets = $run->isScoped() ? array_values($run->targets) : null;
        $result = $this->call($platform, fn (WpSyncService $wp) => $wp->repairProfileSlugAliases(self::SLICE_SIZE, $targets));

        // A plugin that ignored the selection repaired part of the whole market
        // instead. Keep what it returned as the backup and stop, so the run can
        // be restored, rather than carrying on market-wide.
        $ignoredSelection = $targets !== null && empty($result['scoped']);

        $released = array_values(array_filter(
            (array) ($result['released'] ?? []),
            static fn ($row) => is_array($row) && (int) ($row['post_id'] ?? 0) > 0 && ($row['slug'] ?? '') !== ''
        ));
        $processed = max(0, (int) ($result['slugs_processed'] ?? 0));
        $remaining = max(0, (int) ($result['remaining_slugs'] ?? 0));

        $run->forceFill([
            'backup' => array_merge($run->backup ?? [], array_map(static fn (array $row): array => [
                'meta_id' => (int) ($row['meta_id'] ?? 0),
                'post_id' => (int) $row['post_id'],
                'slug' => (string) $row['slug'],
                'kind' => (string) ($row['kind'] ?? ''),
            ], $released)),
            'urls_processed' => (int) $run->urls_processed + $processed,
            'aliases_released' => (int) $run->aliases_released + count($released),
        ])->save();

        if ($ignoredSelection) {
            $run->forceFill([
                'status' => ProfileSlugAliasRepairRun::STATUS_FAILED,
                'finished_at' => now(),
                'notes' => 'The market\'s plugin ignored the URL selection and repaired other URLs. Restore this run, then update exotic-crm-sync to 1.3.14.',
            ])->save();

            return false;
        }

        // A slice that processed slugs but released nothing would come back
        // with the same plan forever; stop and say so instead of looping.
        $stalled = $processed > 0 && $released === [];
        if ($remaining === 0 || $processed === 0 || $stalled) {
            $run->forceFill([
                'status' => ProfileSlugAliasRepairRun::STATUS_COMPLETED,
                'finished_at' => now(),
                'notes' => $stalled
                    ? sprintf('%d URLs could not be repaired: WordPress kept their aliases. Check the plugin error log.', $remaining + $processed)
                    : $run->notes,
            ])->save();

            return false;
        }

        return true;
    }

    /** Put back one slice of a run's released aliases; true while more remain. */
    public function restoreSlice(ProfileSlugAliasRepairRun $run): bool
    {
        $platform = Platform::query()->findOrFail((int) $run->platform_id);
        $backup = array_values($run->backup ?? []);
        $offset = (int) $run->restored_count;
        $slice = array_slice($backup, $offset, self::RESTORE_SLICE_SIZE);

        if ($slice !== []) {
            $rows = array_map(static fn (array $row): array => [
                'meta_id' => (int) ($row['meta_id'] ?? 0),
                'post_id' => (int) $row['post_id'],
                'slug' => (string) $row['slug'],
            ], $slice);

            $this->call($platform, fn (WpSyncService $wp) => $wp->restoreProfileSlugAliases($rows));
            $run->forceFill(['restored_count' => $offset + count($slice)])->save();
        }

        if ((int) $run->restored_count >= count($backup)) {
            $run->forceFill([
                'status' => ProfileSlugAliasRepairRun::STATUS_RESTORED,
                'restored_at' => now(),
            ])->save();

            return false;
        }

        return true;
    }

    /**
     * @template T
     *
     * @param  callable(WpSyncService): T  $callback
     * @return T
     */
    private function call(Platform $platform, callable $callback)
    {
        // Operator-initiated: an admin is looking at this market right now.
        $wp = (new WpSyncService($platform))->bypassHealthGate();

        try {
            return $callback($wp);
        } catch (RequestException $exception) {
            $response = $exception->response;
            if ($response && $response->status() === 404 && (string) $response->json('code') === 'rest_no_route') {
                throw ProfileUrlHealthUnavailableException::pluginOutdated($exception);
            }

            if ($response && $response->serverError()) {
                throw ProfileUrlHealthUnavailableException::unreachable($exception);
            }

            throw $exception;
        } catch (ConnectionException|MarketUnavailableException $exception) {
            throw ProfileUrlHealthUnavailableException::unreachable($exception);
        }
    }
}
