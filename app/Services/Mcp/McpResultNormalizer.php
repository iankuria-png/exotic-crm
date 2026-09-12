<?php

namespace App\Services\Mcp;

/** Only explicit money-contract paths are rounded; arbitrary floats are untouched. */
class McpResultNormalizer
{
    private const MONEY_FIELDS = [
        'amount', 'normalized_amount', 'normalized_total', 'prior_normalized_total',
        'total_normalized', 'target', 'current', 'value', 'prior_value', 'revenue',
        'recovered_amount', 'average_ticket', 'prior_average_ticket',
    ];

    public function forTool(string $tool, array $payload): array
    {
        if (! in_array($tool, ['exotic_revenue_summary', 'exotic_revenue_trend', 'exotic_market_breakdown', 'exotic_agent_performance', 'exotic_peak_hours'], true)) {
            return $payload;
        }

        return $this->roundDeclaredMoney($payload);
    }

    public function money(array $payload, array $paths): array
    {
        foreach ($paths as $path) {
            $this->roundPath($payload, explode('.', $path));
        }

        return $payload;
    }

    private function roundDeclaredMoney(array $value): array
    {
        foreach ($value as $key => $child) {
            if ($key === 'source_breakdown' && is_array($child)) {
                foreach ($child as $currency => $amount) {
                    if (is_numeric($amount)) {
                        $value[$key][$currency] = round((float) $amount, 2);
                    }
                }
            } elseif (in_array((string) $key, self::MONEY_FIELDS, true) && is_numeric($child)) {
                $value[$key] = round((float) $child, 2);
            } elseif (is_array($child)) {
                $value[$key] = $this->roundDeclaredMoney($child);
            }
        }

        return $value;
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
