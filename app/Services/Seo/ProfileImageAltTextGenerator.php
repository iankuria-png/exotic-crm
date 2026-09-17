<?php

namespace App\Services\Seo;

use App\Models\Client;

/**
 * Builds SEO alt text for profile images uploaded through the CRM.
 *
 * Deterministic and dependency-free: every fact comes from the clients row
 * that the upload request already has in memory, so generating alt text never
 * adds a WordPress read to the upload path.
 *
 * The output deliberately avoids "photo of" / "image of" prefixes — screen
 * readers already announce the element as an image, and search engines treat
 * the repetition as padding.
 */
class ProfileImageAltTextGenerator
{
    /**
     * Screen readers commonly cut off around 125 characters, and concise alt
     * outranks a keyword run, so this is a hard ceiling rather than a target.
     */
    public const MAX_LENGTH = 125;

    private const TYPE_LABELS = [
        'escort' => 'escort',
        'agency' => 'agency',
        'masseuse' => 'masseuse',
        'masseur' => 'masseur',
    ];

    /**
     * @param  int  $position  1-based position of this image on the profile.
     *                         Anything past the first is suffixed so no two
     *                         images on a page share alt text.
     */
    public function generate(Client $client, int $position = 1): string
    {
        $name = $this->cleanName((string) $client->name);
        $city = $this->cleanPlace((string) $client->city);
        $country = $this->cleanPlace((string) ($client->platform->country ?? ''));
        $label = $this->typeLabel((string) $client->client_type);

        $alt = $this->assemble($name, $label, $city, $country);

        if ($alt === '') {
            // Nothing identifying survived. "Profile photo (photo 3)" would
            // read twice, so number the bare fallback directly.
            return $position > 1 ? 'Profile photo '.$position : 'Profile photo';
        }

        return $this->withPosition($alt, $position, $name, $label, $city, $country);
    }

    /**
     * Compose the descriptive part, shedding the least valuable clause first
     * when the result would run past MAX_LENGTH.
     */
    private function assemble(string $name, string $label, string $city, string $country): string
    {
        $place = $this->placeClause($city, $country);

        // Full form: "Naima Yemeni, escort in Kilimani, Kenya"
        $candidates = [];

        if ($name !== '' && $label !== '' && $place !== '') {
            $candidates[] = $name.', '.$label.' '.$place;
        }

        if ($name !== '' && $place !== '') {
            $candidates[] = $name.' '.$place;
        }

        if ($name !== '' && $label !== '') {
            $candidates[] = $name.', '.$label;
        }

        // No usable name: lead with the role so the alt still says something.
        if ($name === '' && $label !== '' && $place !== '') {
            $candidates[] = ucfirst($label).' '.$place;
        }

        if ($name !== '') {
            $candidates[] = $name;
        }

        if ($name === '' && $place !== '') {
            // Drop the leading "in " so the alt does not open with a preposition.
            $candidates[] = ucfirst(mb_substr($place, 3));
        }

        foreach ($candidates as $candidate) {
            // Leave headroom for a " (photo 12)" suffix.
            if (mb_strlen($candidate) <= self::MAX_LENGTH - 12) {
                return $candidate;
            }
        }

        return $candidates === [] ? '' : mb_substr($candidates[0], 0, self::MAX_LENGTH - 12);
    }

    private function placeClause(string $city, string $country): string
    {
        if ($city !== '' && $country !== '' && ! $this->sameWord($city, $country)) {
            return 'in '.$city.', '.$country;
        }

        if ($city !== '') {
            return 'in '.$city;
        }

        if ($country !== '') {
            return 'in '.$country;
        }

        return '';
    }

    /**
     * Suffix everything after the first image so a gallery never repeats the
     * same alt twenty times, which reads as duplicate content.
     */
    private function withPosition(string $alt, int $position, string $name, string $label, string $city, string $country): string
    {
        if ($position <= 1) {
            return $this->clamp($alt);
        }

        $suffix = ' (photo '.$position.')';

        if (mb_strlen($alt.$suffix) <= self::MAX_LENGTH) {
            return $alt.$suffix;
        }

        // Shed the country, then the role, rather than truncating mid-word.
        $shorter = $this->assemble($name, $label, $city, '');
        if ($shorter !== '' && mb_strlen($shorter.$suffix) <= self::MAX_LENGTH) {
            return $shorter.$suffix;
        }

        $shortest = $this->assemble($name, '', $city, '');
        if ($shortest !== '' && mb_strlen($shortest.$suffix) <= self::MAX_LENGTH) {
            return $shortest.$suffix;
        }

        return $this->clamp(mb_substr($alt, 0, self::MAX_LENGTH - mb_strlen($suffix)).$suffix);
    }

    /**
     * Profile names arrive from WordPress full of display junk — underscores,
     * emoji, promo digits. Strip to something a human would read aloud.
     */
    private function cleanName(string $value): string
    {
        $value = str_replace(['_', '.', '|', '/', '\\'], ' ', $value);
        $value = preg_replace('/[^\p{L}\p{N}\s\'-]+/u', ' ', $value) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        if ($value === '') {
            return '';
        }

        $value = $this->normalizeCase($value);

        // A very long display name would crowd out the location, which is the
        // part that actually earns image impressions.
        if (mb_strlen($value) > 60) {
            $value = trim(mb_substr($value, 0, 60));
        }

        return $value;
    }

    private function cleanPlace(string $value): string
    {
        $value = preg_replace('/[^\p{L}\p{N}\s\'-]+/u', ' ', $value) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        if ($value === '') {
            return '';
        }

        return $this->normalizeCase(mb_substr($value, 0, 40));
    }

    /**
     * Title-case only when the source gives no casing signal of its own, so
     * names like "McKenzie" or "d'Angelo" survive untouched.
     */
    private function normalizeCase(string $value): string
    {
        $hasLower = (bool) preg_match('/\p{Ll}/u', $value);
        $hasUpper = (bool) preg_match('/\p{Lu}/u', $value);

        if ($hasLower && $hasUpper) {
            return $value;
        }

        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    private function typeLabel(string $clientType): string
    {
        return self::TYPE_LABELS[strtolower(trim($clientType))] ?? '';
    }

    private function sameWord(string $a, string $b): bool
    {
        return mb_strtolower($a) === mb_strtolower($b);
    }

    private function clamp(string $value): string
    {
        return mb_strlen($value) > self::MAX_LENGTH
            ? trim(mb_substr($value, 0, self::MAX_LENGTH))
            : $value;
    }
}
