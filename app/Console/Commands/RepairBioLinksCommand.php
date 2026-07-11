<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Platform;
use App\Services\WpSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Repair broken internal links in already-saved profile bios.
 *
 * The SEO bio generator historically injected service/attribute links using
 * bare slugs (e.g. /bdsm/, /massage/) that 404 on the live markets, whose real
 * pages use an "-escorts" suffix (/bdsm-escorts/, /massage-escorts/). This
 * command rewrites those hrefs in the saved WordPress post_content WITHOUT
 * regenerating the prose (the copy was never the problem — only the link
 * targets were). It also unwraps links to pages that do not exist at all
 * (/mature/), keeping the visible word and dropping the dead anchor.
 *
 * Bios live only in WordPress (post_content), so this reads each profile from
 * WP, rewrites in memory, and writes back. It follows the prod-change
 * discipline: DRY-RUN (default) -> BACKUP (on --apply) -> APPLY -> VERIFY.
 *
 * Run on the production box (cPanel): WordPress writes are blocked from
 * non-production environments by WpSyncService::assertRemoteWriteAllowed().
 *
 *   php artisan crm:repair-bio-links 1                 # dry run, platform 1
 *   php artisan crm:repair-bio-links 1 --limit=20      # dry run, first 20 affected
 *   php artisan crm:repair-bio-links 1 --apply         # apply + backup + verify
 *   php artisan crm:repair-bio-links 1 --post-id=1456235 --apply   # pilot one profile
 */
class RepairBioLinksCommand extends Command
{
    protected $signature = 'crm:repair-bio-links
        {platform : Platform ID or exact name}
        {--apply : Persist rewritten bios to WordPress. Without this flag the command is preview-only.}
        {--limit=0 : Stop after this many AFFECTED profiles (0 = no limit).}
        {--post-id=* : Restrict to specific WP post IDs (repeatable). Handy for piloting.}
        {--sleep=200 : Milliseconds to pause between WordPress writes to stay gentle on the WP host.}';

    protected $description = 'Rewrite broken service/attribute links in already-saved WordPress profile bios (no bio regeneration).';

    /**
     * Bare slug -> correct "-escorts" slug. These are the historical service and
     * attribute pages that 404 without the suffix. Mirrors the corrected map in
     * App\Services\Seo\LinkCatalogService. Only slugs verified to resolve on the
     * live markets are listed.
     */
    private const RENAME = [
        '/bdsm/'       => '/bdsm-escorts/',
        '/couples/'    => '/couples-escorts/',
        '/domination/' => '/domination-escorts/',
        '/massage/'    => '/massage-escorts/',
        '/fetish/'     => '/fetish-escorts/',
        '/gfe/'        => '/gfe-escorts/',
        '/black/'      => '/black-escorts/',
        '/curvy/'      => '/curvy-escorts/',
    ];

    /**
     * Slugs with no valid landing page in either form. The anchor is unwrapped:
     * the visible text stays, the dead link is removed. (/escort/ is intentionally
     * NOT here — it is a valid post-type archive and must be left untouched.)
     */
    private const UNWRAP = [
        '/mature/',
    ];

    public function handle(): int
    {
        $platform = $this->resolvePlatform((string) $this->argument('platform'));
        if (! $platform) {
            $this->error('Platform not found.');

            return self::FAILURE;
        }

        if (! $this->platformHasWpCredentials($platform)) {
            $this->error('Platform is missing WordPress API credentials.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $limit = max(0, (int) $this->option('limit'));
        $sleepMs = max(0, (int) $this->option('sleep'));
        $postIds = collect($this->option('post-id'))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->values();

        $clients = Client::query()
            ->where('platform_id', $platform->id)
            ->whereNotNull('wp_post_id')
            ->where('wp_post_id', '>', 0)
            ->when($postIds->isNotEmpty(), fn ($q) => $q->whereIn('wp_post_id', $postIds->all()))
            ->orderBy('id')
            ->get(['id', 'wp_post_id', 'name']);

        if ($clients->isEmpty()) {
            $this->info('No clients with a WordPress post ID found for this platform.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Scanning %d profiles on %s (ID %d) in %s mode. Reading each bio from WordPress...',
            $clients->count(),
            $platform->name,
            $platform->id,
            $apply ? 'APPLY' : 'DRY-RUN'
        ));

        $wpSync = new WpSyncService($platform);
        $preview = collect();
        $backupRows = [];

        $scanned = 0;
        $affected = 0;
        $applied = 0;
        $verified = 0;
        $errors = 0;

        foreach ($clients as $client) {
            if ($limit > 0 && $affected >= $limit) {
                break;
            }

            $scanned++;
            if ($scanned % 100 === 0) {
                $this->line("  ...scanned {$scanned}, affected {$affected}");
            }

            try {
                $payload = $wpSync->getClientProfile((int) $client->wp_post_id);
                $original = (string) ($payload['post']['content'] ?? '');
            } catch (\Throwable $e) {
                $errors++;
                $preview->push([
                    'wp_post_id' => $client->wp_post_id,
                    'name' => $client->name,
                    'renamed' => 0,
                    'unwrapped' => 0,
                    'status' => 'read_error',
                    'error' => mb_substr($e->getMessage(), 0, 160),
                ]);
                continue;
            }

            if ($original === '') {
                continue;
            }

            [$rewritten, $renamed, $unwrapped] = $this->rewrite($original);
            if ($renamed === 0 && $unwrapped === 0) {
                continue; // no broken links — leave byte-identical
            }

            $affected++;
            $row = [
                'wp_post_id' => $client->wp_post_id,
                'name' => $client->name,
                'renamed' => $renamed,
                'unwrapped' => $unwrapped,
                'status' => 'affected',
            ];

            if (! $apply) {
                $preview->push($row);
                continue;
            }

            // BACKUP the original before overwriting (rollback source; WP also keeps a revision).
            $backupRows[] = [
                'wp_post_id' => (int) $client->wp_post_id,
                'name' => $client->name,
                'renamed' => $renamed,
                'unwrapped' => $unwrapped,
                'old_content' => $original,
                'new_content' => $rewritten,
                'backed_up_at' => now()->toIso8601String(),
            ];

            try {
                $wpSync->updateClientProfile((int) $client->wp_post_id, ['content' => $rewritten]);
                $applied++;

                // VERIFY: re-read and confirm no broken links remain.
                try {
                    $after = (string) ($wpSync->getClientProfile((int) $client->wp_post_id)['post']['content'] ?? '');
                    [, $leftRenamed, $leftUnwrapped] = $this->rewrite($after);
                    if ($leftRenamed === 0 && $leftUnwrapped === 0) {
                        $verified++;
                        $row['status'] = 'repaired';
                    } else {
                        $row['status'] = 'verify_mismatch';
                    }
                } catch (\Throwable $e) {
                    $row['status'] = 'verify_read_error';
                }
            } catch (\Throwable $e) {
                $errors++;
                $row['status'] = 'write_error';
                $row['error'] = mb_substr($e->getMessage(), 0, 160);
            }

            $preview->push($row);

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $summary = [
            'platform_id' => $platform->id,
            'platform_name' => $platform->name,
            'scanned' => $scanned,
            'affected' => $affected,
            'applied' => $applied,
            'verified' => $verified,
            'errors' => $errors,
            'mode' => $apply ? 'apply' : 'dry-run',
        ];

        $this->newLine();
        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->table(
            ['wp_post_id', 'name', 'renamed', 'unwrapped', 'status'],
            $preview->take(25)->map(fn (array $r) => [
                $r['wp_post_id'] ?? null,
                $r['name'] ?? null,
                $r['renamed'] ?? 0,
                $r['unwrapped'] ?? 0,
                $r['status'] ?? null,
            ])->all()
        );

        if ($apply && ! empty($backupRows)) {
            $backupPath = storage_path(
                'app/bio-link-repair/platform-' . $platform->id . '-' . now()->format('Ymd_His') . '.json'
            );
            @mkdir(dirname($backupPath), 0777, true);
            file_put_contents($backupPath, json_encode([
                'platform_id' => $platform->id,
                'platform_name' => $platform->name,
                'generated_at' => now()->toIso8601String(),
                'rows' => $backupRows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $this->info('Backup (old + new content) written to: ' . $backupPath);
        }

        if (! $apply) {
            $this->comment('Dry run only. Re-run with --apply to persist the rewrites to WordPress.');
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Rewrite broken service/attribute anchors in a bio.
     *
     * Only the offending href values are changed (rename) or their anchors
     * unwrapped; every other byte is preserved so diffs stay minimal and the
     * pass is idempotent.
     *
     * @return array{0:string,1:int,2:int} [rewritten html, renamed count, unwrapped count]
     */
    private function rewrite(string $html): array
    {
        $renamed = 0;
        $unwrapped = 0;

        foreach (self::RENAME as $old => $new) {
            // Swap only the href value inside an <a> tag; keep quote style and any other attributes.
            $pattern = '#(<a\b[^>]*\bhref=)(["\'])' . preg_quote($old, '#') . '\2#i';
            $html = preg_replace_callback($pattern, function (array $m) use ($new, &$renamed) {
                $renamed++;

                return $m[1] . $m[2] . $new . $m[2];
            }, $html);
        }

        foreach (self::UNWRAP as $slug) {
            // Remove the anchor, keep its inner text.
            $pattern = '#<a\b[^>]*\bhref=(["\'])' . preg_quote($slug, '#') . '\1[^>]*>(.*?)</a>#is';
            $html = preg_replace_callback($pattern, function (array $m) use (&$unwrapped) {
                $unwrapped++;

                return $m[2];
            }, $html);
        }

        return [$html, $renamed, $unwrapped];
    }

    private function resolvePlatform(string $input): ?Platform
    {
        if (ctype_digit($input)) {
            return Platform::query()->find((int) $input);
        }

        return Platform::query()->where('name', $input)->first();
    }

    private function platformHasWpCredentials(Platform $platform): bool
    {
        return filled($platform->wp_api_url)
            && filled($platform->wp_api_user)
            && filled($platform->wp_api_password);
    }
}
