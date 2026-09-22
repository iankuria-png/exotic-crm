<?php

namespace Tests\Unit;

use App\Services\ClientMediaWatermarker;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ClientMediaWatermarkerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/client-media-watermarker-'.uniqid('', true);
        mkdir($this->directory, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->directory);

        parent::tearDown();
    }

    public function test_it_stamps_a_copy_with_the_default_exotic_escorts_logo(): void
    {
        $sourcePath = $this->directory.'/photo.png';
        $image = imagecreatetruecolor(400, 300);
        imagefill($image, 0, 0, imagecolorallocate($image, 72, 28, 52));
        imagepng($image, $sourcePath);
        imagedestroy($image);

        $sourceHash = md5_file($sourcePath);
        $file = new UploadedFile($sourcePath, 'photo.png', 'image/png', null, true);
        $stamped = app(ClientMediaWatermarker::class)->stamp($file);

        try {
            $this->assertFileExists($stamped['path']);
            $this->assertSame('image/png', $stamped['mime_type']);
            $this->assertSame($sourceHash, md5_file($sourcePath), 'The original upload must remain untouched.');
            $this->assertNotSame($sourceHash, md5_file($stamped['path']), 'The stamped copy must contain the logo.');
            $this->assertSame([400, 300], array_slice(getimagesize($stamped['path']), 0, 2));
        } finally {
            @unlink($stamped['path']);
        }
    }

    public function test_it_respects_a_configured_top_left_position_and_large_size(): void
    {
        $sourcePath = $this->directory.'/photo.jpg';
        $image = imagecreatetruecolor(600, 400);
        imagefill($image, 0, 0, imagecolorallocate($image, 40, 70, 120));
        imagejpeg($image, $sourcePath, 100);
        imagedestroy($image);

        $file = new UploadedFile($sourcePath, 'photo.jpg', 'image/jpeg', null, true);
        $stamped = app(ClientMediaWatermarker::class)->stamp($file, 'tl', 'large');

        try {
            $this->assertSame('image/jpeg', $stamped['mime_type']);
            $this->assertSame([600, 400], array_slice(getimagesize($stamped['path']), 0, 2));
        } finally {
            @unlink($stamped['path']);
        }
    }
}
