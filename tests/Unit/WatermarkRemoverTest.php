<?php

namespace Tests\Unit;

use App\Support\Watermark\WatermarkRemover;
use App\Support\Watermark\WatermarkStamp;
use PHPUnit\Framework\TestCase;

/**
 * The fixtures composite a watermark exactly the way the WordPress theme does,
 * then ask the remover to undo it. That is the only honest test of an inversion:
 * run the real forward operation and measure what comes back.
 */
class WatermarkRemoverTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is not available.');
        }

        $this->dir = sys_get_temp_dir() . '/wm-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    /** A photo-like gradient, so a wrong recovery shows up as banding. */
    private function makePhoto(int $width = 240, int $height = 200): \GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                // Smooth, like a photograph. High-frequency noise would be
                // destroyed by the JPEG round-trip and swamp the measurement.
                imagesetpixel($image, $x, $y, imagecolorallocate(
                    $image,
                    (int) (40 + 180 * ($x / max(1, $width))),
                    (int) (30 + 200 * ($y / max(1, $height))),
                    (int) (60 + 150 * (($x + $y) / max(1, $width + $height)))
                ));
            }
        }

        return $image;
    }

    /** A logo drawn in partial alpha, as the Exotic mark is. */
    private function makeLogo(string $path, int $alpha = 40): void
    {
        $logo = imagecreatetruecolor(60, 30);
        imagesavealpha($logo, true);
        imagealphablending($logo, false);
        imagefill($logo, 0, 0, imagecolorallocatealpha($logo, 255, 255, 255, 127));

        for ($y = 6; $y < 24; $y++) {
            for ($x = 6; $x < 54; $x++) {
                imagesetpixel($logo, $x, $y, imagecolorallocatealpha($logo, 255, 255, 255, $alpha));
            }
        }

        imagepng($logo, $path);
        imagedestroy($logo);
    }

    /** Reproduces the theme's opacity pass and imagecopy composite. */
    private function stampOnto(\GdImage $canvas, string $logoPath, int $x, int $y, int $opacityPercent = 90): void
    {
        $logo = imagecreatefrompng($logoPath);
        $opacity = $opacityPercent / 100;

        $minAlpha = 127;
        for ($lx = 0; $lx < imagesx($logo); $lx++) {
            for ($ly = 0; $ly < imagesy($logo); $ly++) {
                $alpha = (imagecolorat($logo, $lx, $ly) >> 24) & 0xFF;
                $minAlpha = min($minAlpha, $alpha);
            }
        }

        imagealphablending($logo, false);
        for ($lx = 0; $lx < imagesx($logo); $lx++) {
            for ($ly = 0; $ly < imagesy($logo); $ly++) {
                $colour = imagecolorat($logo, $lx, $ly);
                $alpha = ($colour >> 24) & 0xFF;
                $alpha = $minAlpha !== 127
                    ? 127 + 127 * $opacity * ($alpha - 127) / (127 - $minAlpha)
                    : $alpha + 127 * $opacity;

                imagesetpixel($logo, $lx, $ly, imagecolorallocatealpha(
                    $logo,
                    ($colour >> 16) & 0xFF,
                    ($colour >> 8) & 0xFF,
                    $colour & 0xFF,
                    (int) max(0, min(127, $alpha))
                ));
            }
        }

        imagecopy($canvas, $logo, $x, $y, 0, 0, imagesx($logo), imagesy($logo));
        imagedestroy($logo);
    }

    /**
     * @return array{0: float, 1: int}  Mean absolute error and worst channel error
     *                                  across the region the stamp covered.
     */
    private function compare(string $originalPath, string $cleanedPath, int $x, int $y, int $w, int $h): array
    {
        $a = imagecreatefromjpeg($originalPath);
        $b = imagecreatefromjpeg($cleanedPath);

        $total = 0;
        $worst = 0;
        $count = 0;

        for ($dy = 0; $dy < $h; $dy++) {
            for ($dx = 0; $dx < $w; $dx++) {
                $ca = imagecolorat($a, $x + $dx, $y + $dy);
                $cb = imagecolorat($b, $x + $dx, $y + $dy);

                foreach ([16, 8, 0] as $shift) {
                    $diff = abs((($ca >> $shift) & 0xFF) - (($cb >> $shift) & 0xFF));
                    $total += $diff;
                    $worst = max($worst, $diff);
                    $count++;
                }
            }
        }

        imagedestroy($a);
        imagedestroy($b);

        return [$total / max(1, $count), $worst];
    }

    public function test_it_recovers_the_pixels_a_partial_alpha_watermark_covered(): void
    {
        $logoPath = $this->dir . '/logo.png';
        $originalPath = $this->dir . '/original.jpg';
        $stampedPath = $this->dir . '/stamped.jpg';
        $this->makeLogo($logoPath);

        $photo = $this->makePhoto();
        imagejpeg($photo, $originalPath, 100);

        // Bottom right, matching the theme's ten-pixel inset.
        $x = 240 - 60 - 10;
        $y = 200 - 30 - 10;
        $this->stampOnto($photo, $logoPath, $x, $y);
        imagejpeg($photo, $stampedPath, 90);
        imagedestroy($photo);

        // Control: the same photo through the same JPEG round-trip without a
        // watermark. Any recovery error below this is lost in compression.
        $controlPath = $this->dir . '/control.jpg';
        $control = $this->makePhoto();
        imagejpeg($control, $controlPath, 90);
        imagedestroy($control);
        [$jpegFloor] = $this->compare($originalPath, $controlPath, $x, $y, 60, 30);

        [$meanBefore] = $this->compare($originalPath, $stampedPath, $x, $y, 60, 30);
        $this->assertGreaterThan(20, $meanBefore, 'The fixture should be visibly watermarked to begin with.');

        $removed = (new WatermarkRemover(new WatermarkStamp($logoPath, 'br', 90)))->removeFromFile($stampedPath);
        $this->assertTrue($removed);

        [$meanAfter, $worstAfter] = $this->compare($originalPath, $stampedPath, $x, $y, 60, 30);

        // Dividing by (1 - a) amplifies whatever JPEG quantisation the
        // watermarked file already carried, by roughly 1/(1 - a). A few
        // multiples of the noise floor is therefore the expected result, and
        // still far below anything visible at 255 levels.
        $this->assertLessThan(
            6,
            $meanAfter,
            sprintf('Recovery error %.2f, watermarked %.2f, JPEG floor %.2f.', $meanAfter, $meanBefore, $jpegFloor)
        );
        $this->assertLessThan($meanBefore / 4, $meanAfter, 'Removal should be a large improvement.');
        $this->assertLessThan(60, $worstAfter, 'No channel should be wildly wrong.');
    }

    /**
     * An image that never carried this watermark must come back untouched — the
     * guard against stamping a rectangle of mangled pixels onto a thumbnail or
     * a photo from a market with different settings.
     */
    public function test_it_declines_when_the_image_was_never_watermarked(): void
    {
        $logoPath = $this->dir . '/logo.png';
        $this->makeLogo($logoPath, 0);

        $plainPath = $this->dir . '/plain.jpg';
        $photo = $this->makePhoto();
        imagejpeg($photo, $plainPath, 100);
        imagedestroy($photo);

        $before = md5_file($plainPath);

        $removed = (new WatermarkRemover(new WatermarkStamp($logoPath, 'br', 90)))->removeFromFile($plainPath);

        $this->assertFalse($removed);
        $this->assertSame($before, md5_file($plainPath), 'A declined removal must not rewrite the file.');
    }

    /**
     * The real Exotic mark is 674x160 and lands on photos narrower than that.
     * imagecopy clips rather than scaling or refusing, so the logo hangs off
     * both sides and only its middle is composited — the removal has to handle
     * the same negative origin instead of treating the size as an error.
     */
    public function test_it_removes_a_stamp_wider_than_the_image(): void
    {
        $logoPath = $this->dir . '/wide-logo.png';

        // 300 wide with ink only in the middle 100, mirroring a wordmark inside
        // a wide transparent canvas.
        $logo = imagecreatetruecolor(300, 40);
        imagesavealpha($logo, true);
        imagealphablending($logo, false);
        imagefill($logo, 0, 0, imagecolorallocatealpha($logo, 255, 255, 255, 127));
        for ($y = 8; $y < 32; $y++) {
            for ($x = 100; $x < 200; $x++) {
                imagesetpixel($logo, $x, $y, imagecolorallocatealpha($logo, 255, 255, 255, 40));
            }
        }
        imagepng($logo, $logoPath);
        imagedestroy($logo);

        $originalPath = $this->dir . '/wide-original.jpg';
        $stampedPath = $this->dir . '/wide-stamped.jpg';

        // Photo narrower than the stamp, so the origin is negative.
        $photo = $this->makePhoto(200, 260);
        imagejpeg($photo, $originalPath, 100);

        $x = (int) round(200 / 2 - 300 / 2);
        $y = (int) round(260 / 2 - 40 / 2);
        $this->assertLessThan(0, $x, 'The fixture should place the stamp off the left edge.');

        $this->stampOnto($photo, $logoPath, $x, $y);
        imagejpeg($photo, $stampedPath, 90);
        imagedestroy($photo);

        // The ink lands from image x 0 to 100.
        [$meanBefore] = $this->compare($originalPath, $stampedPath, 0, $y, 100, 40);
        $this->assertGreaterThan(20, $meanBefore, 'The overlap should be visibly stamped.');

        $this->assertTrue(
            (new WatermarkRemover(new WatermarkStamp($logoPath, 'cc', 90)))->removeFromFile($stampedPath),
            'A clipped stamp must still be removed.'
        );

        [$meanAfter] = $this->compare($originalPath, $stampedPath, 0, $y, 100, 40);
        $this->assertLessThan($meanBefore / 4, $meanAfter, 'The clipped region should be recovered.');
    }

    /** A stamp placed entirely off the canvas leaves nothing to recover. */
    public function test_it_declines_when_the_stamp_barely_touches_the_image(): void
    {
        $logoPath = $this->dir . '/logo.png';
        $this->makeLogo($logoPath);

        $tinyPath = $this->dir . '/tiny.jpg';
        $tiny = $this->makePhoto(8, 8);
        imagejpeg($tiny, $tinyPath, 100);
        imagedestroy($tiny);

        $this->assertFalse((new WatermarkRemover(new WatermarkStamp($logoPath, 'br', 90)))->removeFromFile($tinyPath));
    }

    public function test_it_declines_without_a_usable_stamp(): void
    {
        $path = $this->dir . '/photo.jpg';
        $photo = $this->makePhoto();
        imagejpeg($photo, $path, 100);
        imagedestroy($photo);

        $this->assertFalse((new WatermarkRemover(new WatermarkStamp($this->dir . '/missing.png', 'br', 90)))->removeFromFile($path));
    }
}
