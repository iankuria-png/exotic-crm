<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SupportBoardService
{
    private const REQUEST_TIMEOUT_SECONDS = 20;

    private const REQUEST_ATTEMPTS = 2;

    private const REQUEST_RETRY_DELAY_MICROSECONDS = 1_000_000;

    private const FAILURE_CACHE_MINUTES = 5;

    private string $apiUrl;

    private ?string $token;

    public function __construct(
        private readonly Platform $platform
    ) {
        $this->apiUrl = trim((string) ($platform->support_board_api_url ?: ''));
        $this->token = filled($platform->support_board_token)
            ? (string) $platform->support_board_token
            : null;
    }

    public static function forPlatform(int $platformId): self
    {
        return new self(Platform::query()->findOrFail($platformId));
    }

    public static function resolveCacheKey(int $platformId, int $clientId): string
    {
        return "sb_resolve:{$platformId}:{$clientId}";
    }

    public static function failureCacheKey(int $platformId): string
    {
        return "sb_failure:{$platformId}";
    }

    public static function clearResolveCache(Client $client): void
    {
        Cache::forget(self::resolveCacheKey((int) $client->platform_id, (int) $client->id));
    }

    public function clearFailureCache(): void
    {
        Cache::forget(self::failureCacheKey((int) $this->platform->id));
    }

    public function isConfigured(): bool
    {
        return $this->apiUrl !== '' && filled($this->token);
    }

    public function canReply(User $crmUser): bool
    {
        return !empty($crmUser->sb_agent_id) || !empty($this->platform->support_board_sender_id);
    }

    public function findUserByPhone(string $normalizedPhone): ?array
    {
        foreach ($this->phoneVariants($normalizedPhone) as $phoneVariant) {
            $user = $this->findUserBy('phone', $phoneVariant);
            if ($user) {
                return $user;
            }
        }

        return null;
    }

    public function findUserByEmail(string $email): ?array
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return null;
        }

        return $this->findUserBy('email', $email);
    }

    public function resolveClient(Client $client): array
    {
        return Cache::remember(
            self::resolveCacheKey((int) $client->platform_id, (int) $client->id),
            now()->addHour(),
            function () use ($client): array {
                // Fast path: client already linked — skip phone/email lookup
                if ($client->sb_user_id) {
                    $sbUser = $this->getUser((int) $client->sb_user_id);
                    if ($sbUser) {
                        return [
                            'matched' => true,
                            'sb_user' => $sbUser,
                            'matched_by' => $client->sb_matched_by ?? 'phone',
                            'tried' => [],
                        ];
                    }
                    // SB user no longer exists — fall through to full resolution
                }

                $phoneVariants = $this->phoneVariants((string) ($client->phone_normalized ?: ''));
                $email = strtolower(trim((string) ($client->email ?: '')));

                $matchedBy = null;
                $sbUser = $this->findUserByPhoneParallel($phoneVariants);
                if ($sbUser) {
                    $matchedBy = 'phone';
                }

                if (!$sbUser && $email !== '') {
                    $sbUser = $this->findUserByEmail($email);
                    if ($sbUser) {
                        $matchedBy = 'email';
                    }
                }

                $this->syncClientLink(
                    $client,
                    $sbUser ? (int) ($sbUser['id'] ?? 0) : null,
                    $matchedBy
                );

                return [
                    'matched' => $sbUser !== null,
                    'sb_user' => $sbUser,
                    'matched_by' => $matchedBy,
                    'tried' => [
                        'phone' => $phoneVariants,
                        'email' => $email !== '' ? $email : null,
                    ],
                ];
            }
        );
    }

    public function getConversations(int $sbUserId): array
    {
        if ($sbUserId <= 0) {
            return [];
        }

        return Cache::remember(
            "sb_conversations:{$this->platform->id}:{$sbUserId}",
            now()->addMinutes(2),
            function () use ($sbUserId): array {
                $response = $this->request('get-user-conversations', [
                    'user_id' => $sbUserId,
                ]);

                return collect(is_array($response) ? $response : [])
                    ->map(fn ($conversation) => $this->normalizeConversationSummary($conversation))
                    ->filter(fn (array $conversation) => (int) $conversation['status_code'] !== 4)
                    ->values()
                    ->all();
            }
        );
    }

    public function clearConversationsCache(int $sbUserId): void
    {
        Cache::forget("sb_conversations:{$this->platform->id}:{$sbUserId}");
    }

    /**
     * Fetch all conversations (not filtered by user). Used for bulk lead import.
     * Supports pagination via $pagination param (1-based page index).
     */
    public function getAllConversations(int $pagination = 1, ?int $statusCode = null): array
    {
        $payload = [
            'pagination' => $pagination,
        ];

        if ($statusCode !== null) {
            $payload['status_code'] = $statusCode;
        }

        $response = $this->request('get-conversations', $payload);

        return collect(is_array($response) ? $response : [])
            ->map(fn ($conversation) => $this->normalizeConversationSummary($conversation))
            ->values()
            ->all();
    }

    /**
     * Fetch conversations created after the given ID (incremental import).
     */
    public function getNewConversations(?int $afterId = null): array
    {
        $payload = [];
        if ($afterId !== null && $afterId > 0) {
            $payload['datetime'] = $afterId;
        }

        $response = $this->request('get-new-conversations', $payload);

        return collect(is_array($response) ? $response : [])
            ->map(fn ($conversation) => $this->normalizeConversationSummary($conversation))
            ->values()
            ->all();
    }

    public function getUser(int $sbUserId, bool $extra = false): ?array
    {
        if ($sbUserId <= 0) {
            return null;
        }

        $response = $this->request('get-user', [
            'user_id' => $sbUserId,
            'extra' => $extra,
        ]);

        if (!$response || !is_array($response)) {
            return null;
        }

        return $this->normalizeUser($response);
    }

    public function getUserExtra(int $sbUserId, ?string $slug = null, mixed $default = false): array
    {
        if ($sbUserId <= 0) {
            return [];
        }

        $payload = [
            'user_id' => $sbUserId,
        ];

        if ($slug !== null && trim($slug) !== '') {
            $payload['slug'] = trim($slug);
        }

        if ($default !== false) {
            $payload['default'] = $default;
        }

        $response = $this->request('get-user-extra', $payload);

        if (!is_array($response)) {
            return [];
        }

        return array_values($response);
    }

    public function updateUser(int $sbUserId, array $settings = [], array $settingsExtra = []): bool
    {
        if ($sbUserId <= 0) {
            throw new RuntimeException('Support Board user ID is required.');
        }

        $payload = array_merge([
            'user_id' => $sbUserId,
        ], array_filter(
            $settings,
            fn ($value) => $value !== null
        ));

        if (!empty($settingsExtra)) {
            $payload['settings_extra'] = $settingsExtra;
        }

        $response = $this->request('update-user', $payload);

        return (bool) $response;
    }

    /**
     * Create a new Support Board user account.
     */
    public function createUser(string $name, string $email, string $phone, ?int $wpUserId = null): ?int
    {
        $extra = $wpUserId ? json_encode(['wp-id' => [$wpUserId, 'WordPress ID']]) : '';

        $response = $this->request('add-user', array_filter([
            'first_name' => $name,
            'last_name' => '',
            'email' => $email ?: '',
            'password' => '',
            'user_type' => 'user',
            'phone' => $phone,
            'extra' => $extra ?: null,
        ], fn ($v) => $v !== null));

        if (is_array($response) && !empty($response['id'])) {
            return (int) $response['id'];
        }

        return is_numeric($response) ? (int) $response : null;
    }

    public function getConversation(int $conversationId): array
    {
        if ($conversationId <= 0) {
            return [
                'id' => null,
                'user_id' => null,
                'title' => '',
                'status_code' => null,
                'created_at' => null,
                'department' => null,
                'messages' => [],
            ];
        }

        $response = $this->request('get-conversation', [
            'conversation_id' => $conversationId,
        ]);

        $details = is_array($response['details'] ?? null) ? $response['details'] : [];
        $messages = is_array($response['messages'] ?? null) ? $response['messages'] : [];

        return [
            'id' => $this->nullableInt($details['id'] ?? $conversationId),
            'user_id' => $this->nullableInt($details['user_id'] ?? null),
            'title' => (string) ($details['title'] ?? ''),
            'status_code' => $this->nullableInt($details['conversation_status_code'] ?? null),
            'created_at' => $details['conversation_time'] ?? null,
            'department' => $details['department'] ?? null,
            'user' => [
                'first_name' => (string) ($details['first_name'] ?? ''),
                'last_name' => (string) ($details['last_name'] ?? ''),
                'full_name' => trim(((string) ($details['first_name'] ?? '')) . ' ' . ((string) ($details['last_name'] ?? ''))),
                'profile_image' => $details['profile_image'] ?? null,
                'user_type' => $details['user_type'] ?? null,
            ],
            'messages' => collect($messages)
                ->map(fn ($message) => $this->normalizeConversationMessage($message))
                ->values()
                ->all(),
        ];
    }

    public function sendMessage(int $conversationId, string $message, int $senderSbUserId): array
    {
        $message = trim($message);
        if ($conversationId <= 0 || $senderSbUserId <= 0 || $message === '') {
            throw new RuntimeException('Conversation, sender, and message are required.');
        }

        $response = $this->request('send-message', [
            'user_id' => $senderSbUserId,
            'conversation_id' => $conversationId,
            'message' => $message,
            'conversation_status_code' => 'skip',
        ]);

        return [
            'id' => $this->nullableInt($response['id'] ?? null),
            'message' => (string) ($response['message'] ?? $message),
            'queue' => (bool) ($response['queue'] ?? false),
            'notifications' => array_values(is_array($response['notifications'] ?? null) ? $response['notifications'] : []),
            'human_takeover_active' => (bool) ($response['human_takeover_active'] ?? false),
            'conversation_id' => $conversationId,
            'sender_user_id' => $senderSbUserId,
        ];
    }

    private function findUserBy(string $by, string $value): ?array
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $response = $this->request('get-user-by', [
            'by' => $by,
            'value' => $value,
        ]);

        if (!$response || !is_array($response)) {
            return null;
        }

        return $this->normalizeUser($response);
    }

    private function findUserByPhoneParallel(array $variants): ?array
    {
        $variants = array_values(array_filter($variants, fn ($v) => trim($v) !== ''));
        if (empty($variants)) {
            return null;
        }

        if (count($variants) === 1) {
            return $this->findUserBy('phone', $variants[0]);
        }

        $apiUrl = $this->apiUrl;
        $token = $this->token;
        $timeout = self::REQUEST_TIMEOUT_SECONDS;

        $responses = Http::pool(fn (Pool $pool) => collect($variants)->map(
            fn (string $variant, int $index) => $pool->as("v{$index}")
                ->asForm()
                ->acceptJson()
                ->timeout($timeout)
                ->post($apiUrl, [
                    'token' => $token,
                    'function' => 'get-user-by',
                    'by' => 'phone',
                    'value' => $variant,
                ])
        )->all());

        // Check responses in variant priority order
        foreach ($variants as $index => $variant) {
            $response = $responses["v{$index}"] ?? null;
            if (!$response || $response instanceof \Throwable || $response->failed()) {
                continue;
            }

            $body = json_decode(ltrim($response->body(), "\xEF\xBB\xBF"), true);
            if (!is_array($body) || !($body['success'] ?? false) || empty($body['response'])) {
                continue;
            }

            $user = $body['response'];
            if (is_array($user) && !empty($user['id'])) {
                return $this->normalizeUser($user);
            }
        }

        return null;
    }

    private function request(string $function, array $payload = []): mixed
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Support Board is not configured for this market.');
        }

        $this->throwIfFailureCached();

        $requestPayload = array_merge([
            'token' => $this->token,
            'function' => $function,
        ], $this->normalizeRequestPayload($payload));

        $response = $this->performRequest($function, $requestPayload);

        if ($response->failed()) {
            Log::error('SupportBoardService request failed', [
                'api_url' => $this->apiUrl,
                'function' => $function,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            $this->cacheFailure(
                $function,
                sprintf('Support Board returned HTTP %d.', $response->status()),
                $response->status(),
                $response->body(),
            );

            $response->throw();
        }

        $body = json_decode(ltrim($response->body(), "\xEF\xBB\xBF"), true);
        if (!is_array($body) || !array_key_exists('success', $body)) {
            Log::error('SupportBoardService invalid response format', [
                'api_url' => $this->apiUrl,
                'function' => $function,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            $this->cacheFailure(
                $function,
                'Support Board returned an invalid response.',
                $response->status(),
                $response->body(),
            );

            throw new RuntimeException('Support Board returned an invalid response.');
        }

        $this->clearFailureCache();

        if (!($body['success'] ?? false)) {
            $error = $body['response'] ?? 'Unknown Support Board error.';
            if (is_array($error)) {
                $error = json_encode($error);
            }

            throw new RuntimeException((string) $error);
        }

        return $body['response'] ?? null;
    }

    private function performRequest(string $function, array $requestPayload): Response
    {
        for ($attempt = 1; $attempt <= self::REQUEST_ATTEMPTS; $attempt++) {
            try {
                return Http::asForm()
                    ->acceptJson()
                    ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                    ->post($this->apiUrl, $requestPayload);
            } catch (ConnectionException $exception) {
                if ($attempt < self::REQUEST_ATTEMPTS && $this->shouldRetryConnectionException($exception)) {
                    usleep(self::REQUEST_RETRY_DELAY_MICROSECONDS);
                    continue;
                }

                Log::error('SupportBoardService connection failed', [
                    'api_url' => $this->apiUrl,
                    'function' => $function,
                    'attempt' => $attempt,
                    'message' => $exception->getMessage(),
                ]);

                $this->cacheFailure($function, $exception->getMessage());

                throw $exception;
            }
        }

        throw new RuntimeException('Support Board request failed.');
    }

    private function shouldRetryConnectionException(ConnectionException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'curl error 28')
            || str_contains($message, 'timed out');
    }

    private function throwIfFailureCached(): void
    {
        $cachedFailure = Cache::get(self::failureCacheKey((int) $this->platform->id));
        if (!is_array($cachedFailure)) {
            return;
        }

        $message = trim((string) ($cachedFailure['message'] ?? ''));

        throw new RuntimeException($message !== '' ? $message : 'Support Board is temporarily unavailable.');
    }

    private function cacheFailure(
        string $function,
        string $message,
        ?int $status = null,
        ?string $body = null,
    ): void {
        Cache::put(
            self::failureCacheKey((int) $this->platform->id),
            [
                'function' => $function,
                'message' => $message,
                'status' => $status,
                'body' => $body,
                'recorded_at' => now()->toIso8601String(),
            ],
            now()->addMinutes(self::FAILURE_CACHE_MINUTES)
        );
    }

    private function normalizeRequestPayload(array $payload): array
    {
        return collect($payload)
            ->map(fn ($value) => is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES) : $value)
            ->all();
    }

    private function phoneVariants(string $normalizedPhone): array
    {
        $digits = preg_replace('/\D+/', '', $normalizedPhone ?? '');
        if ($digits === '') {
            return [];
        }

        $prefix = preg_replace('/\D+/', '', (string) ($this->platform->phone_prefix ?: ''));
        $variants = [];

        if ($prefix !== '' && str_starts_with($digits, $prefix)) {
            $local = substr($digits, strlen($prefix));
            $local = ltrim((string) $local, '0');

            if ($local !== '') {
                $variants[] = '+' . $prefix . $local;
                $variants[] = $prefix . $local;
                $variants[] = '0' . $local;
            }
        }

        if (empty($variants)) {
            $variants[] = '+' . $digits;
            $variants[] = $digits;
            $variants[] = str_starts_with($digits, '0') ? $digits : '0' . $digits;
        }

        return array_values(array_unique(array_filter($variants)));
    }

    /**
     * Fetch all SB users' phone and email values in a single API call.
     *
     * @return array{phone?: list<array{id: int, value: string}>, email?: list<array{id: int, value: string}>}
     */
    public function fetchAllUsersWithDetails(): array
    {
        $response = $this->request('get-users-with-details', [
            'details' => ['phone', 'email'],
        ]);

        return is_array($response) ? $response : [];
    }

    /**
     * Bulk resolve SB user links for a collection of clients.
     * Makes 1 API call to fetch all SB users, then matches locally.
     *
     * @param  \Illuminate\Support\Collection<int, Client>  $clients
     * @return array<int, array{matched: bool, sb_user_id: ?int, matched_by: ?string, changed: bool}>
     */
    public function bulkResolveClients(Collection $clients): array
    {
        $rawDetails = $this->fetchAllUsersWithDetails();

        // Build phone→userId lookup (normalized: digits only)
        $phoneMap = [];
        foreach ($rawDetails['phone'] ?? [] as $entry) {
            $digits = preg_replace('/\D+/', '', (string) ($entry['value'] ?? ''));
            if ($digits !== '' && !empty($entry['id'])) {
                $phoneMap[$digits] = (int) $entry['id'];
            }
        }

        // Build email→userId lookup (lowercased)
        $emailMap = [];
        foreach ($rawDetails['email'] ?? [] as $entry) {
            $email = strtolower(trim((string) ($entry['value'] ?? '')));
            if ($email !== '' && !empty($entry['id'])) {
                $emailMap[$email] = (int) $entry['id'];
            }
        }

        $results = [];

        foreach ($clients as $client) {
            $clientId = (int) $client->id;

            // Fast path: already linked
            if ($client->sb_user_id) {
                $results[$clientId] = [
                    'matched' => true,
                    'sb_user_id' => (int) $client->sb_user_id,
                    'matched_by' => $client->sb_matched_by ?? 'phone',
                    'changed' => false,
                ];
                continue;
            }

            // Try phone variants against the map
            $phoneVariants = $this->phoneVariants((string) ($client->phone_normalized ?: ''));
            $matchedUserId = null;
            $matchedBy = null;

            foreach ($phoneVariants as $variant) {
                $digits = preg_replace('/\D+/', '', $variant);
                if (isset($phoneMap[$digits])) {
                    $matchedUserId = $phoneMap[$digits];
                    $matchedBy = 'phone';
                    break;
                }
            }

            // Fallback: try email
            if (!$matchedUserId) {
                $email = strtolower(trim((string) ($client->email ?: '')));
                if ($email !== '' && isset($emailMap[$email])) {
                    $matchedUserId = $emailMap[$email];
                    $matchedBy = 'email';
                }
            }

            // Persist the link (same path as resolveClient)
            $this->syncClientLink($client, $matchedUserId, $matchedBy);

            $results[$clientId] = [
                'matched' => $matchedUserId !== null,
                'sb_user_id' => $matchedUserId,
                'matched_by' => $matchedBy,
                'changed' => true,
            ];
        }

        return $results;
    }

    protected function syncClientLink(Client $client, ?int $sbUserId, ?string $matchedBy): void
    {
        $currentSbUserId = $client->sb_user_id ? (int) $client->sb_user_id : null;
        $currentMatchedBy = $client->sb_matched_by ?: null;
        $normalizedSbUserId = $sbUserId && $sbUserId > 0 ? $sbUserId : null;
        $normalizedMatchedBy = $matchedBy ?: null;

        if ($currentSbUserId === $normalizedSbUserId && $currentMatchedBy === $normalizedMatchedBy) {
            return;
        }

        $client->forceFill([
            'sb_user_id' => $normalizedSbUserId,
            'sb_matched_by' => $normalizedMatchedBy,
        ])->saveQuietly();
    }

    private function normalizeUser(array $user): array
    {
        $firstName = (string) ($user['first_name'] ?? '');
        $lastName = (string) ($user['last_name'] ?? '');

        return [
            'id' => $this->nullableInt($user['id'] ?? null),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => trim($firstName . ' ' . $lastName),
            'email' => $user['email'] ?? null,
            'profile_image' => $user['profile_image'] ?? null,
            'user_type' => $user['user_type'] ?? null,
            'creation_time' => $user['creation_time'] ?? null,
            'last_activity' => $user['last_activity'] ?? null,
            'department' => $user['department'] ?? null,
            'details' => array_values(is_array($user['details'] ?? null) ? $user['details'] : []),
        ];
    }

    public function normalizeUserDetails(array $details): array
    {
        $items = collect($details)
            ->filter(fn ($detail) => is_array($detail) && filled($detail['slug'] ?? null))
            ->map(function (array $detail) {
                $slug = trim((string) ($detail['slug'] ?? ''));
                $name = trim((string) ($detail['name'] ?? ''));

                return [
                    'slug' => $slug,
                    'name' => $name !== '' ? $name : \Illuminate\Support\Str::title(str_replace('_', ' ', $slug)),
                    'value' => $detail['value'] ?? null,
                ];
            })
            ->values();

        $map = $items
            ->keyBy(fn (array $detail) => $detail['slug'])
            ->all();

        return [
            'items' => $items->all(),
            'map' => $map,
        ];
    }

    private function normalizeConversationSummary($conversation): array
    {
        $attachments = $this->normalizeAttachments($conversation['attachments'] ?? null);

        return [
            'id' => $this->nullableInt($conversation['conversation_id'] ?? null),
            'user_id' => $this->nullableInt($conversation['conversation_user_id'] ?? null),
            'status_code' => (int) ($conversation['conversation_status_code'] ?? 0),
            'created_at' => $conversation['conversation_creation_time'] ?? null,
            'updated_at' => $conversation['last_update_time'] ?? null,
            'title' => (string) ($conversation['title'] ?? ''),
            'agent_id' => $this->nullableInt($conversation['agent_id'] ?? null),
            'source' => $conversation['source'] ?? null,
            'tags' => $this->normalizeJsonValue($conversation['tags'] ?? null),
            'extra' => $this->normalizeJsonValue($conversation['extra'] ?? null),
            'last_message' => (string) ($conversation['message'] ?? ''),
            'last_message_id' => $this->nullableInt($conversation['message_id'] ?? null),
            'last_message_sender' => [
                'id' => $this->nullableInt($conversation['message_user_id'] ?? null),
                'first_name' => (string) ($conversation['message_first_name'] ?? ''),
                'last_name' => (string) ($conversation['message_last_name'] ?? ''),
                'profile_image' => $conversation['message_profile_image'] ?? null,
                'user_type' => $conversation['message_user_type'] ?? null,
            ],
            'conversation_user' => [
                'first_name' => (string) ($conversation['conversation_first_name'] ?? ''),
                'last_name' => (string) ($conversation['conversation_last_name'] ?? ''),
                'profile_image' => $conversation['conversation_profile_image'] ?? null,
                'user_type' => $conversation['conversation_user_type'] ?? null,
            ],
            'attachments' => $attachments,
            'attachment_count' => count($attachments),
        ];
    }

    private function normalizeConversationMessage($message): array
    {
        $firstName = (string) ($message['first_name'] ?? '');
        $lastName = (string) ($message['last_name'] ?? '');

        return [
            'id' => $this->nullableInt($message['id'] ?? null),
            'user_id' => $this->nullableInt($message['user_id'] ?? null),
            'message' => (string) ($message['message'] ?? ''),
            'created_at' => $message['creation_time'] ?? null,
            'status_code' => $this->nullableInt($message['status_code'] ?? null),
            'conversation_id' => $this->nullableInt($message['conversation_id'] ?? null),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => trim($firstName . ' ' . $lastName),
            'profile_image' => $message['profile_image'] ?? null,
            'user_type' => $message['user_type'] ?? null,
            'payload' => $this->normalizeJsonValue($message['payload'] ?? null),
            'attachments' => $this->normalizeAttachments($message['attachments'] ?? null),
        ];
    }

    private function normalizeAttachments($attachments): array
    {
        $decoded = $this->normalizeJsonValue($attachments);

        if (!is_array($decoded)) {
            return [];
        }

        return Collection::make($decoded)
            ->map(function ($attachment) {
                if (is_array($attachment)) {
                    if (array_is_list($attachment)) {
                        return [
                            'name' => $attachment[0] ?? null,
                            'url' => $attachment[1] ?? null,
                        ];
                    }

                    return [
                        'name' => $attachment['name'] ?? $attachment['filename'] ?? null,
                        'url' => $attachment['url'] ?? $attachment['link'] ?? $attachment['path'] ?? null,
                    ];
                }

                if (is_string($attachment)) {
                    return [
                        'name' => basename($attachment),
                        'url' => $attachment,
                    ];
                }

                return null;
            })
            ->filter(fn ($attachment) => is_array($attachment) && !empty($attachment['url']))
            ->values()
            ->all();
    }

    private function normalizeJsonValue($value): mixed
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $value;
    }

    private function nullableInt($value): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        return (int) $value;
    }
}
