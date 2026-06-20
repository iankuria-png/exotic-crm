<?php

namespace Tests\Unit;

use App\Support\WpProfileFieldValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WpProfileFieldValidatorTest extends TestCase
{
    public function test_it_accepts_explicit_location_clears(): void
    {
        $validated = WpProfileFieldValidator::validate([
            'region_id' => null,
            'city_id' => null,
        ], [
            'currency_catalog_ids' => [50],
        ]);

        $this->assertArrayHasKey('region_id', $validated);
        $this->assertNull($validated['region_id']);
        $this->assertNull($validated['city_id']);
    }

    public function test_it_rejects_partial_location_payloads(): void
    {
        $this->expectException(ValidationException::class);

        WpProfileFieldValidator::validate([
            'region_id' => 10,
        ], [
            'currency_catalog_ids' => [50],
        ]);
    }

    public function test_it_rejects_unknown_currency_ids_but_preserves_current_legacy_ids(): void
    {
        $legacy = WpProfileFieldValidator::validate([
            'currency' => 999,
        ], [
            'currency_catalog_ids' => [50, 72],
            'current_currency_id' => 999,
        ]);

        $this->assertSame(999, $legacy['currency']);

        $this->expectException(ValidationException::class);

        WpProfileFieldValidator::validate([
            'currency' => 998,
        ], [
            'currency_catalog_ids' => [50, 72],
            'current_currency_id' => 999,
        ]);
    }

    public function test_it_accepts_valid_availability_codes(): void
    {
        $validated = WpProfileFieldValidator::validate([
            'availability' => ['1', '2'],
        ], [
            'currency_catalog_ids' => [50],
        ]);

        $this->assertSame(['1', '2'], $validated['availability']);
    }

    public function test_it_accepts_valid_service_codes_as_strings(): void
    {
        $validated = WpProfileFieldValidator::validate([
            'services' => ['1', '8'],
        ], [
            'currency_catalog_ids' => [50],
        ]);

        $this->assertSame(['1', '8'], $validated['services']);
    }

    public function test_it_rejects_unknown_availability_values(): void
    {
        $this->expectException(ValidationException::class);

        WpProfileFieldValidator::validate([
            'availability' => ['Incall', '2'],
        ], [
            'currency_catalog_ids' => [50],
        ]);
    }

    public function test_it_accepts_region_only_location_pairs_for_follow_up_hierarchy_validation(): void
    {
        $validated = WpProfileFieldValidator::validate([
            'region_id' => 10,
            'city_id' => null,
        ], [
            'currency_catalog_ids' => [50],
        ]);

        $this->assertSame(10, $validated['region_id']);
        $this->assertNull($validated['city_id']);
    }
}
