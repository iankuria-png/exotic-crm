<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Platform;
use App\Support\WordPressSiteConnection;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Http;

/**
 * Walks the whole "log in as client" pipeline one stage at a time and reports
 * exactly which stage breaks.
 *
 * The live flow has five moving parts (CRM record -> platform credentials ->
 * WordPress REST mint -> admin-post.php consumer -> browser landing on the
 * target page) and a failure in any one of them surfaces to staff as the same
 * useless symptom: a tab that opens logged out. This service reproduces the
 * flow server-side with a cookie jar so the failing hop is named.
 *
 * The probe mints and consumes its OWN one-time token, so running it never
 * steals or invalidates a session link a staff member is about to use.
 */
class ClientSessionDiagnosticsService
{
    private const REQUEST_TIMEOUT = 8;

    private const SENSITIVE_QUERY_KEYS = ['token', 'crm_client_session', '_wpnonce', 'password', 'pass', 'key'];

    /** Cookie names that prove WordPress issued an authenticated session. */
    private const LOGGED_IN_COOKIE_PREFIXES = ['wordpress_logged_in_', 'wordpress_sec_', 'wordpress_'];

    public function run(Client $client, array $payload = []): array
    {
        $client->loadMissing('platform');
        $platform = $client->platform;

        $target = trim((string) ($payload['target'] ?? 'profile'));
        if (! in_array($target, ['edit_profile', 'change_password', 'profile', 'home'], true)) {
            $target = 'profile';
        }

        $context = [
            'client' => $client,
            'platform' => $platform,
            'target' => $target,
            'issued_by' => trim((string) ($payload['issued_by'] ?? 'crm-debug')),
            'reason' => trim((string) ($payload['reason'] ?? 'Client session debug from CRM')),
            'jar' => new CookieJar(),
            'wp_result' => [],
            'session_url' => '',
        ];

        $stages = [];
        $stages[] = $this->stageClientRecord($context);
        $stages[] = $this->stagePlatformCredentials($context);
        $stages[] = $this->stageRestReachable($context, $stages);
        $stages[] = $this->stageRestAuthenticated($context, $stages);
        $stages[] = $this->stageMintSessionLink($context, $stages);
        $stages[] = $this->stageConsumerReachable($context, $stages);
        $stages[] = $this->stageConsumeToken($context, $stages);
        $stages[] = $this->stageAuthCookie($context, $stages);
        $stages[] = $this->stageHostAlignment($context, $stages);
        $stages[] = $this->stageLandingSession($context, $stages);

        return [
            'generated_at' => now()->toIso8601String(),
            'target' => $target,
            'client' => [
                'id' => (int) $client->id,
                'name' => (string) ($client->name ?? ''),
                'platform_id' => (int) $client->platform_id,
                'platform_name' => (string) ($platform->name ?? ''),
                'wp_post_id' => (int) ($client->wp_post_id ?? 0),
                'wp_user_id' => (int) ($client->wp_user_id ?? 0),
            ],
            'overall' => $this->buildVerdict($stages),
            'stages' => $stages,
            // Legacy flat shape kept so existing consumers/tests keep reading
            // the same paths while the staged report is the primary surface.
            'request' => ['target' => $target],
            'wordpress' => $this->legacyWordPressBlock($context),
            'probe' => $this->legacyProbeBlock($stages),
        ];
    }

    // ------------------------------------------------------------------
    // Stages
    // ------------------------------------------------------------------

    private function stageClientRecord(array &$context): array
    {
        /** @var Client $client */
        $client = $context['client'];
        $postId = (int) ($client->wp_post_id ?? 0);
        $userId = (int) ($client->wp_user_id ?? 0);

        $facts = [
            $this->fact('CRM client', '#'.(int) $client->id),
            $this->fact('WP post ID', $postId > 0 ? (string) $postId : 'missing'),
            $this->fact('WP user ID', $userId > 0 ? (string) $userId : 'missing'),
            $this->fact('Market', (string) ($context['platform']->name ?? 'unlinked')),
        ];

        if (! $context['platform']) {
            return $this->stage('client_record', 'CRM client record', 'fail', 'The client is not linked to a market.', $facts,
                'Assign this client to a platform in the CRM before a WordPress session can be minted.');
        }

        if ($postId <= 0) {
            return $this->stage('client_record', 'CRM client record', 'fail', 'The client has no WordPress post ID.', $facts,
                'Run a WP sync for this client. The session link is minted against the profile post, so without wp_post_id there is nothing to log into.');
        }

        if ($userId <= 0) {
            return $this->stage('client_record', 'CRM client record', 'warn', 'No WordPress user ID cached in the CRM.', $facts,
                'WordPress derives the user from the post author, so the mint can still work — but password resets will not. Re-sync the client to backfill wp_user_id.');
        }

        return $this->stage('client_record', 'CRM client record', 'pass', 'Client is linked to a WordPress profile post and user.', $facts);
    }

    private function stagePlatformCredentials(array &$context): array
    {
        /** @var Platform|null $platform */
        $platform = $context['platform'];

        if (! $platform) {
            return $this->stage('platform_credentials', 'Market API credentials', 'skipped', 'No market to check.', []);
        }

        $apiUrl = trim((string) ($platform->wp_api_url ?? ''));
        $user = trim((string) ($platform->wp_api_user ?? ''));
        $password = trim((string) ($platform->wp_api_password ?? ''));

        $facts = [
            $this->fact('REST base', $apiUrl !== '' ? $apiUrl : 'not configured'),
            $this->fact('API user', $user !== '' ? $user : 'not configured'),
            $this->fact('Application password', $password !== '' ? 'stored ('.strlen($password).' chars)' : 'not configured'),
            $this->fact('Shared sync key', $platform->sync_shared_key_enabled ? 'enabled' : 'disabled'),
        ];

        $missing = [];
        if ($apiUrl === '') {
            $missing[] = 'REST base URL';
        }
        if ($user === '') {
            $missing[] = 'API user';
        }
        if ($password === '') {
            $missing[] = 'application password';
        }

        if ($missing) {
            return $this->stage('platform_credentials', 'Market API credentials', 'fail',
                'Missing '.implode(', ', $missing).'.', $facts,
                'Fill in the WordPress API fields for this market under Settings → Markets. Without them the CRM cannot ask WordPress for a session link.');
        }

        return $this->stage('platform_credentials', 'Market API credentials', 'pass', 'REST base URL and application password are configured.', $facts);
    }

    private function stageRestReachable(array &$context, array $stages): array
    {
        if ($this->blocked($stages)) {
            return $this->stage('rest_reachable', 'WordPress REST endpoint reachable', 'skipped', 'Skipped — an earlier stage failed.', []);
        }

        $url = rtrim((string) $context['platform']->wp_api_url, '/');
        $started = microtime(true);

        try {
            $response = Http::withOptions(['allow_redirects' => false])
                ->timeout(self::REQUEST_TIMEOUT)
                ->get($url);
        } catch (\Throwable $exception) {
            return $this->stage('rest_reachable', 'WordPress REST endpoint reachable', 'fail',
                'Could not connect to the market REST endpoint.',
                [
                    $this->fact('URL', $url),
                    $this->fact('Error', $this->scrub($exception->getMessage())),
                ],
                'The CRM cannot reach the site at all. Check DNS, the market REST base URL, and whether the origin is up.',
                $this->elapsed($started)
            );
        }

        $challenge = $this->detectEdgeChallenge($response->status(), $response->headers(), (string) $response->body());
        $facts = [
            $this->fact('URL', $url),
            $this->fact('HTTP status', (string) $response->status()),
            $this->fact('Server', $this->header($response->headers(), 'Server') ?: 'unknown'),
            $this->fact('Content type', $this->header($response->headers(), 'Content-Type') ?: 'unknown'),
        ];

        if ($challenge) {
            $facts[] = $this->fact('Edge challenge', $challenge);

            return $this->stage('rest_reachable', 'WordPress REST endpoint reachable', 'fail',
                'A CDN/WAF challenge is sitting in front of the REST endpoint.', $facts,
                'Cloudflare (or an equivalent edge rule) is challenging server-to-server requests. Allowlist the CRM origin IP for this hostname, or disable the managed challenge on /wp-json/.',
                $this->elapsed($started)
            );
        }

        if ($response->status() >= 500) {
            return $this->stage('rest_reachable', 'WordPress REST endpoint reachable', 'fail',
                'The market REST endpoint returned a server error.', $facts,
                'WordPress or its host is erroring before the plugin runs. Check the market site error log.',
                $this->elapsed($started)
            );
        }

        if ($response->status() === 404) {
            return $this->stage('rest_reachable', 'WordPress REST endpoint reachable', 'fail',
                'The exotic-crm-sync namespace was not found (404).', $facts,
                'The sync plugin is missing, deactivated, or on an older version on this market. Re-upload and activate exotic-crm-sync.',
                $this->elapsed($started)
            );
        }

        return $this->stage('rest_reachable', 'WordPress REST endpoint reachable', 'pass',
            'The sync plugin namespace answered.', $facts, null, $this->elapsed($started));
    }

    private function stageRestAuthenticated(array &$context, array $stages): array
    {
        if ($this->blocked($stages)) {
            return $this->stage('rest_authenticated', 'CRM authenticates to WordPress', 'skipped', 'Skipped — an earlier stage failed.', []);
        }

        /** @var Client $client */
        $client = $context['client'];
        $connection = WordPressSiteConnection::fromPlatform($context['platform']);
        $url = rtrim((string) $connection->wpApiUrl, '/').'/clients/'.(int) $client->wp_post_id;
        $started = microtime(true);

        try {
            $response = Http::withHeaders($this->authHeaders($connection, $context['platform']))
                ->withOptions(['allow_redirects' => false])
                ->timeout(self::REQUEST_TIMEOUT)
                ->get($url);
        } catch (\Throwable $exception) {
            return $this->stage('rest_authenticated', 'CRM authenticates to WordPress', 'fail',
                'The authenticated profile lookup could not connect.',
                [$this->fact('URL', $url), $this->fact('Error', $this->scrub($exception->getMessage()))],
                'Transient network failure or a blocked origin. Retry, then check the market host firewall.',
                $this->elapsed($started)
            );
        }

        $facts = [
            $this->fact('URL', $url),
            $this->fact('HTTP status', (string) $response->status()),
        ];

        if (in_array($response->status(), [401, 403], true)) {
            $challenge = $this->detectEdgeChallenge($response->status(), $response->headers(), (string) $response->body());
            if ($challenge) {
                $facts[] = $this->fact('Edge challenge', $challenge);

                return $this->stage('rest_authenticated', 'CRM authenticates to WordPress', 'fail',
                    'A CDN/WAF challenge blocked the authenticated call.', $facts,
                    'Allowlist the CRM origin IP at the edge for this market — the request never reached WordPress.',
                    $this->elapsed($started)
                );
            }

            return $this->stage('rest_authenticated', 'CRM authenticates to WordPress', 'fail',
                'WordPress rejected the CRM credentials ('.$response->status().').', $facts,
                'The application password for this market is wrong, revoked, or the API user lost its role. Regenerate the WordPress application password and update the market settings.',
                $this->elapsed($started)
            );
        }

        if ($response->status() === 404) {
            return $this->stage('rest_authenticated', 'CRM authenticates to WordPress', 'fail',
                'WordPress does not have a profile post with this ID.', $facts,
                'The cached wp_post_id points at a deleted or re-created post. Re-sync this client from WordPress.',
                $this->elapsed($started)
            );
        }

        if (! $response->successful()) {
            return $this->stage('rest_authenticated', 'CRM authenticates to WordPress', 'fail',
                'The authenticated profile lookup failed.', $facts,
                'Check the market site error log for the exotic-crm-sync request.',
                $this->elapsed($started)
            );
        }

        $body = $response->json();
        if (is_array($body)) {
            $author = $body['post_author'] ?? $body['wp_user_id'] ?? null;
            if ($author !== null) {
                $facts[] = $this->fact('Profile post author', (string) $author);
            }
            if (isset($body['post_status'])) {
                $facts[] = $this->fact('Post status', (string) $body['post_status']);
            }
        }

        return $this->stage('rest_authenticated', 'CRM authenticates to WordPress', 'pass',
            'The CRM application password is accepted and the profile post exists.', $facts, null, $this->elapsed($started));
    }

    private function stageMintSessionLink(array &$context, array $stages): array
    {
        if ($this->blocked($stages)) {
            return $this->stage('session_link_mint', 'WordPress mints a session link', 'skipped', 'Skipped — an earlier stage failed.', []);
        }

        /** @var Client $client */
        $client = $context['client'];
        $postId = (int) $client->wp_post_id;
        $endpoint = rtrim((string) $context['platform']->wp_api_url, '/')."/clients/{$postId}/session-link";
        $started = microtime(true);

        try {
            $result = (new WpSyncService($context['platform']))->createClientSessionLink($postId, [
                'target' => $context['target'],
                'issued_by' => $context['issued_by'],
                'reason' => $context['reason'],
            ]);
        } catch (\Throwable $exception) {
            return $this->stage('session_link_mint', 'WordPress mints a session link', 'fail',
                'WordPress refused to mint a session link.',
                [
                    $this->fact('Endpoint', $endpoint),
                    $this->fact('Error', $this->scrub($exception->getMessage())),
                ],
                'Most often the profile post has no valid author user on WordPress (client_user_missing), or the plugin on this market predates the session-link endpoint. Check the WP user behind the profile, then confirm the market runs the current exotic-crm-sync build.',
                $this->elapsed($started)
            );
        }

        $context['wp_result'] = is_array($result) ? $result : [];
        $url = trim((string) ($context['wp_result']['url'] ?? ''));
        $context['session_url'] = $url;

        $facts = [
            $this->fact('Endpoint', $endpoint),
            $this->fact('Link shape', $this->classifyUrl($url) ?? 'none'),
            $this->fact('Link', $this->sanitizeUrl($url) ?? 'none'),
            $this->fact('Expires at', (string) ($context['wp_result']['expires_at'] ?? 'unknown')),
            $this->fact('Requested target', $context['target']),
            $this->fact('Resolved target', (string) ($context['wp_result']['resolved_target'] ?? $context['wp_result']['target'] ?? 'unknown')),
            $this->fact('Target URL', $this->sanitizeUrl($context['wp_result']['target_url'] ?? null) ?? 'none'),
        ];

        if ($url === '') {
            return $this->stage('session_link_mint', 'WordPress mints a session link', 'fail',
                'WordPress answered but returned no session URL.', $facts,
                'The plugin responded without a "url" field — usually an older exotic-crm-sync build on this market. Re-upload the plugin.',
                $this->elapsed($started)
            );
        }

        if (! empty($context['wp_result']['target_fallback_used'])) {
            return $this->stage('session_link_mint', 'WordPress mints a session link', 'warn',
                'The link was minted, but WordPress could not resolve the requested target page.', $facts,
                'The "'.$context['target'].'" page is not configured on this market (escort_edit_personal_info_page_id / change_password_page_id options are empty), so WordPress fell back to another destination. Set those page options on the market site.',
                $this->elapsed($started)
            );
        }

        return $this->stage('session_link_mint', 'WordPress mints a session link', 'pass',
            'A one-time session link was minted for the requested target.', $facts, null, $this->elapsed($started));
    }

    private function stageConsumerReachable(array &$context, array $stages): array
    {
        if ($this->blocked($stages) || $context['session_url'] === '') {
            return $this->stage('consumer_reachable', 'Session consumer endpoint reachable', 'skipped', 'Skipped — no session link to test.', []);
        }

        $origin = $this->origin($context['session_url']);
        $path = (string) (parse_url($context['session_url'], PHP_URL_PATH) ?: '/');
        if ($origin === null) {
            return $this->stage('consumer_reachable', 'Session consumer endpoint reachable', 'fail',
                'WordPress returned a malformed session URL.', [$this->fact('Link', $this->sanitizeUrl($context['session_url']) ?? '')],
                'The plugin built an invalid URL. Confirm the market\'s WordPress Address (siteurl) option is a full absolute URL.');
        }

        $probeUrl = $origin.$path;
        $started = microtime(true);

        try {
            $response = Http::withOptions(['allow_redirects' => false])
                ->timeout(self::REQUEST_TIMEOUT)
                ->get($probeUrl);
        } catch (\Throwable $exception) {
            return $this->stage('consumer_reachable', 'Session consumer endpoint reachable', 'fail',
                'The session consumer endpoint could not be reached.',
                [$this->fact('URL', $probeUrl), $this->fact('Error', $this->scrub($exception->getMessage()))],
                'The token-consuming endpoint is unreachable, so no session link can ever complete.',
                $this->elapsed($started)
            );
        }

        $challenge = $this->detectEdgeChallenge($response->status(), $response->headers(), (string) $response->body());
        $facts = [
            $this->fact('URL (no token)', $probeUrl),
            $this->fact('HTTP status', (string) $response->status()),
            $this->fact('Server', $this->header($response->headers(), 'Server') ?: 'unknown'),
        ];

        if ($challenge) {
            $facts[] = $this->fact('Edge challenge', $challenge);

            return $this->stage('consumer_reachable', 'Session consumer endpoint reachable', 'fail',
                'A CDN/WAF challenge protects the session consumer endpoint.', $facts,
                'The edge is challenging '.$path.'. Staff browsers get the challenge instead of the login redirect, which is exactly the "opens logged out" symptom. Exclude this path from the managed challenge.',
                $this->elapsed($started)
            );
        }

        if (in_array($response->status(), [401, 403], true)) {
            return $this->stage('consumer_reachable', 'Session consumer endpoint reachable', 'fail',
                'The session consumer endpoint is blocked ('.$response->status().').', $facts,
                'Something in front of WordPress (a security plugin, a host rule, or an .htaccess deny on /wp-admin/) is blocking '.$path.' for logged-out visitors. The session link must be reachable while logged out — that is the entire point of it.',
                $this->elapsed($started)
            );
        }

        if ($response->status() >= 500) {
            return $this->stage('consumer_reachable', 'Session consumer endpoint reachable', 'fail',
                'The session consumer endpoint returned a server error.', $facts,
                'Check the market site PHP error log for a fatal on '.$path.'.',
                $this->elapsed($started)
            );
        }

        return $this->stage('consumer_reachable', 'Session consumer endpoint reachable', 'pass',
            'The endpoint that consumes the token answers for logged-out visitors.', $facts, null, $this->elapsed($started));
    }

    private function stageConsumeToken(array &$context, array $stages): array
    {
        if ($this->blocked($stages) || $context['session_url'] === '') {
            return $this->stage('token_consumed', 'One-time token is accepted', 'skipped', 'Skipped — no session link to test.', []);
        }

        $started = microtime(true);

        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'cookies' => $context['jar'],
            ])->timeout(self::REQUEST_TIMEOUT)->get($context['session_url']);
        } catch (\Throwable $exception) {
            return $this->stage('token_consumed', 'One-time token is accepted', 'fail',
                'The session link request failed.',
                [$this->fact('Error', $this->scrub($exception->getMessage(), $context['session_url']))],
                'Retry the diagnostic. If it keeps failing the market host is dropping the request mid-flight.',
                $this->elapsed($started)
            );
        }

        $status = $response->status();
        $location = $response->header('Location');
        $body = (string) $response->body();
        $context['consumer_status'] = $status;
        $context['consumer_location'] = $location;

        $facts = [
            $this->fact('Link shape', $this->classifyUrl($context['session_url']) ?? 'unknown'),
            $this->fact('HTTP status', (string) $status),
            $this->fact('Redirects to', $this->sanitizeUrl($location) ?? 'no redirect'),
            $this->fact('Redirect shape', $this->classifyUrl($location) ?? 'n/a'),
            $this->fact('Set-Cookie names', implode(', ', $this->cookieNames($context['jar'])) ?: 'none'),
        ];

        $challenge = $this->detectEdgeChallenge($status, $response->headers(), $body);
        if ($challenge) {
            $facts[] = $this->fact('Edge challenge', $challenge);

            return $this->stage('token_consumed', 'One-time token is accepted', 'fail',
                'The token request was intercepted by a CDN/WAF challenge.', $facts,
                'The token is never seen by WordPress. Exclude the session consumer path from the edge challenge.',
                $this->elapsed($started)
            );
        }

        if ($status === 403) {
            $expired = stripos($body, 'invalid or has expired') !== false
                || stripos($body, 'Client session unavailable') !== false;

            return $this->stage('token_consumed', 'One-time token is accepted', 'fail',
                $expired
                    ? 'WordPress rejected the token as invalid or expired.'
                    : 'The token request was refused with 403.',
                $facts,
                $expired
                    ? 'The token transient did not survive between minting and use. This is the classic symptom of an object cache that is not shared across PHP workers, or a persistent cache plugin dropping transients. Check the market for a Redis/Memcached object cache and confirm transients persist.'
                    : 'A security layer refused the request before the plugin ran.',
                $this->elapsed($started)
            );
        }

        if ($status >= 500) {
            return $this->stage('token_consumed', 'One-time token is accepted', 'fail',
                'WordPress fatalled while consuming the token.', $facts,
                'Check the market site PHP error log around this timestamp.',
                $this->elapsed($started)
            );
        }

        if ($status < 300 || $status >= 400 || ! $location) {
            return $this->stage('token_consumed', 'One-time token is accepted', 'fail',
                'WordPress did not redirect after consuming the token.', $facts,
                'A successful session link always answers 302 with a Location header. A 200 here means another plugin or theme handled the request first — check for output before the redirect on the market site.',
                $this->elapsed($started)
            );
        }

        return $this->stage('token_consumed', 'One-time token is accepted', 'pass',
            'WordPress accepted the one-time token and issued a redirect.', $facts, null, $this->elapsed($started));
    }

    private function stageAuthCookie(array &$context, array $stages): array
    {
        if ($this->blocked($stages)) {
            return $this->stage('auth_cookie', 'Login cookie issued', 'skipped', 'Skipped — the token was never consumed.', []);
        }

        $cookies = $this->cookieSummary($context['jar']);
        $loggedIn = array_values(array_filter(
            $cookies,
            fn (array $cookie) => str_starts_with($cookie['name'], 'wordpress_logged_in_')
        ));

        $facts = [];
        foreach ($cookies as $cookie) {
            $facts[] = $this->fact(
                $cookie['name'],
                'domain '.$cookie['domain'].' · path '.$cookie['path'].' · '.($cookie['secure'] ? 'secure' : 'not secure')
            );
        }
        if (! $facts) {
            $facts[] = $this->fact('Cookies received', 'none');
        }

        if (! $loggedIn) {
            return $this->stage('auth_cookie', 'Login cookie issued', 'fail',
                'WordPress redirected without setting a login cookie.', $facts,
                'wp_set_auth_cookie() ran but no wordpress_logged_in_* cookie came back. Either headers were already sent (output before the redirect), or a plugin is filtering the auth cookie away. This is why the tab lands logged out.',
            );
        }

        $context['cookie_domain'] = $loggedIn[0]['domain'];
        $facts[] = $this->fact('Login cookie', $loggedIn[0]['name']);

        return $this->stage('auth_cookie', 'Login cookie issued', 'pass',
            'WordPress issued a wordpress_logged_in_* session cookie.', $facts);
    }

    private function stageHostAlignment(array &$context, array $stages): array
    {
        if ($this->blocked($stages)) {
            return $this->stage('host_alignment', 'Cookie and destination hosts match', 'skipped', 'Skipped — no login cookie to align.', []);
        }

        $cookieDomain = ltrim((string) ($context['cookie_domain'] ?? ''), '.');
        $consumerHost = (string) (parse_url((string) $context['session_url'], PHP_URL_HOST) ?: '');
        $redirectHost = (string) (parse_url((string) ($context['consumer_location'] ?? ''), PHP_URL_HOST) ?: '');
        $consumerScheme = (string) (parse_url((string) $context['session_url'], PHP_URL_SCHEME) ?: '');
        $redirectScheme = (string) (parse_url((string) ($context['consumer_location'] ?? ''), PHP_URL_SCHEME) ?: '');

        $facts = [
            $this->fact('Cookie domain', $cookieDomain ?: 'unknown'),
            $this->fact('Session link host', $consumerHost ?: 'unknown'),
            $this->fact('Redirect host', $redirectHost ?: 'unknown'),
            $this->fact('Schemes', ($consumerScheme ?: '?').' → '.($redirectScheme ?: '?')),
        ];

        $hostMismatch = $redirectHost !== '' && $consumerHost !== '' && $redirectHost !== $consumerHost;
        $cookieMismatch = $cookieDomain !== '' && $redirectHost !== ''
            && $redirectHost !== $cookieDomain
            && ! str_ends_with($redirectHost, '.'.$cookieDomain);

        if ($hostMismatch || $cookieMismatch) {
            return $this->stage('host_alignment', 'Cookie and destination hosts match', 'fail',
                'The login cookie is scoped to a different host than the page the client lands on.', $facts,
                'This is a www / apex (or http / https) split. The cookie is set on '.($cookieDomain ?: $consumerHost).' but the browser is sent to '.$redirectHost.', so it arrives with no session. Make the market\'s WordPress Address and Site Address options use one canonical host, and make sure the redirect target is built from that same host.',
            );
        }

        if ($consumerScheme !== '' && $redirectScheme !== '' && $consumerScheme !== $redirectScheme) {
            return $this->stage('host_alignment', 'Cookie and destination hosts match', 'warn',
                'The session link and the destination use different schemes.', $facts,
                'A https → http hop drops secure cookies. Force https on the market site.',
            );
        }

        return $this->stage('host_alignment', 'Cookie and destination hosts match', 'pass',
            'The login cookie applies to the destination host.', $facts);
    }

    private function stageLandingSession(array &$context, array $stages): array
    {
        $location = (string) ($context['consumer_location'] ?? '');
        if ($this->blocked($stages) || $location === '') {
            return $this->stage('landing_session', 'Client lands logged in', 'skipped', 'Skipped — there is no destination to open.', []);
        }

        $started = microtime(true);

        try {
            $response = Http::withOptions([
                'allow_redirects' => ['max' => 5, 'track_redirects' => true],
                'cookies' => $context['jar'],
            ])->timeout(self::REQUEST_TIMEOUT)->get($location);
        } catch (\Throwable $exception) {
            return $this->stage('landing_session', 'Client lands logged in', 'fail',
                'The destination page could not be loaded.',
                [$this->fact('URL', $this->sanitizeUrl($location) ?? ''), $this->fact('Error', $this->scrub($exception->getMessage(), $location))],
                'The session was created but the landing page is unreachable.',
                $this->elapsed($started)
            );
        }

        $body = (string) $response->body();
        $evidence = $this->loggedInEvidence($body);
        $hasLoginForm = $this->looksLikeLoginForm($body);

        $facts = [
            $this->fact('Destination', $this->sanitizeUrl($location) ?? ''),
            $this->fact('HTTP status', (string) $response->status()),
            $this->fact('Logged-in markers', $evidence ? implode(', ', $evidence) : 'none found'),
            $this->fact('Login form on page', $hasLoginForm ? 'yes' : 'no'),
            $this->fact('Response size', number_format(strlen($body)).' bytes'),
        ];

        if (! $response->successful()) {
            return $this->stage('landing_session', 'Client lands logged in', 'fail',
                'The destination page returned HTTP '.$response->status().'.', $facts,
                'The session may be valid but the target page is broken or missing on this market.',
                $this->elapsed($started)
            );
        }

        if (! $evidence) {
            return $this->stage('landing_session', 'Client lands logged in', 'fail',
                'The destination page rendered as a logged-out visitor.', $facts,
                $hasLoginForm
                    ? 'The client is bounced to a login form. The cookie was issued but WordPress did not recognise it on the next request — check for a session/security plugin that pins sessions to a user agent or IP, and confirm the cookie domain matches the front-end host.'
                    : 'No logged-in markers (admin bar, logged-in body class, logout link) are present. Either the theme hides them for this role, or the session is genuinely not being recognised. Compare against a manual login as this user.',
                $this->elapsed($started)
            );
        }

        return $this->stage('landing_session', 'Client lands logged in', 'pass',
            'The destination page rendered with an authenticated session.', $facts, null, $this->elapsed($started));
    }

    // ------------------------------------------------------------------
    // Verdict
    // ------------------------------------------------------------------

    private function buildVerdict(array $stages): array
    {
        foreach ($stages as $stage) {
            if ($stage['status'] === 'fail') {
                return [
                    'status' => 'fail',
                    'headline' => 'Login as client is broken at: '.$stage['label'],
                    'failing_stage' => $stage['key'],
                    'root_cause' => $stage['summary'],
                    'recommended_fix' => $stage['hint'],
                ];
            }
        }

        $warnings = array_values(array_filter($stages, fn (array $stage) => $stage['status'] === 'warn'));
        if ($warnings) {
            return [
                'status' => 'warn',
                'headline' => 'Login as client works, with '.count($warnings).' issue'.(count($warnings) === 1 ? '' : 's').' to fix',
                'failing_stage' => $warnings[0]['key'],
                'root_cause' => $warnings[0]['summary'],
                'recommended_fix' => $warnings[0]['hint'],
            ];
        }

        return [
            'status' => 'pass',
            'headline' => 'Login as client completed end to end',
            'failing_stage' => null,
            'root_cause' => null,
            'recommended_fix' => null,
        ];
    }

    // ------------------------------------------------------------------
    // Legacy report blocks
    // ------------------------------------------------------------------

    private function legacyWordPressBlock(array $context): array
    {
        $platform = $context['platform'];
        $postId = (int) ($context['client']->wp_post_id ?? 0);
        $base = rtrim((string) ($platform->wp_api_url ?? ''), '/');
        $url = (string) $context['session_url'];
        $wp = $context['wp_result'];

        return [
            'api_url' => $base,
            'session_link_endpoint' => $base."/clients/{$postId}/session-link",
            'response' => [
                'has_url' => $url !== '',
                'url' => $this->sanitizeUrl($url),
                'url_shape' => $this->classifyUrl($url),
                'query_keys' => $this->queryKeys($url),
                'expires_at' => $wp['expires_at'] ?? null,
                'target' => $wp['target'] ?? null,
                'resolved_target' => $wp['resolved_target'] ?? null,
                'target_url' => $this->sanitizeUrl($wp['target_url'] ?? null),
                'profile_url' => $this->sanitizeUrl($wp['profile_url'] ?? null),
                'edit_profile_url' => $this->sanitizeUrl($wp['edit_profile_url'] ?? null),
                'change_password_url' => $this->sanitizeUrl($wp['change_password_url'] ?? null),
                'target_fallback_used' => $wp['target_fallback_used'] ?? null,
            ],
        ];
    }

    private function legacyProbeBlock(array $stages): array
    {
        $stage = null;
        foreach ($stages as $candidate) {
            if ($candidate['key'] === 'token_consumed') {
                $stage = $candidate;
                break;
            }
        }

        $facts = collect($stage['facts'] ?? [])->pluck('value', 'label');

        return [
            'attempted' => ($stage['status'] ?? 'skipped') !== 'skipped',
            'consumes_generated_session' => true,
            'method' => 'GET',
            'allow_redirects' => false,
            'status' => is_numeric($facts->get('HTTP status')) ? (int) $facts->get('HTTP status') : null,
            'redirect_location' => $facts->get('Redirects to') === 'no redirect' ? null : $facts->get('Redirects to'),
            'redirect_location_shape' => $facts->get('Redirect shape') === 'n/a' ? null : $facts->get('Redirect shape'),
            'has_set_cookie' => ($facts->get('Set-Cookie names') ?? 'none') !== 'none',
            'error' => ($stage['status'] ?? null) === 'fail' ? ($stage['summary'] ?? null) : null,
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function stage(
        string $key,
        string $label,
        string $status,
        string $summary,
        array $facts,
        ?string $hint = null,
        ?int $durationMs = null
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'summary' => $summary,
            'hint' => $hint,
            'facts' => array_values($facts),
            'duration_ms' => $durationMs,
        ];
    }

    private function fact(string $label, string $value): array
    {
        return ['label' => $label, 'value' => $value];
    }

    private function blocked(array $stages): bool
    {
        foreach ($stages as $stage) {
            if ($stage['status'] === 'fail') {
                return true;
            }
        }

        return false;
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    private function authHeaders(WordPressSiteConnection $connection, Platform $platform): array
    {
        $headers = [
            'Authorization' => 'Basic '.base64_encode(
                (string) $connection->wpApiUser.':'.(string) $connection->wpApiPassword
            ),
            'Accept' => 'application/json',
        ];

        $sharedKey = trim((string) config('services.exotic_crm_sync.shared_key', ''));
        if ($sharedKey !== '' && $platform->sync_shared_key_enabled) {
            $headers['X-Exotic-CRM-Sync-Key'] = $sharedKey;
        }

        return $headers;
    }

    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $values) {
            if (strcasecmp($key, $name) === 0) {
                return is_array($values) ? (string) reset($values) : (string) $values;
            }
        }

        return null;
    }

    /**
     * Name the edge product sitting in front of WordPress when a request never
     * reached PHP. Server-to-server calls cannot solve a browser challenge, so
     * this is always terminal for the pipeline.
     */
    private function detectEdgeChallenge(int $status, array $headers, string $body): ?string
    {
        if ($this->header($headers, 'cf-mitigated')) {
            return 'Cloudflare managed challenge (cf-mitigated header)';
        }

        if (! in_array($status, [401, 403, 429, 503], true)) {
            return null;
        }

        $needles = [
            'just a moment' => 'Cloudflare interstitial ("Just a moment...")',
            'cf-browser-verification' => 'Cloudflare browser verification',
            '__cf_chl' => 'Cloudflare challenge script',
            'attention required! | cloudflare' => 'Cloudflare block page',
            'checking your browser' => 'Edge browser check interstitial',
            'sucuri website firewall' => 'Sucuri WAF block page',
            'access denied' => 'Edge access-denied page',
        ];

        $haystack = strtolower(substr($body, 0, 4000));
        foreach ($needles as $needle => $label) {
            if (str_contains($haystack, $needle)) {
                return $label;
            }
        }

        $server = strtolower((string) $this->header($headers, 'Server'));
        if ($server !== '' && str_contains($server, 'cloudflare') && $status === 403) {
            return 'Cloudflare 403 (no WordPress response body)';
        }

        return null;
    }

    private function loggedInEvidence(string $body): array
    {
        $found = [];
        $haystack = strtolower($body);

        $markers = [
            'id="wpadminbar"' => 'admin bar',
            'class="logged-in' => 'logged-in body class',
            ' logged-in ' => 'logged-in body class',
            'wp-admin/profile.php' => 'profile link',
            'action=logout' => 'logout link',
            'wp-login.php?action=logout' => 'logout link',
            'my-account' => 'account menu',
        ];

        foreach ($markers as $needle => $label) {
            if (str_contains($haystack, $needle) && ! in_array($label, $found, true)) {
                $found[] = $label;
            }
        }

        return $found;
    }

    private function looksLikeLoginForm(string $body): bool
    {
        $haystack = strtolower($body);

        return str_contains($haystack, 'id="user_login"')
            || str_contains($haystack, 'name="log"')
            || str_contains($haystack, 'wp-login.php');
    }

    private function cookieNames(CookieJar $jar): array
    {
        return array_values(array_unique(array_map(
            fn (array $cookie) => (string) ($cookie['Name'] ?? ''),
            $jar->toArray()
        )));
    }

    /**
     * Cookie metadata only — never values. The report is copied into tickets.
     */
    private function cookieSummary(CookieJar $jar): array
    {
        $summary = [];
        foreach ($jar->toArray() as $cookie) {
            $summary[] = [
                'name' => (string) ($cookie['Name'] ?? ''),
                'domain' => (string) ($cookie['Domain'] ?? ''),
                'path' => (string) ($cookie['Path'] ?? '/'),
                'secure' => (bool) ($cookie['Secure'] ?? false),
            ];
        }

        return $summary;
    }

    private function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    private function classifyUrl(?string $url): ?string
    {
        $url = trim((string) ($url ?? ''));
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return 'malformed';
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        $path = strtolower((string) ($parts['path'] ?? ''));

        if (($query['action'] ?? null) === 'exotic_crm_client_session' && array_key_exists('token', $query)) {
            return 'admin_post_consumer';
        }

        if (array_key_exists('crm_client_session', $query)) {
            return in_array($path, ['', '/'], true)
                ? 'legacy_home_query_consumer'
                : 'legacy_path_query_consumer';
        }

        if (in_array($path, ['', '/'], true)) {
            return 'homepage';
        }

        return 'ordinary_url';
    }

    private function queryKeys(?string $url): array
    {
        $query = (string) (parse_url((string) ($url ?? ''), PHP_URL_QUERY) ?? '');
        if ($query === '') {
            return [];
        }

        parse_str($query, $values);

        return array_values(array_unique(array_map('strval', array_keys($values))));
    }

    private function sanitizeUrl($url): ?string
    {
        $url = trim((string) ($url ?? ''));
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return '[malformed-url]';
        }

        $query = [];
        if (isset($parts['query'])) {
            parse_str((string) $parts['query'], $query);
            foreach (self::SENSITIVE_QUERY_KEYS as $sensitiveKey) {
                if (array_key_exists($sensitiveKey, $query)) {
                    $query[$sensitiveKey] = '[redacted]';
                }
            }
        }

        $rebuilt = '';
        if (isset($parts['scheme'])) {
            $rebuilt .= $parts['scheme'].'://';
        }
        if (isset($parts['user'])) {
            $rebuilt .= '[redacted]@';
        }
        if (isset($parts['host'])) {
            $rebuilt .= $parts['host'];
        }
        if (isset($parts['port'])) {
            $rebuilt .= ':'.$parts['port'];
        }
        $rebuilt .= $parts['path'] ?? '';

        if (! empty($query)) {
            $rebuilt .= '?'.http_build_query($query);
        }

        if (isset($parts['fragment'])) {
            $rebuilt .= '#'.$parts['fragment'];
        }

        return $rebuilt !== '' ? $rebuilt : $url;
    }

    private function scrub(string $message, ?string $url = null): string
    {
        if ($url) {
            $message = str_replace($url, (string) $this->sanitizeUrl($url), $message);
        }

        return (string) preg_replace(
            '/((?:token|crm_client_session|_wpnonce|password|pass|key)=)[^\s&"\']+/i',
            '$1[redacted]',
            $message
        );
    }
}
