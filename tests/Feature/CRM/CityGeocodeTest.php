<?php

namespace Tests\Feature\CRM;

use App\Models\CityGeocode;
use App\Models\Platform;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CityGeocodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_unique_per_platform_canonical_key(): void
    {
        $platform = Platform::factory()->create();
        $otherPlatform = Platform::factory()->create();

        CityGeocode::query()->create([
            'platform_id' => $platform->id,
            'canonical_key' => 'nairobi',
            'display_city' => 'Nairobi',
        ]);

        CityGeocode::query()->create([
            'platform_id' => $otherPlatform->id,
            'canonical_key' => 'nairobi',
            'display_city' => 'Nairobi',
        ]);

        $this->expectException(QueryException::class);

        CityGeocode::query()->create([
            'platform_id' => $platform->id,
            'canonical_key' => 'nairobi',
            'display_city' => 'Nairobi',
        ]);
    }
}
