<?php

namespace App\Services\Forecast;

class ForecastClaimResolver
{
    public function resolve(array $candidates): array
    {
        $order = ['failed_recovery', 'renewal', 'churn_winback', 'new_activations'];
        $claimed = [];
        $sets = [];
        $excluded = [];

        foreach ($order as $lever) {
            $roots = array_values(array_unique($candidates[$lever] ?? []));
            $available = array_values(array_diff($roots, array_keys($claimed)));
            $sets[$lever] = $available;
            $excluded[$lever] = count($roots) - count($available);

            foreach ($available as $root) {
                $claimed[$root] = $lever;
            }
        }

        return [
            'sets' => $sets,
            'excluded' => $excluded,
            'claimed' => $claimed,
        ];
    }
}
