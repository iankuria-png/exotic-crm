<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\McpOAuthAuthorizationCode;
use App\Models\McpOAuthClient;
use App\Models\McpOAuthRefreshToken;
use App\Models\User;
use App\Services\Mcp\McpSettingsService;
use App\Services\Mcp\ToolRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class McpOAuthController extends Controller
{
    public function __construct(private readonly McpSettingsService $settings, private readonly ToolRegistry $tools) {}

    public function protectedResource(Request $request)
    {
        abort_unless((bool) config('mcp.oauth.enabled'), 404);

        return response()->json([
            'resource' => url('/api/mcp'),
            'authorization_servers' => [url('/')],
            'scopes_supported' => config('mcp.oauth.resource_scopes'),
            'bearer_methods_supported' => ['header'],
        ]);
    }

    public function metadata()
    {
        abort_unless((bool) config('mcp.oauth.enabled'), 404);

        return response()->json([
            'issuer' => url('/'),
            'authorization_endpoint' => url('/mcp/oauth/authorize'),
            'token_endpoint' => url('/api/mcp/oauth/token'),
            'registration_endpoint' => url('/api/mcp/oauth/register'),
            'revocation_endpoint' => url('/api/mcp/oauth/revoke'),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => config('mcp.oauth.resource_scopes'),
        ]);
    }

    public function registerClient(Request $request)
    {
        abort_unless((bool) config('mcp.oauth.enabled'), 404);
        $data = $request->validate(['client_name' => ['required', 'string', 'max:120'], 'redirect_uris' => ['required', 'array', 'min:1', 'max:10'], 'redirect_uris.*' => ['required', 'string', 'max:2048']]);
        foreach ($data['redirect_uris'] as $uri) {
            abort_unless($this->safeRedirect($uri), 422, 'OAuth redirect URIs must use HTTPS (or an exact localhost development URI).');
        }
        $client = McpOAuthClient::query()->create(['client_id' => (string) Str::uuid(), 'client_name' => $data['client_name'], 'redirect_uris' => array_values(array_unique($data['redirect_uris'])), 'active' => true]);

        return response()->json(['client_id' => $client->client_id, 'client_name' => $client->client_name, 'redirect_uris' => $client->redirect_uris, 'token_endpoint_auth_method' => 'none'], 201);
    }

    public function consent(Request $request)
    {
        abort_unless((bool) config('mcp.oauth.enabled'), 404);
        $data = $request->validate(['response_type' => ['required', 'in:code'], 'client_id' => ['required', 'string'], 'redirect_uri' => ['required', 'string'], 'code_challenge' => ['required', 'string', 'min:43', 'max:128'], 'code_challenge_method' => ['required', 'in:S256'], 'state' => ['nullable', 'string', 'max:1024'], 'scope' => ['nullable', 'string'], 'approved' => ['nullable', 'boolean']]);
        $client = McpOAuthClient::query()->where('client_id', $data['client_id'])->where('active', true)->firstOrFail();
        abort_unless(in_array($data['redirect_uri'], $client->redirect_uris, true), 422, 'The redirect URI is not registered for this connector.');
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isActive(), 403, 'Your CRM account is inactive.');
        $requestedScopes = array_filter(explode(' ', (string) ($data['scope'] ?? 'mcp:read')));
        abort_unless($requestedScopes === ['mcp:read'], 422, 'Only the read-only MCP scope is available.');
        if (! ($data['approved'] ?? false)) {
            return response($this->consentPage($request, $client), 200)->header('Content-Type', 'text/html; charset=UTF-8');
        }
        $code = Str::random(64);
        McpOAuthAuthorizationCode::query()->create(['code_hash' => hash('sha256', $code), 'client_id' => $client->id, 'user_id' => $user->id, 'redirect_uri' => $data['redirect_uri'], 'code_challenge' => $data['code_challenge'], 'code_challenge_method' => 'S256', 'abilities' => $this->abilitiesFor($user), 'expires_at' => now()->addMinutes((int) config('mcp.oauth.authorization_code_minutes'))]);
        $query = array_filter(['code' => $code, 'state' => $data['state'] ?? null], fn ($value) => $value !== null && $value !== '');

        return redirect()->away($data['redirect_uri'].(str_contains($data['redirect_uri'], '?') ? '&' : '?').http_build_query($query));
    }

    public function token(Request $request)
    {
        abort_unless((bool) config('mcp.oauth.enabled'), 404);
        $data = $request->validate(['grant_type' => ['required', 'in:authorization_code,refresh_token'], 'client_id' => ['required', 'string'], 'code' => ['required_if:grant_type,authorization_code', 'string'], 'redirect_uri' => ['required_if:grant_type,authorization_code', 'string'], 'code_verifier' => ['required_if:grant_type,authorization_code', 'string', 'min:43', 'max:128'], 'refresh_token' => ['required_if:grant_type,refresh_token', 'string']]);
        $client = McpOAuthClient::query()->where('client_id', $data['client_id'])->where('active', true)->firstOrFail();
        if ($data['grant_type'] === 'refresh_token') {
            return response()->json(DB::transaction(function () use ($data, $client) {
                $refresh = McpOAuthRefreshToken::query()->where('token_hash', hash('sha256', $data['refresh_token']))->where('client_id', $client->id)->whereNull('revoked_at')->where('expires_at', '>', now())->lockForUpdate()->firstOrFail();
                $refresh->update(['revoked_at' => now()]);
                if ($refresh->personal_access_token_id) {
                    DB::table('personal_access_tokens')->where('id', $refresh->personal_access_token_id)->delete();
                }

                return $this->issue(User::query()->findOrFail($refresh->user_id), $client, $refresh->abilities);
            }));
        }

        return response()->json(DB::transaction(function () use ($data, $client) {
            $code = McpOAuthAuthorizationCode::query()->where('code_hash', hash('sha256', $data['code']))->where('client_id', $client->id)->where('redirect_uri', $data['redirect_uri'])->whereNull('consumed_at')->where('expires_at', '>', now())->lockForUpdate()->firstOrFail();
            $expected = rtrim(strtr(base64_encode(hash('sha256', $data['code_verifier'], true)), '+/', '-_'), '=');
            abort_unless(hash_equals($code->code_challenge, $expected), 422, 'PKCE verification failed.');
            $code->update(['consumed_at' => now()]);

            return $this->issue(User::query()->findOrFail($code->user_id), $client, $code->abilities);
        }));
    }

    public function revoke(Request $request)
    {
        $data = $request->validate(['token' => ['required', 'string']]);
        $refresh = McpOAuthRefreshToken::query()->where('token_hash', hash('sha256', $data['token']))->first();
        if ($refresh) {
            $refresh->update(['revoked_at' => now()]);
            DB::table('personal_access_tokens')->where('id', $refresh->personal_access_token_id)->delete();
        }

        return response('', 200);
    }

    private function issue(User $user, McpOAuthClient $client, array $abilities): array
    {
        $access = $user->createToken('mcp:oauth:'.$client->client_id, $abilities, now()->addMinutes((int) config('mcp.oauth.access_token_minutes')));
        $refreshToken = Str::random(80);
        McpOAuthRefreshToken::query()->create(['token_hash' => hash('sha256', $refreshToken), 'client_id' => $client->id, 'user_id' => $user->id, 'personal_access_token_id' => $access->accessToken->id, 'abilities' => $abilities, 'expires_at' => now()->addDays((int) config('mcp.oauth.refresh_token_days'))]);

        return ['access_token' => $access->plainTextToken, 'token_type' => 'Bearer', 'expires_in' => (int) config('mcp.oauth.access_token_minutes') * 60, 'refresh_token' => $refreshToken, 'scope' => 'mcp:read'];
    }

    private function abilitiesFor(User $user): array
    {
        return array_merge(['mcp:read'], array_map(fn ($tool) => 'mcp:tool:'.$tool, $this->tools->defaultToolNames($user, $this->settings)));
    }

    private function safeRedirect(string $uri): bool
    {
        $parts = parse_url($uri);

        return is_array($parts) && isset($parts['scheme'], $parts['host']) && ($parts['scheme'] === 'https' || ($parts['scheme'] === 'http' && in_array($parts['host'], ['localhost', '127.0.0.1', '::1'], true)));
    }

    private function consentPage(Request $request, McpOAuthClient $client): string
    {
        $fields = '';
        foreach ($request->query() as $key => $value) {
            if (in_array($key, ['approved', 'password'], true) || is_array($value)) {
                continue;
            }
            $fields .= '<input type="hidden" name="'.e($key).'" value="'.e((string) $value).'">';
        }

        return '<!doctype html><html><body style="font-family:system-ui;max-width:42rem;margin:12vh auto;color:#0f172a"><p style="color:#0f766e;font-weight:700;letter-spacing:.08em">EXOTIC CRM · READ-ONLY MCP</p><h1>Connect '.e($client->client_name).'</h1><p>This connector can read only the MCP capabilities available to your CRM role and market scope. It cannot edit clients, settle payments, send messages, or access Support Board content.</p><form method="get">'.$fields.'<input type="hidden" name="approved" value="1"><button style="background:#0f766e;color:white;border:0;border-radius:.5rem;padding:.75rem 1rem;font-weight:700">Approve read-only connection</button></form><p style="color:#64748b;font-size:.875rem">You can revoke this connection from Settings → MCP at any time.</p></body></html>';
    }
}
