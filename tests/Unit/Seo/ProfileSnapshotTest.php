<?php

namespace Tests\Unit\Seo;

use App\Services\Seo\ProfileSnapshot;
use PHPUnit\Framework\TestCase;

class ProfileSnapshotTest extends TestCase
{
    public function test_deterministic_key_prefers_wp_post_id(): void
    {
        $snap = $this->makeSnapshot(wpPostId: 12345);
        $this->assertSame('12345', $snap->deterministicKey());
    }

    public function test_deterministic_key_uses_signature_when_no_post_id(): void
    {
        $snap = $this->makeSnapshot(wpPostId: null, signature: 'sig-abc');
        $this->assertSame('sig-abc', $snap->deterministicKey());
    }

    public function test_deterministic_key_falls_back_to_md5_hash(): void
    {
        $snap = $this->makeSnapshot(wpPostId: null, signature: null);
        $expected = md5('Anna|Nairobi|1');
        $this->assertSame($expected, $snap->deterministicKey());
    }

    public function test_media_counts_default_to_zero_when_empty(): void
    {
        $snap = $this->makeSnapshot(mediaSummary: []);
        $this->assertSame(0, $snap->imageCount());
        $this->assertSame(0, $snap->videoCount());
        $this->assertFalse($snap->hasMainImage());
    }

    public function test_media_counts_read_from_summary(): void
    {
        $snap = $this->makeSnapshot(mediaSummary: [
            'image_count' => 5,
            'video_count' => 2,
            'has_main_image' => true,
        ]);
        $this->assertSame(5, $snap->imageCount());
        $this->assertSame(2, $snap->videoCount());
        $this->assertTrue($snap->hasMainImage());
    }

    public function test_top_service_returns_first_or_empty(): void
    {
        $this->assertSame('GFE', $this->makeSnapshot(services: ['GFE', 'Massage'])->topService());
        $this->assertSame('', $this->makeSnapshot(services: [])->topService());
    }

    public function test_neighborhood_or_city_prefers_neighborhood(): void
    {
        $this->assertSame('Westlands', $this->makeSnapshot(city: 'Nairobi', neighborhood: 'Westlands')->neighborhoodOrCity());
        $this->assertSame('Nairobi', $this->makeSnapshot(city: 'Nairobi', neighborhood: null)->neighborhoodOrCity());
    }

    public function test_availability_text_has_default(): void
    {
        $this->assertSame('flexible availability', $this->makeSnapshot(availability: null)->availabilityText());
        $this->assertSame('incall', $this->makeSnapshot(availability: 'incall')->availabilityText());
    }

    public function test_to_array_round_trips_all_fields(): void
    {
        $snap = $this->makeSnapshot();
        $arr = $snap->toArray();
        $this->assertSame(1, $arr['platform_id']);
        $this->assertSame('Anna', $arr['name']);
        $this->assertSame('Nairobi', $arr['city']);
        $this->assertArrayHasKey('media_summary', $arr);
    }

    // ------------------------------------------------------------

    private function makeSnapshot(
        ?int $wpPostId = null,
        ?string $signature = null,
        string $name = 'Anna',
        string $city = 'Nairobi',
        ?string $neighborhood = null,
        array $services = [],
        array $mediaSummary = [],
        ?string $availability = null,
    ): ProfileSnapshot {
        return new ProfileSnapshot(
            clientId: null,
            wpPostId: $wpPostId,
            platformId: 1,
            name: $name,
            age: 25,
            city: $city,
            neighborhood: $neighborhood,
            gender: 'female',
            ethnicity: null,
            build: null,
            height: null,
            hairColor: null,
            services: $services,
            languages: [],
            rates: [],
            availability: $availability,
            existingBio: '',
            mediaSummary: $mediaSummary,
            signature: $signature,
        );
    }
}
