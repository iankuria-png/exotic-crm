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
