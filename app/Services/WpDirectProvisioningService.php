<?php

namespace App\Services;

use App\Models\Platform;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class WpDirectProvisioningService
{
    private Platform $platform;
    private string $connectionName;

    public function __construct(Platform $platform)
    {
        $this->platform = $platform;
        $this->connectionName = 'wp_provision_' . $platform->id;

        DynamicDatabaseService::switchConnection(
            $this->connectionName,
            $platform->getConnectionConfig()
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
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Name is required for WordPress provisioning.');
        }

        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $phone = trim((string) ($payload['phone'] ?? ''));
        $city = trim((string) ($payload['city'] ?? ''));
        $website = trim((string) ($payload['website'] ?? ''));
        $personalPhone = trim((string) ($payload['personal_phone'] ?? ''));

        $requestedUsername = trim((string) ($payload['username'] ?? ''));
        $providedPassword = (string) ($payload['password'] ?? '');
        $password = $providedPassword !== '' ? $providedPassword : Str::random(12);

        $postStatus = strtolower(trim((string) ($payload['post_status'] ?? 'private')));
        if (!in_array($postStatus, ['publish', 'private', 'draft', 'pending'], true)) {
            $postStatus = 'private';
        }

        return DB::connection($this->connectionName)->transaction(function () use (
            $name,
            $email,
            $phone,
            $city,
            $website,
            $personalPhone,
            $requestedUsername,
            $password,
            $postStatus
        ): array {
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
                postStatus: $postStatus
            );

            $this->storeProfileMeta(
                postId: $postId,
                name: $name,
                phone: $phone,
                city: $city,
                website: $website,
                personalPhone: $personalPhone,
                postStatus: $postStatus
            );

            $this->upsertOption('escortid' . $userId, $postType);
            $this->upsertOption('escortpostid' . $userId, (string) $postId);

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

    private function createProfilePost(int $userId, string $name, string $postType, string $postStatus): int
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
            'post_content' => '',
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
        string $name,
        string $phone,
        string $city,
        string $website,
        string $personalPhone,
        string $postStatus
    ): void {
        if ($phone !== '') {
            $this->upsertPostMeta($postId, 'phone', $phone);
        }
        if ($city !== '') {
            $this->upsertPostMeta($postId, 'city', $city);
        }
        if ($website !== '') {
            $this->upsertPostMeta($postId, 'website', $website);
        }
        if ($personalPhone !== '') {
            $this->upsertPostMeta($postId, 'personal_phone', $personalPhone);
        }

        $this->upsertPostMeta($postId, 'premium', '0');
        $this->upsertPostMeta($postId, 'featured', '0');
        $this->upsertPostMeta($postId, 'verified', '0');
        $this->upsertPostMeta($postId, 'independent', 'yes');
        $this->upsertPostMeta($postId, 'upload_folder', (string) (time() . random_int(100, 999)));
        $this->upsertPostMeta(
            $postId,
            'secret',
            hash('sha256', $name . '|' . $postId . '|' . now()->timestamp . '|' . Str::random(20))
        );

        $this->upsertPostMeta($postId, 'signup_source', 'crm_provisioned');

        if ($postStatus !== 'publish') {
            $this->upsertPostMeta($postId, 'notactive', '1');
        }
    }

    private function upsertPostMeta(int $postId, string $key, string $value): void
    {
        DB::connection($this->connectionName)->table('postmeta')->updateOrInsert(
            ['post_id' => $postId, 'meta_key' => $key],
            ['meta_value' => $value]
        );
    }

    private function upsertOption(string $name, string $value): void
    {
        DB::connection($this->connectionName)->table('options')->updateOrInsert(
            ['option_name' => $name],
            ['option_value' => $value, 'autoload' => 'yes']
        );
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
