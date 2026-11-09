<?php

namespace App\Services\SubscriptionAudit;

use App\Models\Client;
use App\Services\Mcp\McpAuthorizationContext;
use App\Services\Mcp\ToolResultSanitizer;
use Illuminate\Support\Facades\DB;

class SubscriptionAuditIdentityPresenter
{
    public function enrich(array $payload, object $mapping, McpAuthorizationContext $auth): array
    {
        if (! $auth->allows('mcp:identified-clients') || $auth->user->role !== 'admin' || ! $auth->user->isActive()
            || ($auth->platformIds !== null && ! in_array((int) $mapping->platform_id, $auth->platformIds, true))) {
            throw new SubscriptionAuditException('Enable named audit access on this administrator connection in Settings or reconnect with named audit consent.', 'identified_audit_denied', 403, -32011);
        }
        $client = Client::query()->with('platform')->whereKey($mapping->client_id)->where('platform_id', $mapping->platform_id)->first();
        $frozen = $mapping;
        if (! property_exists($mapping, 'wp_post_id')) {
            $frozen = \Illuminate\Support\Facades\Schema::hasTable('subscription_audit_profiles') ? DB::table('subscription_audit_profiles')->where('run_id', $mapping->run_id)->where('client_id', $mapping->client_id)->where('platform_id', $mapping->platform_id)->first(['wp_post_id', 'wp_user_id']) : null;
        }
        $binding = $client && $frozen && (int) $frozen->wp_post_id === (int) $client->wp_post_id && (int) $frozen->wp_user_id === (int) $client->wp_user_id;
        $identity = ['identity_as_of' => now('UTC')->toIso8601String(), 'state' => $binding ? 'current_identity' : 'identity_unavailable',
            'client_display_name' => $binding ? $this->display($client->name) : null,
            'profile_url' => $binding ? $this->profileUrl($client) : null, 'profile_url_kind' => 'wordpress_public_locator'];
        // Staff handles are resolved only from this already scoped event/profile evidence.
        $handles = [];
        $scan = function ($value) use (&$scan, &$handles) {
            if (is_array($value)) {
                foreach ($value as $v) {
                    $scan($v);
                }
            } elseif (is_string($value) && preg_match('/^staff_[a-f0-9]{32}$/D', $value)) {
                $handles[$value] = true;
            }
        };
        $scan($payload);
        $staff = [];
        if ($handles) {
            foreach (DB::table('users')->select(['id', 'name'])->orderBy('id')->lazyById(500) as $user) {
                $handle = AuditValue::handle('staff', $user->id);
                if (isset($handles[$handle])) {
                    $staff[$handle] = $this->display($user->name);
                }
            }
        }
        $identity['staff'] = array_map(fn ($handle) => ['staff_handle' => $handle, 'staff_display_name' => $staff[$handle] ?? null,
            'state' => isset($staff[$handle]) ? 'current_identity' : 'former_or_unavailable'], array_keys($handles));
        $payload['audit_identity'] = $identity;

        return $payload;
    }

    public function profileUrl(Client $client): ?string
    {
        $base = preg_replace('#/wp-json/.*$#', '', (string) $client->platform?->wp_api_url);
        $parts = parse_url($base);
        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])
            || preg_match('#/(?:clients?|deals?|payments?|users?)/[0-9]+#i', (string) ($parts['path'] ?? ''))
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || (int) $client->wp_post_id < 1) {
            return null;
        }
        $url = $client->wp_profile_url;

        return $url === rtrim($base, '/').'/?p='.(int) $client->wp_post_id ? $url : null;
    }

    private function display(?string $name): ?string
    {
        return $name === null ? null : app(ToolResultSanitizer::class)->sanitize(mb_substr(strip_tags($name), 0, 160));
    }
}
