<?php

namespace App\Services\Mcp;

/**
 * Keeps source calculations intact while enforcing the MCP serialization policy.
 * Transaction/event FX rows are implementation metadata, not connector data.
 */
class McpResponseProjector
{
    public function project(array $payload): array
    {
        return $this->walk($payload);
    }

    private function walk(array $value): array
    {
        $projected = [];
        foreach ($value as $key => $child) {
            if ($key === 'normalization_meta' && is_array($child)) {
                $child = $this->fxMeta($child);
            }
            $projected[$key] = is_array($child) ? $this->walk($child) : $child;
        }

        return $projected;
    }

    private function fxMeta(array $meta): array
    {
        unset($meta['rows'], $meta['unresolved_rows'], $meta['currency_aliases']);
        $meta['detail_policy'] = 'aggregate_only';

        return $meta;
    }
}
