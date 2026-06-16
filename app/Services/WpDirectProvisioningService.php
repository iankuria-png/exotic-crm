<?php

namespace App\Services;

use App\Models\Platform;
use App\Support\WpProfileFieldCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class WpDirectProvisioningService
{
    private Platform $platform;
    private string $connectionName;

    public function __construct(Platform $platform, ?array $connectionConfig = null)
    {
        $this->platform = $platform;
        $this->connectionName = 'wp_provision_' . $platform->id;

        DynamicDatabaseService::switchConnection(
            $this->connectionName,
            $connectionConfig ?? $platform->getConnectionConfig()
        );
    }

    /**
     * Create a WordPress user + escort profile post without changing WP API routes.
     *
     * @return array{
     *   wp_user_id:int,
     *   wp_post_id:int,
     *   wp_username:string,
     *   wp_email:string,
     *   wp_post_status:string,
     *   wp_post_type:string,
     *   linked_existing_user:bool,
     *   placeholder_email_used:bool
     * }
     */
    public function provisionEscort(array $payload): array
    {
        $requestId = $this->normalizeRequestId($payload['provision_request_id'] ?? null);
        $payloadHash = $this->computePayloadHash($payload);
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Name is required for WordPress provisioning.');
        }

        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $phone = trim((string) ($payload['phone'] ?? ''));
        $whatsappPayload = trim((string) ($payload['whatsapp'] ?? ''));
        $whatsapp = $whatsappPayload !== '' ? $whatsappPayload : $phone;
        $bio = trim((string) ($payload['bio'] ?? $payload['content'] ?? ''));
        $website = trim((string) ($payload['website'] ?? ''));
        $signupSource = trim((string) ($payload['signup_source'] ?? 'crm_provisioned'));
        if (!in_array($signupSource, ['crm_provisioned', 'field'], true)) {
            $signupSource = 'crm_provisioned';
        }

        $requestedUsername = trim((string) ($payload['username'] ?? ''));
        $providedPassword = (string) ($payload['password'] ?? '');
        $password = $providedPassword !== '' ? $providedPassword : Str::random(12);

        $postStatus = strtolower(trim((string) ($payload['post_status'] ?? 'private')));
        if (!in_array($postStatus, ['publish', 'private', 'draft', 'pending'], true)) {
            $postStatus = 'private';
        }

        return DB::connection($this->connectionName)->transaction(function () use (
            $requestId,
            $payloadHash,
            $name,
            $email,
            $phone,
            $website,
            $requestedUsername,
            $password,
            $postStatus,
            $whatsapp,
            $bio,
            $signupSource,
            $payload
        ): array {
            $profilePayload = $payload;
            if (($profilePayload['whatsapp'] ?? null) === null || trim((string) $profilePayload['whatsapp']) === '') {
                $profilePayload['whatsapp'] = $whatsapp;
            }

            $existing = $this->claimProvisionRequest($requestId, $payloadHash);
            if ($existing !== null) {
                return $this->hydrateExistingProvisionResult(
                    (int) ($existing['wp_post_id'] ?? 0),
                    (int) ($existing['wp_user_id'] ?? 0)
                );
            }

            $postType = $this->resolveProfilePostType();

            [
                $userId,
                $username,
                $linkedExistingUser,
                $placeholderEmailUsed,
                $resolvedEmail,
            ] = $this->resolveOrCreateUser(
                $name,
                $email,
                $requestedUsername,
                $password,
                $website
            );

            $postId = $this->createProfilePost(
                userId: $userId,
                name: $name,
                postType: $postType,
                postStatus: $postStatus,
                content: $bio
            );

            $this->storeProfileMeta(
                postId: $postId,
                payload: $profilePayload,
                postStatus: $postStatus,
                signupSource: $signupSource
            );
            $this->assignLocationTaxonomy(
                $postId,
                isset($profilePayload['region_id']) ? (int) $profilePayload['region_id'] : null,
                isset($profilePayload['city_id']) ? (int) $profilePayload['city_id'] : null
            );

            $this->upsertOption('escortid' . $userId, $postType);
            $this->upsertOption('escortpostid' . $userId, (string) $postId);
            $this->completeProvisionRequest($requestId, $payloadHash, $postId, $userId);

            return [
                'wp_user_id' => $userId,
                'wp_post_id' => $postId,
                'wp_username' => $username,
                'wp_email' => $resolvedEmail,
                'wp_post_status' => $postStatus,
                'wp_post_type' => $postType,
                'linked_existing_user' => $linkedExistingUser,
                'placeholder_email_used' => $placeholderEmailUsed,
            ];
        });
    }

    /**
     * @return array{0:int,1:string,2:bool,3:bool,4:string}
     */
    private function resolveOrCreateUser(
        string $name,
        string $email,
        string $requestedUsername,
        string $password,
        string $website
    ): array {
        $users = DB::connection($this->connectionName)->table('users');
        $options = DB::connection($this->connectionName)->table('options');

        $placeholderEmailUsed = false;
        $resolvedEmail = $email;
        if ($resolvedEmail === '') {
            $resolvedEmail = $this->buildPlaceholderEmail();
            $placeholderEmailUsed = true;
        }

        $existingByEmail = null;
        if (!$placeholderEmailUsed) {
            $existingByEmail = $users
                ->whereRaw('LOWER(user_email) = ?', [mb_strtolower($resolvedEmail)])
                ->first();
        }

        if ($existingByEmail) {
            $existingProfileId = $options
                ->where('option_name', 'escortpostid' . (int) $existingByEmail->ID)
                ->value('option_value');

            if ($existingProfileId) {
                throw new \InvalidArgumentException(
                    'This email is already linked to a WordPress profile. Use a different email.'
                );
            }

            return [
                (int) $existingByEmail->ID,
                (string) $existingByEmail->user_login,
                true,
                false,
                (string) $existingByEmail->user_email,
            ];
        }

        $usernameBase = $requestedUsername !== ''
            ? $requestedUsername
            : $this->usernameFromNameOrEmail($name, $resolvedEmail);
        $username = $this->nextAvailableUsername($this->normalizeUsername($usernameBase));

        $nicename = Str::slug($name !== '' ? $name : $username);
        if ($nicename === '') {
            $nicename = $username;
        }
        $nicename = Str::limit($nicename, 50, '');

        $now = now()->format('Y-m-d H:i:s');

        $userId = (int) $users->insertGetId([
            'user_login' => $username,
            'user_pass' => Hash::make($password),
            'user_nicename' => $nicename,
            'user_email' => $resolvedEmail,
            'user_url' => $website,
            'user_registered' => $now,
            'user_activation_key' => '',
            'user_status' => 0,
            'display_name' => $name,
        ]);

        $prefix = (string) ($this->platform->db_prefix ?? '');
        DB::connection($this->connectionName)->table('usermeta')->insert([
            [
                'user_id' => $userId,
                'meta_key' => $prefix . 'capabilities',
                'meta_value' => serialize(['subscriber' => true]),
            ],
            [
                'user_id' => $userId,
                'meta_key' => $prefix . 'user_level',
                'meta_value' => '0',
            ],
            [
                'user_id' => $userId,
                'meta_key' => 'nickname',
                'meta_value' => $name !== '' ? $name : $username,
            ],
        ]);

        return [$userId, $username, false, $placeholderEmailUsed, $resolvedEmail];
    }

    private function createProfilePost(int $userId, string $name, string $postType, string $postStatus, string $content = ''): int
    {
        $posts = DB::connection($this->connectionName)->table('posts');

        $baseSlug = Str::slug($name);
        if ($baseSlug === '') {
            $baseSlug = 'escort-profile';
        }

        $postSlug = $this->nextAvailablePostSlug($baseSlug, $postType);
        $nowLocal = now()->format('Y-m-d H:i:s');
        $nowUtc = now('UTC')->format('Y-m-d H:i:s');

        $postId = (int) $posts->insertGetId([
            'post_author' => $userId,
            'post_date' => $nowLocal,
            'post_date_gmt' => $nowUtc,
            'post_content' => $content,
            'post_title' => $name,
            'post_excerpt' => '',
            'post_status' => $postStatus,
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'post_password' => '',
            'post_name' => $postSlug,
            'to_ping' => '',
            'pinged' => '',
            'post_modified' => $nowLocal,
            'post_modified_gmt' => $nowUtc,
            'post_content_filtered' => '',
            'post_parent' => 0,
            'guid' => '',
            'menu_order' => 0,
            'post_type' => $postType,
            'post_mime_type' => '',
            'comment_count' => 0,
        ]);

        $baseUrl = rtrim((string) ($this->platform->domain ?? ''), '/');
        $guid = $baseUrl !== '' ? "{$baseUrl}/?p={$postId}" : "/?p={$postId}";
        $posts->where('ID', $postId)->update(['guid' => $guid]);

        return $postId;
    }

    private function storeProfileMeta(
        int $postId,
        array $payload,
        string $postStatus,
        string $signupSource = 'crm_provisioned'
    ): void {
        foreach ($this->profileMetaPayload($payload) as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $this->upsertPostMeta($postId, $key, $value);
        }

        $this->upsertPostMeta($postId, 'premium', '0');
        $this->upsertPostMeta($postId, 'featured', '0');
        $this->upsertPostMeta($postId, 'verified', '0');
        $this->upsertPostMeta($postId, 'independent', 'yes');
        $this->upsertPostMeta($postId, 'upload_folder', (string) (time() . random_int(100, 999)));
        $this->upsertPostMeta(
            $postId,
            'secret',
            hash('sha256', trim((string) ($payload['name'] ?? '')) . '|' . $postId . '|' . now()->timestamp . '|' . Str::random(20))
        );

        $this->upsertPostMeta($postId, 'signup_source', $signupSource);

        if ($postStatus !== 'publish') {
            $this->upsertPostMeta($postId, 'notactive', '1');
        }
    }

    private function upsertPostMeta(int $postId, string $key, mixed $value): void
    {
        DB::connection($this->connectionName)->table('postmeta')->updateOrInsert(
            ['post_id' => $postId, 'meta_key' => $key],
            ['meta_value' => $this->serializeMetaValue($value)]
        );
    }

    private function upsertOption(string $name, string $value): void
    {
        DB::connection($this->connectionName)->table('options')->updateOrInsert(
            ['option_name' => $name],
            ['option_value' => $value, 'autoload' => 'yes']
        );
    }

    private function assignLocationTaxonomy(int $postId, ?int $regionId, ?int $cityId): void
    {
        if ($regionId === null || $cityId === null) {
            return;
        }

        $connection = DB::connection($this->connectionName);
        $taxonomy = $this->resolveLocationTaxonomy();
        $regionTaxonomy = $this->findLocationTermTaxonomy($regionId, $taxonomy);
        $cityTaxonomy = $this->findLocationTermTaxonomy($cityId, $taxonomy);

        if ($regionTaxonomy === null) {
            throw new \InvalidArgumentException('Selected region term does not exist in WordPress.');
        }

        if ($cityTaxonomy === null) {
            throw new \InvalidArgumentException('Selected city term does not exist in WordPress.');
        }

        if ((int) $cityTaxonomy->parent !== $regionId) {
            throw new \InvalidArgumentException('Selected city is not a child of the selected region.');
        }

        $termTaxonomyId = (int) $cityTaxonomy->term_taxonomy_id;

        $connection->table('term_relationships')->updateOrInsert(
            [
                'object_id' => $postId,
                'term_taxonomy_id' => $termTaxonomyId,
            ],
            ['term_order' => 0]
        );

        $count = $connection->table('term_relationships')
            ->where('term_taxonomy_id', $termTaxonomyId)
            ->count();

        $connection->table('term_taxonomy')
            ->where('term_taxonomy_id', $termTaxonomyId)
            ->update(['count' => $count]);

        $this->upsertPostMeta($postId, 'country', $regionId);
        $this->upsertPostMeta($postId, 'city', $cityId);
    }

    private function resolveProfilePostType(): string
    {
        $raw = (string) DB::connection($this->connectionName)->table('options')
            ->where('option_name', 'taxonomy_profile_url')
            ->value('option_value');

        $raw = trim($raw);
        if ($raw === '') {
            return 'escort';
        }

        return preg_match('/^[A-Za-z0-9_-]+$/', $raw) === 1 ? strtolower($raw) : 'escort';
    }

    private function resolveLocationTaxonomy(): string
    {
        $raw = (string) DB::connection($this->connectionName)->table('options')
            ->where('option_name', 'taxonomy_location_url')
            ->value('option_value');

        $raw = trim($raw);

        return $raw !== '' ? $raw : 'escorts-from';
    }

    private function normalizeRequestId(mixed $value): string
    {
        $requestId = trim((string) $value);
        if ($requestId === '') {
            $requestId = (string) Str::uuid();
        }

        return substr($requestId, 0, 64);
    }

    private function computePayloadHash(array $payload): string
    {
        $allowedKeys = array_unique(array_merge(
            [
                'name',
                'email',
                'phone',
                'whatsapp',
                'city',
                'post_status',
                'username',
                'password',
                'website',
            ],
            WpProfileFieldCatalog::createProvisioningFields()
        ));

        $canonical = [];
        foreach ($allowedKeys as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $canonical[$key] = $this->canonicalizeValue($payload[$key]);
        }

        ksort($canonical);

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function canonicalizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->canonicalizeValue($item);
            }

            ksort($normalized);

            return $normalized;
        }

        if (is_bool($value)) {
            return $value;
        }

        if ($value === null) {
            return null;
        }

        return is_numeric($value) ? (string) $value : trim((string) $value);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function claimProvisionRequest(string $requestId, string $payloadHash): ?array
    {
        $connection = DB::connection($this->connectionName);

        try {
            $connection->table('exotic_crm_provisions')->insert([
                'request_id' => $requestId,
                'payload_hash' => $payloadHash,
                'status' => 'pending',
                'wp_post_id' => null,
                'wp_user_id' => null,
                'created_at' => now()->format('Y-m-d H:i:s'),
                'completed_at' => null,
            ]);

            return null;
        } catch (QueryException $exception) {
            if (!$this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }
        }

        $existing = $this->waitForProvisionRequest($requestId);
        if ($existing === null) {
            throw new \RuntimeException('Provision request is already in progress. Please retry.');
        }

        if ((string) ($existing['payload_hash'] ?? '') !== $payloadHash) {
            throw new ConflictHttpException('This provisioning request ID was already used with a different payload.');
        }

        return $existing;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function waitForProvisionRequest(string $requestId): ?array
    {
        $connection = DB::connection($this->connectionName);

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $row = (array) ($connection->table('exotic_crm_provisions')
                ->where('request_id', $requestId)
                ->first() ?? []);

            if ($row !== [] && ($row['status'] ?? null) === 'completed' && (int) ($row['wp_post_id'] ?? 0) > 0) {
                return $row;
            }

            usleep(($attempt + 1) * 100_000);
        }

        return null;
    }

    private function completeProvisionRequest(string $requestId, string $payloadHash, int $postId, int $userId): void
    {
        DB::connection($this->connectionName)->table('exotic_crm_provisions')
            ->where('request_id', $requestId)
            ->where('payload_hash', $payloadHash)
            ->update([
                'status' => 'completed',
                'wp_post_id' => $postId,
                'wp_user_id' => $userId,
                'completed_at' => now()->format('Y-m-d H:i:s'),
            ]);
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'unique')
            || str_contains($message, 'duplicate')
            || str_contains($message, 'constraint');
    }

    /**
     * @return array{
     *   wp_user_id:int,
     *   wp_post_id:int,
     *   wp_username:string,
     *   wp_email:string,
     *   wp_post_status:string,
     *   wp_post_type:string,
     *   linked_existing_user:bool,
     *   placeholder_email_used:bool
     * }
     */
    private function hydrateExistingProvisionResult(int $postId, int $userId): array
    {
        $post = DB::connection($this->connectionName)->table('posts')->where('ID', $postId)->first();
        $user = DB::connection($this->connectionName)->table('users')->where('ID', $userId)->first();

        if (!$post || !$user) {
            throw new \RuntimeException('Provision request exists but the linked WordPress profile could not be recovered.');
        }

        return [
            'wp_user_id' => (int) $user->ID,
            'wp_post_id' => (int) $post->ID,
            'wp_username' => (string) $user->user_login,
            'wp_email' => (string) $user->user_email,
            'wp_post_status' => (string) $post->post_status,
            'wp_post_type' => (string) $post->post_type,
            'linked_existing_user' => false,
            'placeholder_email_used' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function profileMetaPayload(array $payload): array
    {
        $meta = [];
        $excluded = array_flip(['name', 'email', 'bio', 'content', 'region_id', 'city_id', 'city', 'username', 'password', 'post_status', 'signup_source', 'provision_request_id']);

        foreach (array_merge(WpProfileFieldCatalog::editableFields(), ['phone', 'whatsapp', 'website', 'personal_phone']) as $key) {
            if (isset($excluded[$key]) || !array_key_exists($key, $payload)) {
                continue;
            }

            $meta[$key] = $payload[$key];
        }

        if (!empty($payload['whatsapp'])) {
            $meta['phone_available_on'] = ['1'];
        }

        return $meta;
    }

    private function serializeMetaValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            $items = array_values(array_map(static function ($item): string {
                return (string) $item;
            }, $value));

            return serialize($items);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    private function findLocationTermTaxonomy(int $termId, string $taxonomy): ?object
    {
        $row = DB::connection($this->connectionName)->table('term_taxonomy')
            ->where('term_id', $termId)
            ->where('taxonomy', $taxonomy)
            ->first();

        return $row ?: null;
    }

    private function usernameFromNameOrEmail(string $name, string $email): string
    {
        if ($email !== '' && str_contains($email, '@')) {
            $candidate = explode('@', $email)[0] ?? '';
            if ($candidate !== '') {
                return $candidate;
            }
        }

        $fallback = Str::slug($name, '');
        if ($fallback !== '') {
            return $fallback;
        }

        return 'escort';
    }

    private function normalizeUsername(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._-]/', '', $value) ?? '';

        if ($value === '') {
            $value = 'escort';
        }

        if (strlen($value) < 4) {
            $value = str_pad($value, 4, 'x');
        }

        return substr($value, 0, 60);
    }

    private function nextAvailableUsername(string $base): string
    {
        $base = substr($base, 0, 60);
        $candidate = $base;
        $suffix = 1;

        while (
            DB::connection($this->connectionName)->table('users')
                ->where('user_login', $candidate)
                ->exists()
        ) {
            $suffix++;
            $tail = (string) $suffix;
            $candidate = substr($base, 0, max(1, 60 - strlen($tail))) . $tail;
        }

        return $candidate;
    }

    private function nextAvailablePostSlug(string $base, string $postType): string
    {
        $base = Str::limit($base, 190, '');
        if ($base === '') {
            $base = 'escort-profile';
        }

        $candidate = $base;
        $suffix = 1;

        while (
            DB::connection($this->connectionName)->table('posts')
                ->where('post_type', $postType)
                ->where('post_name', $candidate)
                ->exists()
        ) {
            $suffix++;
            $tail = '-' . $suffix;
            $candidate = Str::limit($base, max(1, 190 - strlen($tail)), '') . $tail;
        }

        return $candidate;
    }

    private function buildPlaceholderEmail(): string
    {
        $domain = (string) (parse_url((string) ($this->platform->domain ?? ''), PHP_URL_HOST) ?? '');
        $domain = strtolower(trim($domain));
        $domain = preg_replace('/[^a-z0-9.-]/', '', $domain) ?? '';

        if ($domain === '') {
            $domain = 'onboard.local';
        }

        return 'onboard+' . strtolower(Str::random(10)) . '@' . $domain;
    }
}
