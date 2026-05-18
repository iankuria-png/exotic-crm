<?php

namespace App\Services\Seo;

/**
 * Normalized profile data passed between all SEO services.
 * Pure DTO — no DB calls, no side effects.
 */
readonly class ProfileSnapshot
{
    public function __construct(
        public ?int    $clientId,
        public ?int    $wpPostId,
        public int     $platformId,
        public string  $name,
        public ?int    $age,
        public string  $city,
        public ?string $neighborhood,
        public string  $gender,
        public ?string $ethnicity,
        public ?string $build,
        public ?string $height,
        public ?string $hairColor,
        public array   $services,
        public array   $languages,
        public array   $rates,
        public ?string $availability,
        public string  $existingBio,
        public array   $mediaSummary,
        /** Signature used for deterministic template selection when wp_post_id is absent. */
        public ?string $signature = null,
    ) {}

    /** Combined identifier used for deterministic template selection. */
    public function deterministicKey(): string
    {
        if ($this->wpPostId !== null) {
            return (string) $this->wpPostId;
        }

        return $this->signature ?? md5($this->name . '|' . $this->city . '|' . $this->platformId);
    }

    public function hasMainImage(): bool
    {
        return (bool) ($this->mediaSummary['has_main_image'] ?? false);
    }

    public function imageCount(): int
    {
        return (int) ($this->mediaSummary['image_count'] ?? 0);
    }

    public function videoCount(): int
    {
        return (int) ($this->mediaSummary['video_count'] ?? 0);
    }

    public function topService(): string
    {
        return $this->services[0] ?? '';
    }

    public function availabilityText(): string
    {
        return $this->availability ?? 'flexible availability';
    }

    public function neighborhoodOrCity(): string
    {
        return $this->neighborhood ?? $this->city;
    }

    /**
     * Return an array representation for use in LLM prompts / JSON serialization.
     */
    public function toArray(): array
    {
        return [
            'client_id'    => $this->clientId,
            'wp_post_id'   => $this->wpPostId,
            'platform_id'  => $this->platformId,
            'name'         => $this->name,
            'age'          => $this->age,
            'city'         => $this->city,
            'neighborhood' => $this->neighborhood,
            'gender'       => $this->gender,
            'ethnicity'    => $this->ethnicity,
            'build'        => $this->build,
            'height'       => $this->height,
            'hair_color'   => $this->hairColor,
            'services'     => $this->services,
            'languages'    => $this->languages,
            'rates'        => $this->rates,
            'availability' => $this->availability,
            'existing_bio' => $this->existingBio,
            'media_summary' => $this->mediaSummary,
        ];
    }
}
