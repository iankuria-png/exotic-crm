<?php

namespace App\Console\Commands;

use App\Services\WpWatermarkConfigService;
use App\Support\Watermark\WatermarkRemover;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Try the watermark removal against one real image and report what happened.
 *
 * Removal declines rather than guesses, which is the right default but makes a
 * silent "nothing changed" indistinguishable from "not configured". This says
 * which it was, and leaves the before and after side by side so the result can
 * be judged by eye rather than by a log line.
 */
class TestWatermarkRemovalCommand extends Command
{
    protected $signature = 'crm:test-watermark-removal
        {platform : Source platform id whose watermark settings to use}
        {url : URL of a watermarked image from that market}
        {--out= : Directory for the before/after files (default storage/app/watermark-tests)}';

    protected $description = 'Run watermark removal against one image and report whether it applied.';

    public function handle(WpWatermarkConfigService $config): int
    {
        $platformId = (int) $this->argument('platform');
        $url = (string) $this->argument('url');
        $directory = (string) ($this->option('out') ?: storage_path('app/watermark-tests'));

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            $this->error('Could not create ' . $directory);

            return self::FAILURE;
        }

        $stamp = $config->forPlatform($platformId);
        if ($stamp === null) {
            $this->error('No usable watermark configuration for platform ' . $platformId . '.');
            $this->line('Check watermarklogourl and watermark_position in that market\'s WordPress options,');
            $this->line('and that the CRM can reach both its database and the logo URL.');

            return self::FAILURE;
        }

        [$logoWidth, $logoHeight] = getimagesize($stamp->pngPath) ?: [0, 0];
        $this->info(sprintf(
            'Watermark: %dx%d, position %s, opacity %d%%',
            $logoWidth,
            $logoHeight,
            $stamp->position,
            $stamp->opacityPercent
        ));

        $response = Http::timeout(60)->get($url);
        if (!$response->successful()) {
            $this->error('Image download failed with HTTP ' . $response->status() . '.');

            return self::FAILURE;
        }

        $extension = pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
        $before = $directory . '/before.' . $extension;
        $after = $directory . '/after.' . $extension;

        file_put_contents($before, $response->body());
        copy($before, $after);

        $size = getimagesize($before);
        $this->info(sprintf('Image: %dx%d %s', $size[0] ?? 0, $size[1] ?? 0, $size ? image_type_to_mime_type($size[2]) : 'unknown'));

        if ($size && ($logoWidth > $size[0] || $logoHeight > $size[1])) {
            $this->line('The stamp is larger than this image, so only its middle lands. That is normal:');
            $this->line('the theme composites with imagecopy, which clips rather than scaling.');
        }

        $applied = (new WatermarkRemover($stamp))->removeFromFile($after);

        if ($applied) {
            $this->info('Removal applied. Compare:');
        } else {
            $this->warn('Removal declined and the file is unchanged.');
            $this->line('That means the pixels did not look like this logo blended at this position —');
            $this->line('a resized copy, a different market\'s mark, or an image that never carried one.');
        }

        $this->line('  before: ' . $before);
        $this->line('  after:  ' . $after);

        return self::SUCCESS;
    }
}
