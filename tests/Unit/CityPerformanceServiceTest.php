<?php

namespace Tests\Unit;

use App\Services\CityPerformanceService;
use PHPUnit\Framework\TestCase;

class CityPerformanceServiceTest extends TestCase
{
    public function test_it_scores_contrasting_cities_deterministically(): void
    {
        $service = new CityPerformanceService();

        $scored = $service->score([
            ['display_city' => 'Nairobi', 'client_count' => 12, 'views' => 180, 'contact_rate' => 18.5],
            ['display_city' => 'Mombasa', 'client_count' => 7, 'views' => 90, 'contact_rate' => 9.1],
            ['display_city' => 'Kisumu', 'client_count' => 3, 'views' => 35, 'contact_rate' => 2.0],
        ]);

        $this->assertSame('strong', $scored[0]['performance']['band']);
        $this->assertSame('weak', $scored[2]['performance']['band']);
        $this->assertGreaterThan($scored[1]['performance']['index'], $scored[0]['performance']['index']);
        $this->assertLessThan($scored[1]['performance']['index'], $scored[2]['performance']['index']);
    }

    public function test_it_marks_low_sample_cities_as_insufficient(): void
    {
        $service = new CityPerformanceService();

        $scored = $service->score([
            ['display_city' => 'Tiny Town', 'client_count' => 1, 'views' => 5, 'contact_rate' => 100.0],
        ]);

        $this->assertNull($scored[0]['performance']['index']);
        $this->assertSame('insufficient', $scored[0]['performance']['band']);
    }

    public function test_it_handles_a_single_qualifying_city_without_nan_values(): void
    {
        $service = new CityPerformanceService();

        $scored = $service->score([
            ['display_city' => 'Nairobi', 'client_count' => 4, 'views' => 50, 'contact_rate' => 12.5],
        ]);

        $this->assertIsInt($scored[0]['performance']['index']);
        $this->assertContains($scored[0]['performance']['band'], ['strong', 'moderate', 'weak']);
    }

    public function test_it_keeps_all_equal_cities_in_the_same_band(): void
    {
        $service = new CityPerformanceService();

        $scored = $service->score([
            ['display_city' => 'A', 'client_count' => 5, 'views' => 60, 'contact_rate' => 10.0],
            ['display_city' => 'B', 'client_count' => 5, 'views' => 60, 'contact_rate' => 10.0],
            ['display_city' => 'C', 'client_count' => 5, 'views' => 60, 'contact_rate' => 10.0],
        ]);

        $bands = array_unique(array_map(fn ($city) => $city['performance']['band'], $scored));
        $indexes = array_unique(array_map(fn ($city) => $city['performance']['index'], $scored));

        $this->assertCount(1, $bands);
        $this->assertCount(1, $indexes);
    }
}
