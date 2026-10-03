<?php

namespace App\Console\Commands;

/**
 * Mark every active CRM VVIP as VVIP on its WordPress market, across all
 * markets in one run, without creating campaign posts.
 *
 * WordPress (exotic-crm-sync 1.3.20 + the matching child theme) treats a
 * profile as VVIP when it carries the `vvip` plan flag; the homepage carousel
 * campaign is optional. This sets that flag, ending with the paid VVIP
 * period, for escort clients with an active VVIP deal. Agencies are excluded.
 * A market still on 1.3.19 is reported and skipped, because it would ignore
 * flag_only and create campaigns.
 *
 * Same safety flow as crm:backfill-vvip-placements: dry-run by default,
 * --apply backs up first and verifies, --revert=<backup> undoes.
 */
class SyncVvipFlags extends BackfillVvipPlacements
{
    protected $signature = 'crm:sync-vvip-flags
        {--apply : Write changes. Without this flag the command only reports}
        {--platform= : Restrict to a single platform id (default: every market)}
        {--client= : One CRM client id}
        {--limit=5000 : Maximum number of profiles}
        {--revert= : Path to a backup file written by an earlier --apply run}';

    protected $description = 'Mark active CRM VVIP profiles as VVIP on every WordPress market (flag only, no campaign posts).';

    protected function flagOnly(): bool
    {
        return true;
    }
}
