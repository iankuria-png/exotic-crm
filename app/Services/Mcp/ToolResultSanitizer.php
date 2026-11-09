<?php

namespace App\Services\Mcp;

use RuntimeException;

class ToolResultSanitizer
{
    private const FORBIDDEN_KEYS = [
        'client_id', 'deal_id', 'payment_id', 'lead_id', 'agent_id', 'user_id',
        'name', 'phone', 'email', 'bio', 'notes', 'body', 'raw_payload', 'payment_data',
        'crm_url',
    ];

    public function sanitize(mixed $payload): mixed
    {
        $value = $this->walk($payload);
        $encoded = json_encode($value, JSON_THROW_ON_ERROR);

        if (preg_match('#/(?:clients?|deals?|payments?|users?)/[0-9]+#i', $encoded)) {
            throw new RuntimeException('MCP payload contains a raw-id-bearing URL.');
        }

        return $value;
    }

    /** Only an authorised report presenter may supply these exact identity locations. */
    public function auditEnvelope(array $envelope, \App\Services\Mcp\McpAuthorizationContext $auth): array
    {
        if (! $auth->allows('mcp:subscription-audit') || ! $auth->allows('mcp:identified-clients') || $auth->user->role !== 'admin' || ! $auth->user->isActive()) {
            throw new RuntimeException('Named audit context is unavailable.');
        }
        $identities = [];
        foreach (array_keys($envelope['data']['rows'] ?? []) as $index) {
            if (isset($envelope['data']['rows'][$index]['audit_identity'])) {
                $identities['rows'][$index] = $envelope['data']['rows'][$index]['audit_identity'];
                unset($envelope['data']['rows'][$index]['audit_identity']);
            }
        }
        if (isset($envelope['data']['event']['audit_identity'])) {
            $identities['event'] = $envelope['data']['event']['audit_identity'];
            unset($envelope['data']['event']['audit_identity']);
        }
        $safe = $this->sanitize($envelope);
        $restore = function (array $identity): array {
            $url = $identity['profile_url'] ?? null;
            unset($identity['profile_url']);
            $identity = $this->sanitize($identity);
            // The presenter constructs this locator from the bound platform origin.
            if ($url !== null && (! preg_match('#^https?://[^\s<>]+/\?p=[1-9][0-9]*$#iD', $url) || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null)) {
                throw new RuntimeException('Invalid public profile locator.');
            }
            $identity['profile_url'] = $url;

            return $identity;
        };
        foreach ($identities['rows'] ?? [] as $index => $identity) {
            $safe['data']['rows'][$index]['audit_identity'] = $restore($identity);
        }
        if (isset($identities['event'])) {
            $safe['data']['event']['audit_identity'] = $restore($identities['event']);
        }

        return $safe;
    }

    private function walk(mixed $value): mixed
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $child) {
                $keyString = strtolower((string) $key);
                if (in_array($keyString, self::FORBIDDEN_KEYS, true)) {
                    throw new RuntimeException('MCP payload contains a forbidden field: '.$keyString);
                }
                $result[$key] = $this->walk($child);
            }

            return $result;
        }

        if (is_string($value)) {
            $value = preg_replace('#https?://\S+#i', '[url removed]', $value) ?? $value;
            $value = preg_replace('/bearer\s+[A-Za-z0-9._|=-]+/i', '[credential removed]', $value) ?? $value;

            return mb_substr($value, 0, (int) data_get(config('mcp'), 'sanitisation.truncate_chars', 500));
        }

        return $value;
    }
}
