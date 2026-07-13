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
 * Bios live only in WordPress (post_content), and WordPress only returns
 * post_content in the full single-profile payload — so each bio is read
 * individually, but reads run in parallel (--concurrency) to stay fast at
 * scale. It follows the prod-change discipline: DRY-RUN (default) -> BACKUP
 * (on --apply) -> APPLY -> VERIFY (opt-in --verify).
 *
 * Run on the production box (cPanel): WordPress writes are blocked from
 * non-production environments by WpSyncService::assertRemoteWriteAllowed().
 *
 *   php artisan crm:repair-bio-links 1 --active            # dry run, active profiles on Kenya
 *   php artisan crm:repair-bio-links --all --active --apply# fix active profiles on every market
 *   php artisan crm:repair-bio-links 1 --apply             # fix every affected profile on Kenya
 *   php artisan crm:repair-bio-links 1 --post-id=1456235 --apply --verify  # pilot one profile
 */
class RepairBioLinksCommand extends Command
{
    protected $signature = 'crm:repair-bio-links
        {platform? : Platform ID or exact name. Omit and pass --all to process every active market.}
        {--all : Process all active platforms that have WordPress credentials.}
        {--active : Only repair active (published, paying, not-deactivated) profiles. Recommended first pass.}
        {--apply : Persist rewritten bios to WordPress. Without this flag the command is preview-only.}
        {--limit=0 : Stop after this many AFFECTED profiles per platform (0 = no limit).}
        {--post-id=* : Restrict to specific WP post IDs (repeatable; implies a single platform).}
        {--concurrency=8 : Parallel WordPress reads during the scan (1-20).}
        {--verify : After each write, re-read the profile and confirm no broken links remain (slower).}
        {--sleep=0 : Milliseconds to pause between WordPress writes to stay gentle on the WP host.}';

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
        $platforms = $this->resolvePlatforms();
        if ($platforms === null) {
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $active = (bool) $this->option('active');
        $limit = max(0, (int) $this->option('limit'));
        $concurrency = max(1, min(20, (int) $this->option('concurrency')));
        $verify = (bool) $this->option('verify');
        $sleepMs = max(0, (int) $this->option('sleep'));
        $postIds = collect($this->option('post-id'))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->values();

        $grand = ['scanned' => 0, 'affected' => 0, 'applied' => 0, 'verified' => 0, 'errors' => 0];

        foreach ($platforms as $platform) {
            $summary = $this->repairPlatform(
                $platform,
                compact('apply', 'active', 'limit', 'concurrency', 'verify', 'sleepMs') + ['postIds' => $postIds]
            );

            foreach (array_keys($grand) as $key) {
                $grand[$key] += $summary[$key] ?? 0;
            }
        }

        if ($platforms->count() > 1) {
            $this->newLine();
            $this->info('Grand total across ' . $platforms->count() . ' platforms:');
            $this->line(json_encode($grand + ['mode' => $apply ? 'apply' : 'dry-run'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        if (! $apply) {
            $this->comment('Dry run only. Re-run with --apply to persist the rewrites to WordPress.');
        }

        return $grand['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{scanned:int,affected:int,applied:int,verified:int,errors:int}
     */
    private function repairPlatform(Platform $platform, array $opts): array
    {
        /** @var Collection<int, int> $postIds */
        $postIds = $opts['postIds'];

        $clients = Client::query()
            ->where('platform_id', $platform->id)
            ->whereNotNull('wp_post_id')
            ->where('wp_post_id', '>', 0)
            ->when($opts['active'], fn ($q) => $q->active())
            ->when($postIds->isNotEmpty(), fn ($q) => $q->whereIn('wp_post_id', $postIds->all()))
            ->orderBy('id')
            ->get(['id', 'wp_post_id', 'name']);

        $this->newLine();
        $this->info(sprintf(
            '%s (ID %d): scanning %d %sprofiles in %s mode, concurrency %d...',
            $platform->name,
            $platform->id,
            $clients->count(),
            $opts['active'] ? 'active ' : '',
            $opts['apply'] ? 'APPLY' : 'DRY-RUN',
            $opts['concurrency']
        ));

        if ($clients->isEmpty()) {
            return ['scanned' => 0, 'affected' => 0, 'applied' => 0, 'verified' => 0, 'errors' => 0];
        }

        $wpSync = new WpSyncService($platform);
        $preview = collect();
        $backupRows = [];

        $scanned = 0;
        $affected = 0;
        $applied = 0;
        $verified = 0;
        $errors = 0;

        foreach ($clients->chunk($opts['concurrency']) as $chunk) {
            if ($opts['limit'] > 0 && $affected >= $opts['limit']) {
                break;
            }

            /** @var array<int, Client> $byPostId */
            $byPostId = $chunk->keyBy('wp_post_id');
            $bios = $wpSync->getClientBiosPool($byPostId->keys()->all(), $opts['concurrency']);

            foreach ($bios as $postId => $original) {
                $scanned++;
                $client = $byPostId[$postId];

                if ($original === null) {
                    $errors++;
                    $preview->push(['wp_post_id' => $postId, 'name' => $client->name, 'renamed' => 0, 'unwrapped' => 0, 'status' => 'read_error']);
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
                $row = ['wp_post_id' => $postId, 'name' => $client->name, 'renamed' => $renamed, 'unwrapped' => $unwrapped, 'status' => 'affected'];

                if (! $opts['apply']) {
                    $preview->push($row);
                    if ($opts['limit'] > 0 && $affected >= $opts['limit']) {
                        break;
                    }
                    continue;
                }

                // BACKUP the original before overwriting (rollback source; WP also keeps a revision).
                $backupRows[] = [
                    'wp_post_id' => (int) $postId,
                    'name' => $client->name,
                    'renamed' => $renamed,
                    'unwrapped' => $unwrapped,
                    'old_content' => $original,
                    'new_content' => $rewritten,
                    'backed_up_at' => now()->toIso8601String(),
                ];

                try {
                    $wpSync->updateClientProfile((int) $postId, ['content' => $rewritten]);
                    $applied++;
                    $row['status'] = 'repaired';

                    if ($opts['verify']) {
                        try {
                            $after = (string) ($wpSync->getClientProfile((int) $postId)['post']['content'] ?? '');
                            [, $leftRenamed, $leftUnwrapped] = $this->rewrite($after);
                            if ($leftRenamed === 0 && $leftUnwrapped === 0) {
                                $verified++;
                            } else {
                                $row['status'] = 'verify_mismatch';
                            }
                        } catch (\Throwable $e) {
                            $row['status'] = 'verify_read_error';
                        }
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    $row['status'] = 'write_error';
                    $row['error'] = mb_substr($e->getMessage(), 0, 160);
                }

                $preview->push($row);

                if ($opts['sleepMs'] > 0) {
                    usleep($opts['sleepMs'] * 1000);
                }

                if ($opts['limit'] > 0 && $affected >= $opts['limit']) {
                    break;
                }
            }

            if ($scanned % 200 < $opts['concurrency']) {
                $this->line("  ...scanned {$scanned}, affected {$affected}");
            }
        }

        $summary = [
            'platform_id' => $platform->id,
            'platform_name' => $platform->name,
            'scanned' => $scanned,
            'affected' => $affected,
            'applied' => $applied,
            'verified' => $opts['verify'] ? $verified : null,
            'errors' => $errors,
            'mode' => $opts['apply'] ? 'apply' : 'dry-run',
        ];

        $this->line(json_encode(array_filter($summary, fn ($v) => $v !== null), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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

        if ($opts['apply'] && ! empty($backupRows)) {
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

        return [
            'scanned' => $scanned,
            'affected' => $affected,
            'applied' => $applied,
            'verified' => $verified,
            'errors' => $errors,
        ];
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

    /**
     * @return Collection<int, Platform>|null  null on a resolution error (message already printed).
     */
    private function resolvePlatforms(): ?Collection
    {
        $argument = $this->argument('platform');
        $all = (bool) $this->option('all');
        $hasPostIds = ! empty($this->option('post-id'));

        if ($all) {
            if ($argument !== null) {
                $this->error('Pass either a platform or --all, not both.');

                return null;
            }
            if ($hasPostIds) {
                $this->error('--post-id targets specific posts and cannot be combined with --all.');

                return null;
            }

            $platforms = Platform::query()
                ->where('is_active', true)
                ->whereNotNull('wp_api_url')
                ->orderBy('id')
                ->get()
                ->filter(fn (Platform $p) => $this->platformHasWpCredentials($p))
                ->values();

            if ($platforms->isEmpty()) {
                $this->error('No active platforms with WordPress credentials found.');

                return null;
            }

            return $platforms;
        }

        if ($argument === null) {
            $this->error('Specify a platform (ID or name), or pass --all.');

            return null;
        }

        $platform = $this->resolvePlatform((string) $argument);
        if (! $platform) {
            $this->error('Platform not found.');

            return null;
        }
        if (! $this->platformHasWpCredentials($platform)) {
            $this->error('Platform is missing WordPress API credentials.');

            return null;
        }

        return collect([$platform]);
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
