<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientCredentialDispatch;
use App\Models\Platform;
use App\Models\Template;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class CredentialDeliveryService
{
    private const DEFAULT_SUPPORT_CHAT_URL = 'https://chat.cloud.board.support/1369683147';

    public const RESET_PASSWORD_DISABLED_MESSAGE = 'A linked WordPress user and market database credentials are required to reset credentials.';
    public const LOGIN_AS_CLIENT_DISABLED_MESSAGE = 'A linked WordPress profile and market API credentials are required to generate a client session link.';
    public const ACCESS_LINKS_DISABLED_MESSAGE = 'No WordPress login or profile URL is configured for this market.';

    public function __construct(
        private readonly NotificationService $notificationService
    ) {
    }

    public function createDispatch(Client $client, array $payload, int $actorId): ClientCredentialDispatch
    {
        $normalized = $this->normalizePayload($client, $payload);
        $idempotencyKey = (string) data_get($normalized['payload'], 'idempotency_key', '');

        $existing = $this->findRecentIdempotentDispatch($client, $idempotencyKey);
        if ($existing) {
            return $existing;
        }

        $dispatch = ClientCredentialDispatch::create([
            'client_id' => (int) $client->id,
            'platform_id' => (int) $client->platform_id,
            'method' => $normalized['method'],
            'channel' => $normalized['channel'],
            'timing' => $normalized['timing'],
            'status' => $normalized['timing'] === 'manual_send_later' ? 'deferred' : 'failed',
            'recipient_email' => $normalized['recipient_email'],
            'recipient_phone' => $normalized['recipient_phone'],
            'error_message' => null,
            'payload' => $normalized['payload'],
            'provider_results' => null,
            'created_by' => $actorId,
            'sent_at' => null,
        ]);

        if ($normalized['timing'] === 'manual_send_later') {
            return $dispatch->fresh();
        }

        return $this->deliverNow($client, $dispatch, $normalized, $actorId);
    }

    public function retryDispatch(
        Client $client,
        ClientCredentialDispatch $dispatch,
        int $actorId,
        array $overrides = []
    ): ClientCredentialDispatch {
        if ((int) $dispatch->client_id !== (int) $client->id) {
            throw new \InvalidArgumentException('Credential dispatch does not belong to this client.');
        }

        $payload = [
            'method' => $dispatch->method,
            'channel' => $dispatch->channel,
            'timing' => 'send_now',
            'recipient_email' => $dispatch->recipient_email,
            'recipient_phone' => $dispatch->recipient_phone,
            'reason' => data_get($dispatch->payload, 'reason'),
        ];

        $normalized = $this->normalizePayload($client, array_merge($payload, $overrides));

        return $this->deliverNow($client, $dispatch, $normalized, $actorId);
    }

    public function accessContext(Client $client): array
    {
        $client->loadMissing('platform');
        $platform = $client->platform;

        if (!$platform) {
            throw new \InvalidArgumentException('Client platform is required to resolve access context.');
        }

        $messageBundle = $this->buildMessageBundle($client, $platform, 'setup_link', null);

        $canResetPassword = $this->canResetPassword($client, $platform);
        $canGenerateSessionLink = $this->canGenerateSessionLink($client, $platform);
        $hasAccessLinks = filled($messageBundle['login_url'] ?? null)
            || filled($messageBundle['setup_url'] ?? null)
            || filled($messageBundle['profile_url'] ?? null);

        return [
            'wp_username' => $messageBundle['wp_username'] ?? null,
            'login_url' => $messageBundle['login_url'] ?? null,
            'setup_url' => $messageBundle['setup_url'] ?? null,
            'profile_url' => $messageBundle['profile_url'] ?? null,
            'can_reset_password' => $canResetPassword,
            'can_generate_session_link' => $canGenerateSessionLink,
            'messages' => [
                'reset_password' => $canResetPassword ? null : self::RESET_PASSWORD_DISABLED_MESSAGE,
                'login_as_client' => $canGenerateSessionLink ? null : self::LOGIN_AS_CLIENT_DISABLED_MESSAGE,
                'access_links' => $hasAccessLinks ? null : self::ACCESS_LINKS_DISABLED_MESSAGE,
            ],
        ];
    }

    public function resetCredentials(Client $client, array $payload): array
    {
        $client->loadMissing('platform');
        $platform = $client->platform;

        if (!$platform) {
            throw new \InvalidArgumentException('Client platform is required to reset credentials.');
        }

        if (!$this->canResetPassword($client, $platform)) {
            throw new \InvalidArgumentException(self::RESET_PASSWORD_DISABLED_MESSAGE);
        }

        $temporaryPassword = trim((string) ($payload['temporary_password'] ?? ''));
        if ($temporaryPassword === '') {
            $temporaryPassword = $this->generateTemporaryPassword();
        }

        $this->setWordPressPassword($platform, (int) ($client->wp_user_id ?? 0), $temporaryPassword);

        return [
            'access_context' => $this->accessContext($client),
            'revealed' => [
                'password' => $temporaryPassword,
            ],
        ];
    }

    public function createClientSessionLink(Client $client, array $payload): array
    {
        $client->loadMissing('platform');
        $platform = $client->platform;

        if (!$platform) {
            throw new \InvalidArgumentException('Client platform is required to generate a client session link.');
        }

        if (!$this->canGenerateSessionLink($client, $platform)) {
            throw new \InvalidArgumentException(self::LOGIN_AS_CLIENT_DISABLED_MESSAGE);
        }

        $target = trim((string) ($payload['target'] ?? 'edit_profile'));
        if ($target === '') {
            $target = 'edit_profile';
        }

        $result = (new WpSyncService($platform))->createClientSessionLink(
            (int) ($client->wp_post_id ?? 0),
            [
                'target' => $target,
                'issued_by' => trim((string) ($payload['issued_by'] ?? 'crm')),
                'reason' => trim((string) ($payload['reason'] ?? 'Client session link from CRM')),
            ]
        );

        $url = trim((string) ($result['url'] ?? ''));
        if ($url === '') {
            throw new \RuntimeException('WordPress did not return a usable client session link.');
        }

        return [
            'url' => $url,
            'expires_at' => $result['expires_at'] ?? null,
            'target' => (string) ($result['target'] ?? $target),
        ];
    }

    private function normalizePayload(Client $client, array $payload): array
    {
        $method = (string) ($payload['method'] ?? 'setup_link');
        if (!in_array($method, ['setup_link', 'temporary_password'], true)) {
            throw new \InvalidArgumentException('Invalid credential method.');
        }

        $channel = (string) ($payload['channel'] ?? 'both');
        if (!in_array($channel, ['email', 'sms', 'both'], true)) {
            throw new \InvalidArgumentException('Invalid credential channel.');
        }

        $timing = (string) ($payload['timing'] ?? 'send_now');
        if (!in_array($timing, ['send_now', 'manual_send_later'], true)) {
            throw new \InvalidArgumentException('Invalid credential timing.');
        }

        $recipientEmail = isset($payload['recipient_email'])
            ? trim((string) $payload['recipient_email'])
            : trim((string) ($client->email ?? ''));
        if ($recipientEmail === '') {
            $recipientEmail = null;
        }

        $client->loadMissing('platform');
        $phonePrefix = (string) ($client->platform?->phone_prefix ?: '254');

        $recipientPhone = isset($payload['recipient_phone'])
            ? $this->normalizePhone((string) $payload['recipient_phone'], $phonePrefix)
            : $this->normalizePhone((string) ($client->phone_normalized ?? ''), $phonePrefix);
        if ($recipientPhone === '') {
            $recipientPhone = null;
        }

        if (in_array($channel, ['email', 'both'], true) && !$recipientEmail) {
            if ($timing === 'send_now') {
                throw new \InvalidArgumentException('Recipient email is required for the selected credential channel.');
            }
        }

        if (in_array($channel, ['sms', 'both'], true) && !$recipientPhone) {
            if ($timing === 'send_now') {
                throw new \InvalidArgumentException('Recipient phone is required for the selected credential channel.');
            }
        }

        $reason = trim((string) ($payload['reason'] ?? 'Client credential dispatch from CRM'));
        if ($reason === '') {
            $reason = 'Client credential dispatch from CRM';
        }

        $idempotencyKey = trim((string) ($payload['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') {
            $idempotencyKey = $this->buildIdempotencyKey([
                'client_id' => (int) $client->id,
                'method' => $method,
                'channel' => $channel,
                'timing' => $timing,
                'recipient_email' => (string) ($recipientEmail ?? ''),
                'recipient_phone' => (string) ($recipientPhone ?? ''),
                'reason' => $reason,
            ]);
        }

        $dispatchPayload = [
            'reason' => $reason,
            'source' => (string) ($payload['source'] ?? 'crm'),
            'idempotency_key' => $idempotencyKey,
            'recommended' => [
                'method' => 'setup_link',
                'channel' => 'both',
                'timing' => 'send_now',
            ],
        ];

        return [
            'method' => $method,
            'channel' => $channel,
            'timing' => $timing,
            'recipient_email' => $recipientEmail,
            'recipient_phone' => $recipientPhone,
            'temporary_password' => isset($payload['temporary_password'])
                ? trim((string) $payload['temporary_password'])
                : '',
            'payload' => $dispatchPayload,
            'reason' => $reason,
        ];
    }

    private function deliverNow(
        Client $client,
        ClientCredentialDispatch $dispatch,
        array $normalized,
        int $actorId
    ): ClientCredentialDispatch {
        $client->loadMissing('platform');
        $platform = $client->platform;

        if (!$platform) {
            throw new \InvalidArgumentException('Client platform is required for credential delivery.');
        }

        $channelResults = [];
        $errors = [];
        $requestedChannels = $this->resolveRequestedChannels($normalized['channel']);

        $temporaryPassword = null;
        if ($normalized['method'] === 'temporary_password') {
            $temporaryPassword = $normalized['temporary_password'] !== ''
                ? $normalized['temporary_password']
                : $this->generateTemporaryPassword();

            $this->setWordPressPassword($platform, (int) ($client->wp_user_id ?? 0), $temporaryPassword);
        }

        $messageBundle = $this->buildMessageBundle($client, $platform, $normalized['method'], $temporaryPassword);
        if (
            $normalized['method'] === 'setup_link'
            && empty($messageBundle['setup_url'])
            && empty($messageBundle['login_url'])
            && empty($messageBundle['profile_url'])
        ) {
            throw new \InvalidArgumentException(
                'No WordPress login or profile URL is configured for this market. Use temporary password or configure platform domain.'
            );
        }

        if (in_array('email', $requestedChannels, true)) {
            if (!$normalized['recipient_email']) {
                $channelResults['email'] = [
                    'success' => false,
                    'status' => 'failed',
                    'provider' => 'mail',
                    'provider_response' => 'Recipient email is missing.',
                ];
                $errors[] = 'Email recipient is missing.';
            } else {
                $emailResult = $this->sendEmail(
                    $normalized['recipient_email'],
                    (string) $messageBundle['subject'],
                    (string) $messageBundle['email_body']
                );
                $channelResults['email'] = $emailResult;
                if (!$emailResult['success']) {
                    $errors[] = 'Email send failed: ' . ($emailResult['provider_response'] ?? 'unknown error');
                }
            }
        }

        if (in_array('sms', $requestedChannels, true)) {
            if (!$normalized['recipient_phone']) {
                $channelResults['sms'] = [
                    'success' => false,
                    'status' => 'failed',
                    'provider' => null,
                    'provider_response' => 'Recipient phone is missing.',
                ];
                $errors[] = 'SMS recipient phone is missing.';
            } else {
                $smsResult = $this->notificationService->sendSms(
                    $normalized['recipient_phone'],
                    (string) $messageBundle['sms_body'],
                    [
                        'platform_id' => (int) $client->platform_id,
                        'client_id' => (int) $client->id,
                        'notification_type' => 'client_credentials',
                        'credential_dispatch_id' => (int) $dispatch->id,
                    ]
                );

                $channelResults['sms'] = $smsResult;
                if (empty($smsResult['success'])) {
                    $errors[] = 'SMS send failed: ' . ($smsResult['provider_response'] ?? 'unknown error');
                }
            }
        }

        $successCount = collect($requestedChannels)
            ->filter(fn (string $channel) => !empty($channelResults[$channel]['success']))
            ->count();

        $status = 'failed';
        if ($successCount === count($requestedChannels)) {
            $status = 'sent';
        } elseif ($successCount > 0) {
            $status = 'partial';
        }

        $redactedPayload = array_merge($normalized['payload'], [
            'method_context' => [
                'login_url' => $messageBundle['login_url'] ?? null,
                'setup_url' => $messageBundle['setup_url'] ?? null,
                'profile_url' => $messageBundle['profile_url'] ?? null,
                'wp_username' => $messageBundle['wp_username'] ?? null,
                'temporary_password_generated' => $normalized['method'] === 'temporary_password',
                'temporary_password_length' => $normalized['method'] === 'temporary_password' ? strlen((string) $temporaryPassword) : null,
            ],
        ]);

        $dispatch->forceFill([
            'method' => $normalized['method'],
            'channel' => $normalized['channel'],
            'timing' => $normalized['timing'],
            'status' => $status,
            'recipient_email' => $normalized['recipient_email'],
            'recipient_phone' => $normalized['recipient_phone'],
            'payload' => $redactedPayload,
            'provider_results' => $channelResults,
            'error_message' => empty($errors) ? null : implode(' | ', $errors),
            'created_by' => $actorId,
            'sent_at' => in_array($status, ['sent', 'partial'], true) ? now() : null,
        ])->save();

        return $dispatch->fresh();
    }

    private function sendEmail(string $to, string $subject, string $body): array
    {
        try {
            Mail::raw($body, function ($message) use ($to, $subject) {
                $message->to($to)->subject($subject);
            });

            return [
                'success' => true,
                'status' => 'sent',
                'provider' => (string) config('mail.default', 'smtp'),
                'provider_response' => 'Email accepted by mailer.',
            ];
        } catch (\Throwable $exception) {
            Log::error('Credential email dispatch failed', [
                'email' => $to,
                'error' => $exception->getMessage(),
            ]);

            return [
                'success' => false,
                'status' => 'failed',
                'provider' => (string) config('mail.default', 'smtp'),
                'provider_response' => $exception->getMessage(),
            ];
        }
    }

    private function buildMessageBundle(
        Client $client,
        Platform $platform,
        string $method,
        ?string $temporaryPassword
    ): array {
        $profileUrl = $client->wp_profile_url;
        $baseUrl = $this->resolvePlatformBaseUrl($platform, $profileUrl);
        $loginUrl = $baseUrl ? rtrim($baseUrl, '/') . '/wp-login.php' : null;
        $setupUrl = $baseUrl ? rtrim($baseUrl, '/') . '/wp-login.php?action=lostpassword' : null;
        $wpUsername = $this->resolveWpUsername($platform, (int) ($client->wp_user_id ?? 0));
        $supportChatUrl = trim((string) ($platform->support_chat_url ?: self::DEFAULT_SUPPORT_CHAT_URL));

        $clientName = trim((string) ($client->name ?: 'there'));

        $placeholders = [
            'clientName' => $clientName,
            'wpUsername' => (string) ($wpUsername ?? ''),
            'temporaryPassword' => (string) ($temporaryPassword ?? ''),
            'loginUrl' => (string) ($loginUrl ?? ''),
            'setupUrl' => (string) ($setupUrl ?? ''),
            'profileUrl' => (string) ($profileUrl ?? ''),
            'supportChatUrl' => $supportChatUrl,
        ];

        $category = $method === 'temporary_password'
            ? 'credential_temp_password'
            : 'credential_setup_link';

        $emailTemplate = $this->resolveTemplate((int) $platform->id, $category, 'email');
        $smsTemplate = $this->resolveTemplate((int) $platform->id, $category, 'sms');

        if ($emailTemplate || $smsTemplate) {
            $subject = $emailTemplate
                ? $this->interpolate((string) ($emailTemplate->subject ?? ''), $placeholders)
                : ($method === 'temporary_password'
                    ? 'Your Exotic profile temporary credentials'
                    : 'Set up your Exotic profile access');

            $emailBody = $emailTemplate
                ? $this->interpolate((string) $emailTemplate->body, $placeholders)
                : $this->buildDefaultEmailBody($method, $clientName, $wpUsername, $temporaryPassword, $loginUrl, $setupUrl, $profileUrl, $supportChatUrl);

            $smsBody = $smsTemplate
                ? $this->interpolate((string) $smsTemplate->body, $placeholders)
                : $this->buildDefaultSmsBody($method, $clientName, $wpUsername, $temporaryPassword, $loginUrl, $setupUrl, $profileUrl, $supportChatUrl);

            return [
                'subject' => $subject,
                'sms_body' => $smsBody,
                'email_body' => $emailBody,
                'profile_url' => $profileUrl,
                'login_url' => $loginUrl,
                'setup_url' => $setupUrl,
                'wp_username' => $wpUsername,
            ];
        }

        // Fallback: hardcoded defaults (no templates configured)
        return [
            'subject' => $method === 'temporary_password'
                ? 'Your Exotic profile temporary credentials'
                : 'Set up your Exotic profile access',
            'sms_body' => $this->buildDefaultSmsBody($method, $clientName, $wpUsername, $temporaryPassword, $loginUrl, $setupUrl, $profileUrl, $supportChatUrl),
            'email_body' => $this->buildDefaultEmailBody($method, $clientName, $wpUsername, $temporaryPassword, $loginUrl, $setupUrl, $profileUrl, $supportChatUrl),
            'profile_url' => $profileUrl,
            'login_url' => $loginUrl,
            'setup_url' => $setupUrl,
            'wp_username' => $wpUsername,
        ];
    }

    private function canResetPassword(Client $client, Platform $platform): bool
    {
        return (int) ($client->wp_user_id ?? 0) > 0 && $this->hasWordPressDatabaseCredentials($platform);
    }

    private function canGenerateSessionLink(Client $client, Platform $platform): bool
    {
        return (int) ($client->wp_post_id ?? 0) > 0 && $this->hasWordPressApiCredentials($platform);
    }

    private function resolveTemplate(int $platformId, string $category, string $channel): ?Template
    {
        // Platform-specific template first
        $template = Template::where('platform_id', $platformId)
            ->where('category', $category)
            ->where('channel', $channel)
            ->where('status', 'active')
            ->first();

        if ($template) {
            return $template;
        }

        // Global default (platform_id IS NULL)
        return Template::whereNull('platform_id')
            ->where('category', $category)
            ->where('channel', $channel)
            ->where('status', 'active')
            ->first();
    }

    private function interpolate(string $text, array $vars): string
    {
        $search = [];
        $replace = [];
        foreach ($vars as $key => $value) {
            $search[] = '{' . $key . '}';
            $replace[] = (string) $value;
        }

        return str_replace($search, $replace, $text);
    }

    private function buildDefaultSmsBody(
        string $method,
        string $clientName,
        ?string $wpUsername,
        ?string $temporaryPassword,
        ?string $loginUrl,
        ?string $setupUrl,
        ?string $profileUrl,
        string $supportChatUrl
    ): string {
        if ($method === 'temporary_password') {
            return trim(implode("\n", array_filter([
                "Hi {$clientName},",
                'Your CRM onboarding is complete.',
                $wpUsername ? "Username: {$wpUsername}" : null,
                $temporaryPassword ? "Temporary password: {$temporaryPassword}" : null,
                $loginUrl ? "Login: {$loginUrl}" : null,
                $supportChatUrl !== '' ? "Support chat: {$supportChatUrl}" : null,
                'Please change your password after login.',
            ])));
        }

        return trim(implode("\n", array_filter([
            "Hi {$clientName},",
            'Your profile is ready. Set your password and sign in:',
            $setupUrl ? "Set password: {$setupUrl}" : null,
            $loginUrl ? "Login: {$loginUrl}" : null,
            $profileUrl ? "Profile: {$profileUrl}" : null,
            $supportChatUrl !== '' ? "Support chat: {$supportChatUrl}" : null,
        ])));
    }

    private function buildDefaultEmailBody(
        string $method,
        string $clientName,
        ?string $wpUsername,
        ?string $temporaryPassword,
        ?string $loginUrl,
        ?string $setupUrl,
        ?string $profileUrl,
        string $supportChatUrl
    ): string {
        if ($method === 'temporary_password') {
            return trim(implode("\n", array_filter([
                "Hi {$clientName},",
                '',
                'Your profile onboarding is complete and your login credentials are ready.',
                $wpUsername ? "Username: {$wpUsername}" : null,
                $temporaryPassword ? "Temporary password: {$temporaryPassword}" : null,
                $loginUrl ? "Login URL: {$loginUrl}" : null,
                $profileUrl ? "Profile URL: {$profileUrl}" : null,
                $supportChatUrl !== '' ? "Support chat: {$supportChatUrl}" : null,
                '',
                'For security, please sign in and change this password immediately.',
            ])));
        }

        return trim(implode("\n", array_filter([
            "Hi {$clientName},",
            '',
            'Your profile onboarding is complete.',
            'Use the links below to set your password and access your profile.',
            $setupUrl ? "Password setup: {$setupUrl}" : null,
            $loginUrl ? "Login URL: {$loginUrl}" : null,
            $profileUrl ? "Profile URL: {$profileUrl}" : null,
            $supportChatUrl !== '' ? "Support chat: {$supportChatUrl}" : null,
            '',
            'If you did not request this, contact support immediately.',
        ])));
    }

    private function findRecentIdempotentDispatch(Client $client, string $idempotencyKey): ?ClientCredentialDispatch
    {
        if ($idempotencyKey === '') {
            return null;
        }

        $recent = ClientCredentialDispatch::query()
            ->where('client_id', (int) $client->id)
            ->where('platform_id', (int) $client->platform_id)
            ->where('created_at', '>=', now()->subSeconds(45))
            ->orderByDesc('id')
            ->limit(12)
            ->get();

        foreach ($recent as $dispatch) {
            if ((string) data_get($dispatch->payload, 'idempotency_key', '') === $idempotencyKey) {
                return $dispatch;
            }
        }

        return null;
    }

    private function setWordPressPassword(Platform $platform, int $wpUserId, string $temporaryPassword): void
    {
        if ($wpUserId <= 0) {
            throw new \InvalidArgumentException('WordPress user ID is required for temporary password delivery.');
        }

        $connectionName = $this->connectWordPress($platform);

        $updated = DB::connection($connectionName)
            ->table('users')
            ->where('ID', $wpUserId)
            ->update([
                'user_pass' => Hash::make($temporaryPassword),
                'user_activation_key' => '',
            ]);

        if ($updated <= 0) {
            throw new \RuntimeException('Unable to set temporary password because the WordPress user was not found.');
        }
    }

    private function resolveWpUsername(Platform $platform, int $wpUserId): ?string
    {
        if ($wpUserId <= 0) {
            return null;
        }

        try {
            $connectionName = $this->connectWordPress($platform);
            $username = DB::connection($connectionName)
                ->table('users')
                ->where('ID', $wpUserId)
                ->value('user_login');

            return $username ? (string) $username : null;
        } catch (\Throwable $exception) {
            Log::warning('Unable to resolve WordPress username for credential dispatch', [
                'platform_id' => (int) $platform->id,
                'wp_user_id' => $wpUserId,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function connectWordPress(Platform $platform): string
    {
        if (!$this->hasWordPressDatabaseCredentials($platform)) {
            throw new \InvalidArgumentException('WordPress database credentials are incomplete for this market.');
        }

        $connectionName = 'wp_credentials_' . (int) $platform->id;
        $connectionConfig = $platform->getConnectionConfig();
        $host = strtolower(trim((string) ($connectionConfig['host'] ?? '')));
        $defaultSocket = (string) config('database.connections.mysql.unix_socket', '');

        if ($defaultSocket !== '' && in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            $connectionConfig['unix_socket'] = $defaultSocket;
        }

        DynamicDatabaseService::switchConnection($connectionName, $connectionConfig);

        return $connectionName;
    }

    private function hasWordPressDatabaseCredentials(Platform $platform): bool
    {
        return filled($platform->db_host)
            && filled($platform->db_name)
            && filled($platform->db_user)
            && filled($platform->db_pass);
    }

    private function hasWordPressApiCredentials(Platform $platform): bool
    {
        return filled($platform->wp_api_url)
            && filled($platform->wp_api_user)
            && filled($platform->wp_api_password);
    }

    private function resolvePlatformBaseUrl(Platform $platform, ?string $profileUrl = null): ?string
    {
        $domain = trim((string) ($platform->domain ?? ''));
        if ($domain !== '') {
            if (!str_starts_with($domain, 'http://') && !str_starts_with($domain, 'https://')) {
                $domain = 'https://' . $domain;
            }

            return rtrim($domain, '/');
        }

        $apiUrl = trim((string) ($platform->wp_api_url ?? ''));
        if ($apiUrl !== '') {
            return rtrim((string) preg_replace('#/wp-json/.*$#', '', $apiUrl), '/');
        }

        if ($profileUrl) {
            $parts = parse_url($profileUrl);
            if (!empty($parts['scheme']) && !empty($parts['host'])) {
                return $parts['scheme'] . '://' . $parts['host'];
            }
        }

        return null;
    }

    private function resolveRequestedChannels(string $channel): array
    {
        return match ($channel) {
            'email' => ['email'],
            'sms' => ['sms'],
            default => ['email', 'sms'],
        };
    }

    private function normalizePhone(?string $phone, string $prefix = '254'): ?string
    {
        $normalized = PhoneNormalizer::normalize($phone, $prefix) ?? '';

        if ($normalized === '' || !preg_match('/^\d{10,15}$/', $normalized)) {
            return null;
        }

        return $normalized;
    }

    private function generateTemporaryPassword(int $length = 12): string
    {
        $length = max(10, $length);
        return Str::random($length);
    }

    private function buildIdempotencyKey(array $context): string
    {
        $encoded = json_encode($context);
        if (!is_string($encoded)) {
            $encoded = serialize($context);
        }

        return hash('sha256', $encoded);
    }
}
