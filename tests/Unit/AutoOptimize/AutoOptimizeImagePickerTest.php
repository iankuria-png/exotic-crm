<?php

namespace Tests\Unit\AutoOptimize;

use App\Services\AutoOptimize\AutoOptimizeImagePicker;
use App\Services\ClientProfileImageService;
use Tests\TestCase;

class AutoOptimizeImagePickerTest extends TestCase
{
    private function picker(): AutoOptimizeImagePicker
    {
        return new AutoOptimizeImagePicker(new ClientProfileImageService());
    }

    private function cfg(array $overrides = []): array
    {
        return array_merge([
            'min_width' => 800,
            'min_height' => 1000,
            'min_megapixel_gain' => 0.15,
            'require_dimensions' => true,
        ], $overrides);
    }

    public function test_returns_null_when_no_candidates_other_than_main(): void
    {
        $media = [['id' => 1, 'url' => 'https://x.com/a.jpg', 'is_main' => true, 'mime_type' => 'image/jpeg', 'width' => 1200, 'height' => 1600]];

        $result = $this->picker()->pickBetterMain(['data' => $media], 1, $this->cfg());

        $this->assertNull($result);
    }

    public function test_picks_higher_resolution_non_main(): void
    {
        $media = [
            ['id' => 1, 'url' => 'https://x.com/main.jpg', 'is_main' => true, 'mime_type' => 'image/jpeg', 'width' => 800, 'height' => 1000],
            ['id' => 2, 'url' => 'https://x.com/better.jpg', 'is_main' => false, 'mime_type' => 'image/jpeg', 'width' => 1200, 'height' => 1600],
        ];

        $result = $this->picker()->pickBetterMain(['data' => $media], 1, $this->cfg());

        $this->assertNotNull($result);
        $this->assertSame(2, $result['id']);
    }

    public function test_returns_null_when_dimensions_missing_and_required(): void
    {
        $media = [
            ['id' => 1, 'url' => 'https://x.com/main.jpg', 'is_main' => true, 'mime_type' => 'image/jpeg', 'width' => 800, 'height' => 1000],
            ['id' => 2, 'url' => 'https://x.com/nodims.jpg', 'is_main' => false, 'mime_type' => 'image/jpeg', 'width' => null, 'height' => null],
        ];

        $result = $this->picker()->pickBetterMain(['data' => $media], 1, $this->cfg(['require_dimensions' => true]));

        $this->assertNull($result);
    }

    public function test_skips_candidates_below_minimum_dimensions(): void
    {
        $media = [
            ['id' => 1, 'url' => 'https://x.com/main.jpg', 'is_main' => true, 'mime_type' => 'image/jpeg', 'width' => 100, 'height' => 100],
            ['id' => 2, 'url' => 'https://x.com/tiny.jpg', 'is_main' => false, 'mime_type' => 'image/jpeg', 'width' => 400, 'height' => 500],
        ];

        $result = $this->picker()->pickBetterMain(['data' => $media], 1, $this->cfg());

        $this->assertNull($result); // tiny is above current but below min_width/min_height
    }

    public function test_returns_null_when_gain_below_threshold(): void
    {
        // Current main = 0.8MP, candidate = 0.81MP → gain of 0.01 < 0.15 threshold
        $media = [
            ['id' => 1, 'url' => 'https://x.com/main.jpg', 'is_main' => true, 'mime_type' => 'image/jpeg', 'width' => 1000, 'height' => 800],
            ['id' => 2, 'url' => 'https://x.com/close.jpg', 'is_main' => false, 'mime_type' => 'image/jpeg', 'width' => 1010, 'height' => 802],
        ];

        $result = $this->picker()->pickBetterMain(['data' => $media], 1, $this->cfg(['min_megapixel_gain' => 0.15]));

        $this->assertNull($result);
    }

    public function test_picks_best_among_multiple_candidates(): void
    {
        $media = [
            ['id' => 1, 'url' => 'https://x.com/main.jpg', 'is_main' => true, 'mime_type' => 'image/jpeg', 'width' => 800, 'height' => 1000],
            ['id' => 2, 'url' => 'https://x.com/ok.jpg', 'is_main' => false, 'mime_type' => 'image/jpeg', 'width' => 1200, 'height' => 1500],
            ['id' => 3, 'url' => 'https://x.com/best.jpg', 'is_main' => false, 'mime_type' => 'image/jpeg', 'width' => 2000, 'height' => 2500],
        ];

        $result = $this->picker()->pickBetterMain(['data' => $media], 1, $this->cfg());

        $this->assertSame(3, $result['id']); // 5MP best
    }

    public function test_normalize_preserves_quality_fields(): void
    {
        $payload = ['data' => [
            ['id' => 1, 'url' => 'https://x.com/a.jpg', 'is_main' => false, 'mime_type' => 'image/jpeg', 'width' => 1200, 'height' => 1600, 'filesize' => 500000],
        ]];

        $items = $this->picker()->normalizeMediaItemsWithQuality($payload);

        $this->assertSame(1200, $items[0]['width']);
        $this->assertSame(1600, $items[0]['height']);
        $this->assertSame(500000, $items[0]['filesize']);
    }
}
