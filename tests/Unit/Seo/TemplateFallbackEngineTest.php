<?php

namespace Tests\Unit\Seo;

use App\Services\Seo\ProfileSnapshot;
use App\Services\Seo\TemplateFallbackEngine;
use Tests\TestCase;

/**
 * TemplateFallbackEngine uses Log facade so it needs the Laravel TestCase.
 */
class TemplateFallbackEngineTest extends TestCase
{
    private string $tempDir;
    private TemplateFallbackEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/seo_templates_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);

        file_put_contents($this->tempDir . '/female_default.json', json_encode([
            ['intro' => 'A {name}.', 'middle' => 'In {city}.', 'closer' => 'Bye.'],
            ['intro' => 'B {name}.', 'middle' => 'In {city}.', 'closer' => 'Done.'],
            ['intro' => 'C {name}.', 'middle' => 'In {city}.', 'closer' => 'End.'],
        ]));

        $this->engine = new TemplateFallbackEngine($this->tempDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') as $f) {
            unlink($f);
        }
        rmdir($this->tempDir);
        parent::tearDown();
    }

    public function test_deterministic_same_input_same_output(): void
    {
        $profile = $this->makeSnapshot(wpPostId: 999, name: 'Anna', city: 'Nairobi');
        $first  = $this->engine->generate($profile);
        $second = $this->engine->generate($profile);
        $this->assertSame($first, $second);
    }

    public function test_substitutes_variables(): void
    {
        $profile = $this->makeSnapshot(wpPostId: 1, name: 'Zara', city: 'Mombasa');
        $result = $this->engine->generate($profile);
        $this->assertStringContainsString('Zara', $result);
        $this->assertStringContainsString('Mombasa', $result);
        $this->assertStringNotContainsString('{name}', $result);
        $this->assertStringNotContainsString('{city}', $result);
    }

    public function test_picks_service_specific_file_when_available(): void
    {
        file_put_contents($this->tempDir . '/female_gfe.json', json_encode([
            ['intro' => 'GFE specialist.', 'middle' => '', 'closer' => ''],
        ]));
        $profile = $this->makeSnapshot(services: ['GFE']);
        $result = $this->engine->generate($profile);
        $this->assertStringContainsString('GFE specialist', $result);
    }

    public function test_falls_back_to_gender_default_when_no_service_file(): void
    {
        $profile = $this->makeSnapshot(services: ['UnknownService']);
        $result = $this->engine->generate($profile);
        // Should hit female_default.json
        $this->assertNotEmpty($result);
        $this->assertStringNotContainsString('{name}', $result);
    }

    public function test_uses_built_in_when_no_files_exist(): void
    {
        // Use a non-existent dir to force built-in fallback
        $engine = new TemplateFallbackEngine($this->tempDir . '/nope');
        $profile = $this->makeSnapshot();
        $result = $engine->generate($profile);
        $this->assertStringContainsString('companion based in', $result);
    }

    public function test_handles_negative_crc32_on_32bit_systems(): void
    {
        // Use a key that produces a crc32 large enough to be negative on 32-bit
        $profile = $this->makeSnapshot(wpPostId: PHP_INT_MAX);
        // Should not throw, should return a valid template
        $result = $this->engine->generate($profile);
        $this->assertNotEmpty($result);
    }

    public function test_substitutes_neighborhood_or_city_fallback(): void
    {
        file_put_contents($this->tempDir . '/female_default.json', json_encode([
            ['intro' => 'In {neighborhood_or_city}.', 'middle' => '', 'closer' => ''],
        ]));
        $engine = new TemplateFallbackEngine($this->tempDir);

        $withNeighborhood = $this->makeSnapshot(city: 'Nairobi', neighborhood: 'Westlands');
        $this->assertStringContainsString('In Westlands.', $engine->generate($withNeighborhood));

        $withoutNeighborhood = $this->makeSnapshot(city: 'Nairobi', neighborhood: null);
        $this->assertStringContainsString('In Nairobi.', $engine->generate($withoutNeighborhood));
    }

    // ------------------------------------------------------------

    private function makeSnapshot(
        ?int $wpPostId = 1,
        string $name = 'Anna',
        string $city = 'Nairobi',
        ?string $neighborhood = null,
        array $services = [],
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
            availability: null,
            existingBio: '',
            mediaSummary: [],
        );
    }
}
