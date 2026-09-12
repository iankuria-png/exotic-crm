<?php

namespace App\Services\Mcp;

/** Only explicit money-contract paths are rounded; arbitrary floats are untouched. */
class McpResultNormalizer
{
    public function money(array $payload, array $paths): array
    {
        foreach ($paths as $path) {
            $this->roundPath($payload, explode('.', $path));
        }

        return $payload;
    }

    private function roundPath(array &$value, array $segments): void
    {
        $segment = array_shift($segments);
        if ($segment === null) {
            return;
        }
        if ($segment === '*') {
            foreach ($value as &$child) {
                if (is_array($child)) {
                    $this->roundPath($child, $segments);
                }
            }

            return;
        }
        if (! array_key_exists($segment, $value)) {
            return;
        }
        if ($segments === []) {
            if (is_numeric($value[$segment])) {
                $value[$segment] = round((float) $value[$segment], 2);
            }

            return;
        }
        if (is_array($value[$segment])) {
            $this->roundPath($value[$segment], $segments);
        }
    }
}
