<?php

namespace App\Services\Pbn;

use App\Models\PbnSeedBatch;
use App\Models\PbnSeedItem;
use App\Services\DynamicDatabaseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Repairs the WordPress rows that make a seeded profile self-editable.
 *
 * The parent theme links a logged-in owner to their profile through three
 * options, all written at real signup and all addressed by id or by secret
 * rather than looked up from the session:
 *
 *   escortid{user_id}     the profile post type slug — every edit template
 *                         redirects without it
 *   escortpostid{user_id} the profile post id — how the editor and the photo
 *                         page find which post to load
 *   {secret}              the profile's `secret` post meta used as an option
 *                         NAME, whose value is the owner's user id. The photo
 *                         and video uploaders resolve the profile only through
 *                         this and die with "We couldn't find a profile" before
 *                         any authorship or nonce check when it is absent.
 *
 * Provisioning wrote the first two but gated the third behind a compatibility
 * flag that PBN sites never set, so every seeded profile was created unable to
 * accept media. This repairs existing profiles in place rather than requiring a
 * revert and re-seed.
 *
 * Inspection is read-only and always available; applying is a separate call, so
 * the UI can show what would change before anything is written.
 */
class PbnProfileLinkRepairService
{
    public const STATE_OK = 'ok';
    public const STATE_REPAIRABLE = 'repairable';
    public const STATE_BLOCKED = 'blocked';

    /**
     * @return array<string, mixed>
     */
    public function inspect(PbnSeedBatch $batch, ?array $connectionConfig = null): array
    {
        return $this->run($batch, apply: false, connectionConfig: $connectionConfig);
    }

    /**
     * @return array<string, mixed>
     */
    public function repair(PbnSeedBatch $batch, ?array $connectionConfig = null): array
    {
        return $this->run($batch, apply: true, connectionConfig: $connectionConfig);
    }

    /**
     * @return array<string, mixed>
     */
    private function run(PbnSeedBatch $batch, bool $apply, ?array $connectionConfig = null): array
    {
        $batch->loadMissing('pbnSite');
        $site = $batch->pbnSite;

        if (!$site || !$site->databaseCredentialsReady()) {
            return $this->summary([], $apply, 'The PBN site has no database credentials, so profile links cannot be checked.');
        }

        $connectionName = 'pbn_repair_' . (int) $site->id;
        DynamicDatabaseService::switchConnection($connectionName, $connectionConfig ?: $site->getConnectionConfig());
        $connection = DB::connection($connectionName);
        $postType = $this->resolveProfilePostType($connection);

        $items = $batch->items()
            ->whereNotNull('target_wp_post_id')
            ->whereNotNull('target_wp_user_id')
            ->whereNotIn('status', [PbnSeedItem::STATUS_REVERTED, PbnSeedItem::STATUS_CANCELLED])
            ->with('sourceClient:id,name')
            ->orderBy('id')
            ->get();

        $rows = [];
        foreach ($items as $item) {
            $rows[] = $this->inspectItem($connection, $item, $postType, $apply);
        }

        return $this->summary($rows, $apply, null);
    }

    /**
     * @return array<string, mixed>
     */
    private function inspectItem(\Illuminate\Database\Connection $connection, PbnSeedItem $item, string $postType, bool $apply): array
    {
        $postId = (int) $item->target_wp_post_id;
        $userId = (int) $item->target_wp_user_id;

        $secret = trim((string) $connection->table('postmeta')
            ->where('post_id', $postId)
            ->where('meta_key', 'secret')
            ->value('meta_value'));

        $missing = [];
        $repaired = [];

        // The upload link. Without the secret meta itself there is nothing to
        // name the option after, so the profile needs a new secret first —
        // which is a write we make rather than a state we report as broken.
        if ($secret === '') {
            if ($apply) {
                $secret = $this->mintSecret($item, $postId);
                $connection->table('postmeta')->updateOrInsert(
                    ['post_id' => $postId, 'meta_key' => 'secret'],
                    ['meta_value' => $secret]
                );
                $repaired[] = 'secret';
            } else {
                $missing[] = 'secret';
            }
        }

        $checks = [
            'upload_secret_option' => ['name' => $secret, 'value' => (string) $userId],
            'escortid' => ['name' => 'escortid' . $userId, 'value' => $postType],
            'escortpostid' => ['name' => 'escortpostid' . $userId, 'value' => (string) $postId],
        ];

        foreach ($checks as $key => $check) {
            if ($check['name'] === '') {
                continue;
            }

            $current = $connection->table('options')->where('option_name', $check['name'])->value('option_value');
            if ((string) $current === $check['value']) {
                continue;
            }

            if (!$apply) {
                $missing[] = $key;
                continue;
            }

            // autoload 'no' matches the theme's own dynamic-user-option
            // migration: these are read on one request per owner, and
            // autoloading thousands of them inflates every page load.
            $connection->table('options')->updateOrInsert(
                ['option_name' => $check['name']],
                ['option_value' => $check['value'], 'autoload' => 'no']
            );
            $repaired[] = $key;
        }

        $state = self::STATE_OK;
        if ($missing !== []) {
            $state = self::STATE_REPAIRABLE;
        }
        if ($repaired !== []) {
            $state = self::STATE_OK;
        }

        return [
            'item_id' => (int) $item->id,
            'name' => $item->sourceClient?->name ?: ('Client #' . $item->source_client_id),
            'target_wp_post_id' => $postId,
            'target_wp_user_id' => $userId,
            'state' => $state,
            'missing' => $missing,
            'repaired' => $repaired,
        ];
    }

    /**
     * A profile with no `secret` post meta has no upload identity at all. Mint
     * one the same shape the theme does, keyed off the item so a rerun is
     * stable rather than generating a fresh secret each time.
     */
    private function mintSecret(PbnSeedItem $item, int $postId): string
    {
        return md5('pbn-repair|' . (int) $item->id . '|' . $postId . '|' . Str::random(16));
    }

    private function resolveProfilePostType(\Illuminate\Database\Connection $connection): string
    {
        $raw = trim((string) $connection->table('options')
            ->where('option_name', 'taxonomy_profile_url')
            ->value('option_value'));

        return $raw !== '' ? $raw : 'escort';
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summary(array $rows, bool $applied, ?string $error): array
    {
        $needsRepair = array_values(array_filter($rows, static fn (array $row): bool => $row['state'] === self::STATE_REPAIRABLE));
        $repaired = array_values(array_filter($rows, static fn (array $row): bool => $row['repaired'] !== []));

        return [
            'applied' => $applied,
            'error' => $error,
            'checked' => count($rows),
            'needs_repair' => count($needsRepair),
            'repaired' => count($repaired),
            'healthy' => count($rows) - count($needsRepair) - count($repaired),
            'items' => $rows,
        ];
    }
}
