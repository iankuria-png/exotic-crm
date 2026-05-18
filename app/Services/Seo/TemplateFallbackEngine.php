<?php

namespace App\Services\Seo;

use Illuminate\Support\Facades\Log;

/**
 * Deterministic template-based bio generator.
 * Same profile always produces the same template (idempotent).
 * Does NOT call any external service.
 */
class TemplateFallbackEngine
{
    private string $templateDir;

    public function __construct(?string $templateDir = null)
    {
        $this->templateDir = $templateDir ?? app_path('Services/Seo/Templates');
    }

    public function generate(ProfileSnapshot $profile): string
    {
        $template = $this->selectTemplate($profile);
        return $this->substitute($template, $profile);
    }

    // -------------------------------------------------------------------------

    private function selectTemplate(ProfileSnapshot $profile): array
    {
        $variants = $this->loadVariants($profile);
        if (empty($variants)) {
            return $this->builtInFallback();
        }

        $key   = $profile->deterministicKey();
        $index = crc32($key) % count($variants);
        // crc32 can return negative on 32-bit; abs() ensures valid array index
        $index = abs($index) % count($variants);

        return $variants[$index];
    }

    private function loadVariants(ProfileSnapshot $profile): array
    {
        $gender  = strtolower(trim($profile->gender)) ?: 'female';
        $service = $this->normalizeService($profile->topService());

        // Try specific gender + service file first, then gender default, then any default
        $candidates = [
            "{$gender}_{$service}.json",
            "{$gender}_default.json",
            'female_default.json',
        ];

        foreach ($candidates as $filename) {
            $path = $this->templateDir . '/' . $filename;
            if (is_file($path)) {
                $json = file_get_contents($path);
                if ($json === false) {
                    continue;
                }

                $decoded = json_decode($json, true);
                if (is_array($decoded) && !empty($decoded)) {
                    return $decoded;
                }
            }
        }

        Log::warning('TemplateFallbackEngine: no template file found', [
            'gender'  => $gender,
            'service' => $service,
        ]);

        return [];
    }

    private function normalizeService(string $service): string
    {
        $service = strtolower(trim($service));
        $service = preg_replace('/[^a-z0-9]+/', '_', $service);
        $service = trim($service, '_');

        // Map common service aliases
        $map = [
            'girlfriend_experience' => 'gfe',
            'gfe'                   => 'gfe',
            'massage'               => 'massage',
            'erotic_massage'        => 'massage',
            'sensual_massage'       => 'massage',
            'bdsm'                  => 'bdsm',
        ];

        return $map[$service] ?? ($service !== '' ? $service : 'default');
    }

    private function substitute(array $template, ProfileSnapshot $profile): string
    {
        $vars = [
            '{name}'               => $profile->name ?: 'She',
            '{age}'                => $profile->age !== null ? (string) $profile->age : 'mature',
            '{ethnicity}'          => $profile->ethnicity ?: 'beautiful',
            '{city}'               => $profile->city ?: 'the city',
            '{neighborhood}'       => $profile->neighborhood ?: $profile->city ?: 'the area',
            '{neighborhood_or_city}' => $profile->neighborhoodOrCity() ?: 'the city',
            '{build}'              => $profile->build ?: 'elegant',
            '{height}'             => $profile->height ?: 'tall',
            '{hair_color}'         => $profile->hairColor ?: 'beautiful',
            '{top_service}'        => $profile->topService() ?: 'companionship',
            '{availability_text}'  => $profile->availabilityText(),
        ];

        $parts = array_filter([
            (string) ($template['intro'] ?? ''),
            (string) ($template['middle'] ?? ''),
            (string) ($template['closer'] ?? ''),
        ]);

        $text = implode(' ', $parts);

        return str_replace(array_keys($vars), array_values($vars), $text);
    }

    private function builtInFallback(): array
    {
        return [
            'intro'  => '{name} is a professional companion based in {city}.',
            'middle' => 'Specialising in {top_service} and available for {availability_text} bookings.',
            'closer' => 'Contact {name} for an unforgettable experience in {neighborhood_or_city}.',
        ];
    }
}
