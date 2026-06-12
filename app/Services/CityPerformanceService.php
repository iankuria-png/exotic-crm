<?php

namespace App\Services;

class CityPerformanceService
{
    public function score(array $cities, int $minClients = 3, int $minViews = 30): array
    {
        $qualifying = [];

        foreach ($cities as $index => $city) {
            $clientCount = (int) ($city['client_count'] ?? 0);
            $views = (int) ($city['views'] ?? 0);
            $contactRate = (float) ($city['contact_rate'] ?? 0);

            if ($clientCount < $minClients && $views < $minViews) {
                $cities[$index]['performance'] = [
                    'index' => null,
                    'band' => 'insufficient',
                ];
                continue;
            }

            $qualifying[$index] = [
                'client_count' => $clientCount,
                'views' => $views,
                'contact_rate' => $contactRate,
            ];
        }

        if ($qualifying === []) {
            return $cities;
        }

        $clientValues = array_column($qualifying, 'client_count');
        $viewValues = array_column($qualifying, 'views');
        $contactRateValues = array_column($qualifying, 'contact_rate');

        foreach ($qualifying as $index => $city) {
            $normalizedClients = $this->normalize($city['client_count'], $clientValues);
            $normalizedViews = $this->normalize($city['views'], $viewValues);
            $normalizedContactRate = $this->normalize($city['contact_rate'], $contactRateValues);

            $score = (int) round(100 * (
                (0.30 * $normalizedClients)
                + (0.40 * $normalizedViews)
                + (0.30 * $normalizedContactRate)
            ));

            $cities[$index]['performance'] = [
                'index' => $score,
                'band' => $this->band($score),
            ];
        }

        return $cities;
    }

    private function normalize(float|int $value, array $values): float
    {
        $min = min($values);
        $max = max($values);

        if ($max === $min) {
            return $value > 0 ? 1.0 : 0.0;
        }

        return ($value - $min) / ($max - $min);
    }

    private function band(int $score): string
    {
        if ($score >= 66) {
            return 'strong';
        }

        if ($score >= 33) {
            return 'moderate';
        }

        return 'weak';
    }
}
