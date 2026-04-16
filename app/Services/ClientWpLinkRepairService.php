<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Platform;
use App\Models\WordpressPost;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class ClientWpLinkRepairService
{
    public function canAttemptRepair(Client $client): bool
    {
        $client->loadMissing('platform');

        $platform = $client->platform ?? ($client->platform_id ? Platform::find($client->platform_id) : null);

        return (int) ($client->wp_user_id ?? 0) > 0
            && $platform instanceof Platform
            && $this->platformHasDatabaseAccess($platform);
    }

    /**
     * @return array{
     *   status:string,
     *   message:string,
     *   client?:Client,
     *   wp_post_id?:int,
     *   previous_wp_post_id?:int,
     *   candidate_post_ids:list<int>,
     *   conflict_client_id?:int|null,
     *   profile_post_type?:string
     * }
     */
    public function repair(Client $client): array
    {
        $client->loadMissing('platform');

        $platform = $client->platform ?? ($client->platform_id ? Platform::find($client->platform_id) : null);
        if (!$platform) {
            throw new InvalidArgumentException('This client is not linked to a valid market.');
        }

        $wpUserId = (int) ($client->wp_user_id ?? 0);
        if ($wpUserId <= 0) {
            throw new InvalidArgumentException('This client is not linked to a WordPress user.');
        }

        if (!$this->platformHasDatabaseAccess($platform)) {
            throw new InvalidArgumentException('WordPress database credentials are incomplete for this market.');
        }

        $profilePostType = $this->resolveProfilePostType($platform);
        $candidatePostIds = $this->findCandidatePostIds($platform, $wpUserId, $profilePostType);

        if ($candidatePostIds->isEmpty()) {
            return [
                'status' => 'no_candidate',
                'message' => 'No current WordPress profile post was found for this client user.',
                'candidate_post_ids' => [],
                'profile_post_type' => $profilePostType,
            ];
        }

        if ($candidatePostIds->count() > 1) {
            return [
                'status' => 'ambiguous',
                'message' => 'Multiple WordPress profile posts were found for this client user. Repair requires manual review.',
                'candidate_post_ids' => $candidatePostIds->all(),
                'profile_post_type' => $profilePostType,
            ];
        }

        $candidatePostId = (int) $candidatePostIds->first();
        $conflictClient = Client::query()
            ->where('platform_id', (int) $platform->id)
            ->where('wp_post_id', $candidatePostId)
            ->whereKeyNot((int) $client->id)
            ->first();

        if ($conflictClient) {
            return [
                'status' => 'conflict',
                'message' => 'The matching WordPress profile is already linked to another CRM client.',
                'candidate_post_ids' => [$candidatePostId],
                'conflict_client_id' => (int) $conflictClient->id,
                'profile_post_type' => $profilePostType,
            ];
        }

        $previousWpPostId = (int) ($client->wp_post_id ?? 0);

        $client->forceFill([
            'wp_post_id' => $candidatePostId,
        ])->save();

        try {
            $syncedClient = (new ClientSyncService($platform))->syncOne($candidatePostId);
        } catch (Throwable $exception) {
            $client->forceFill([
                'wp_post_id' => $previousWpPostId > 0 ? $previousWpPostId : null,
            ])->save();

            Log::warning('WordPress link repair sync failed; restoring previous link.', [
                'client_id' => (int) $client->id,
                'platform_id' => (int) $platform->id,
                'previous_wp_post_id' => $previousWpPostId,
                'candidate_wp_post_id' => $candidatePostId,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        return [
            'status' => 'repaired',
            'message' => $previousWpPostId === $candidatePostId
                ? 'WordPress link verified successfully.'
                : 'WordPress link repaired successfully.',
            'client' => $syncedClient->loadMissing(['platform', 'assignedAgent', 'activeDeal.product']),
            'wp_post_id' => $candidatePostId,
            'previous_wp_post_id' => $previousWpPostId,
            'candidate_post_ids' => [$candidatePostId],
            'profile_post_type' => $profilePostType,
        ];
    }

    private function platformHasDatabaseAccess(Platform $platform): bool
    {
        if (!empty($platform->db_host) && !empty($platform->db_name) && !empty($platform->db_user) && !empty($platform->db_pass)) {
            return true;
        }

        $defaultConnection = (string) config('database.default');
        $defaultConfig = (array) config("database.connections.{$defaultConnection}", []);

        return ($defaultConfig['driver'] ?? null) === 'sqlite'
            && blank($platform->db_host)
            && blank($platform->db_name)
            && blank($platform->db_user);
    }

    private function resolveConnectionName(Platform $platform): string
    {
        $defaultConnection = (string) config('database.default');
        $defaultConfig = (array) config("database.connections.{$defaultConnection}", []);

        if (($defaultConfig['driver'] ?? null) === 'sqlite'
            && blank($platform->db_host)
            && blank($platform->db_name)
            && blank($platform->db_user)
        ) {
            return $defaultConnection;
        }

        $connectionName = 'client_wp_link_repair_' . $platform->id;
        DynamicDatabaseService::switchConnection($connectionName, $platform->getConnectionConfig());

        return $connectionName;
    }

    private function resolveProfilePostType(Platform $platform): string
    {
        $connectionName = $this->resolveConnectionName($platform);

        try {
            $raw = (string) WordpressPost::on($connectionName)
                ->getConnection()
                ->table('options')
                ->where('option_name', 'taxonomy_profile_url')
                ->value('option_value');
        } catch (Throwable $exception) {
            Log::warning('Failed to resolve profile post type during WordPress link repair; defaulting to escort.', [
                'platform_id' => (int) $platform->id,
                'error' => $exception->getMessage(),
            ]);

            return 'escort';
        }

        $raw = trim($raw);
        if ($raw === '') {
            return 'escort';
        }

        return preg_match('/^[A-Za-z0-9_-]+$/', $raw) === 1 ? strtolower($raw) : 'escort';
    }

    /**
     * @return Collection<int, int>
     */
    private function findCandidatePostIds(Platform $platform, int $wpUserId, string $profilePostType): Collection
    {
        $connectionName = $this->resolveConnectionName($platform);

        return WordpressPost::on($connectionName)
            ->where('post_author', $wpUserId)
            ->where('post_type', $profilePostType)
            ->whereNotIn('post_status', ['trash', 'auto-draft', 'inherit'])
            ->orderByRaw('CASE WHEN post_status IN ("publish", "private", "pending", "draft") THEN 0 ELSE 1 END')
            ->orderByDesc('post_modified')
            ->orderByDesc('ID')
            ->pluck('ID')
            ->map(fn ($id) => (int) $id)
            ->values();
    }
}
