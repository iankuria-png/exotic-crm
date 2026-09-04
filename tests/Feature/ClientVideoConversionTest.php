<?php

namespace Tests\Feature;

use App\Jobs\ConvertClientVideoUploadJob;
use App\Models\Client;
use App\Models\Platform;
use App\Models\User;
use App\Services\MediaConversionStatusService;
use App\Services\VideoTranscodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientVideoConversionTest extends TestCase
{
    use RefreshDatabase;

    private function platform(): Platform
    {
        return Platform::factory()->create([
            'wp_api_url' => 'https://ghana.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }

    private function actingSalesUser(Platform $platform): User
    {
        $user = User::query()->create([
            'name' => 'Sales User',
            'email' => 'sales-' . Str::lower(Str::random(6)) . '@example.test',
            'password' => bcrypt('password'),
            'role' => 'sales',
            'assigned_market_ids' => [$platform->id],
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        return $user;
    }

    private function fakeTranscoder(bool $available): void
    {
        $this->instance(VideoTranscodeService::class, new class($available) extends VideoTranscodeService {
            public function __construct(private bool $isAvailable)
            {
            }

            public function available(): bool
            {
                return $this->isAvailable;
            }
        });
    }

    public function test_mov_upload_is_queued_for_conversion_instead_of_being_sent_to_wordpress(): void
    {
        Queue::fake();
        $platform = $this->platform();
        $client = Client::factory()->create(['platform_id' => $platform->id, 'wp_post_id' => 61783]);
        $this->actingSalesUser($platform);
        $this->fakeTranscoder(true);

        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $response = $this->post("/api/crm/clients/{$client->id}/media", [
            'file' => UploadedFile::fake()->create('clip.mov', 2048, 'video/quicktime'),
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('uploaded_count', 0)
            ->assertJsonPath('conversions.0.status', 'queued');

        Queue::assertPushed(ConvertClientVideoUploadJob::class);

        // The market site must never see the .mov itself.
        Http::assertNotSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/media'));
    }

    public function test_mov_upload_is_rejected_when_the_server_cannot_convert(): void
    {
        Queue::fake();
        $platform = $this->platform();
        $client = Client::factory()->create(['platform_id' => $platform->id, 'wp_post_id' => 61783]);
        $this->actingSalesUser($platform);
        $this->fakeTranscoder(false);

        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $this->post("/api/crm/clients/{$client->id}/media", [
            'file' => UploadedFile::fake()->create('clip.mov', 2048, 'video/quicktime'),
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'MOV videos cannot be converted on this server yet. Export the clip as MP4 and upload that instead.');

        Queue::assertNothingPushed();
    }

    public function test_mp4_upload_still_goes_straight_to_wordpress(): void
    {
        Queue::fake();
        $platform = $this->platform();
        $client = Client::factory()->create(['platform_id' => $platform->id, 'wp_post_id' => 61783]);
        $this->actingSalesUser($platform);
        $this->fakeTranscoder(true);

        $mediaUrl = rtrim($platform->wp_api_url, '/') . "/clients/{$client->wp_post_id}/media";
        Http::fake(function ($request) use ($mediaUrl) {
            if ($request->method() === 'POST' && $request->url() === $mediaUrl) {
                return Http::response([
                    'attachment' => ['id' => 991, 'url' => 'https://x.test/clip.mp4', 'mime_type' => 'video/mp4', 'is_main' => false],
                ], 200);
            }

            return Http::response(['data' => []], 200);
        });

        $this->post("/api/crm/clients/{$client->id}/media", [
            'file' => UploadedFile::fake()->create('clip.mp4', 2048, 'video/mp4'),
        ])
            ->assertOk()
            ->assertJsonPath('uploaded_count', 1);

        Queue::assertNothingPushed();
    }

    public function test_conversion_status_endpoint_reports_queued_work_for_the_client(): void
    {
        Queue::fake();
        $platform = $this->platform();
        $client = Client::factory()->create(['platform_id' => $platform->id, 'wp_post_id' => 61783]);
        $this->actingSalesUser($platform);
        $this->fakeTranscoder(true);

        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $conversionId = $this->post("/api/crm/clients/{$client->id}/media", [
            'file' => UploadedFile::fake()->create('clip.mov', 2048, 'video/quicktime'),
        ])->json('conversions.0.conversion_id');

        $this->getJson("/api/crm/clients/{$client->id}/media/conversions")
            ->assertOk()
            ->assertJsonPath('data.0.conversion_id', $conversionId)
            ->assertJsonPath('data.0.status', 'queued')
            ->assertJsonPath('data.0.original_name', 'clip.mov');
    }

    public function test_failed_conversion_surfaces_through_the_status_feed(): void
    {
        $platform = $this->platform();
        $client = Client::factory()->create(['platform_id' => $platform->id, 'wp_post_id' => 61783]);
        $this->actingSalesUser($platform);

        $status = app(MediaConversionStatusService::class);
        $status->put('conv-1', [
            'client_id' => (int) $client->id,
            'status' => 'failed',
            'original_name' => 'clip.mov',
            'message' => 'Conversion timed out after 900 seconds.',
        ]);

        $this->getJson("/api/crm/clients/{$client->id}/media/conversions")
            ->assertOk()
            ->assertJsonPath('data.0.status', 'failed')
            ->assertJsonPath('data.0.message', 'Conversion timed out after 900 seconds.');
    }

    public function test_conversion_job_uploads_the_converted_mp4_and_never_the_original(): void
    {
        $platform = $this->platform();
        $client = Client::factory()->create(['platform_id' => $platform->id, 'wp_post_id' => 61783]);

        $directory = storage_path('app/media-conversions');
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $sourcePath = $directory . '/job-test.mov';
        file_put_contents($sourcePath, 'quicktime-bytes');

        // Stand in for ffmpeg: writes the MP4 the real binary would produce.
        $this->instance(VideoTranscodeService::class, new class extends VideoTranscodeService {
            public function __construct()
            {
            }

            public function available(): bool
            {
                return true;
            }

            public function toMp4(string $sourcePath, string $targetPath): array
            {
                file_put_contents($targetPath, 'mp4-bytes');

                return ['ok' => true, 'mode' => 'transcode', 'message' => 'ok', 'duration_seconds' => 1.0];
            }
        });

        $uploadedNames = [];
        Http::fake(function ($request) use (&$uploadedNames) {
            if ($request->method() === 'POST' && str_contains($request->url(), '/media')) {
                foreach ($request->data() as $part) {
                    if (($part['name'] ?? '') === 'file') {
                        $uploadedNames[] = $part['filename'] ?? '';
                    }
                }

                return Http::response([
                    'attachment' => ['id' => 555, 'url' => 'https://x.test/clip.mp4', 'mime_type' => 'video/mp4'],
                ], 200);
            }

            return Http::response(['data' => []], 200);
        });

        (new ConvertClientVideoUploadJob('conv-job-1', (int) $client->id, $sourcePath, 'clip.mov'))
            ->handle(app(VideoTranscodeService::class), app(MediaConversionStatusService::class), app(\App\Services\AuditService::class));

        $this->assertSame(['clip.mp4'], $uploadedNames);

        $status = app(MediaConversionStatusService::class)->get('conv-job-1');
        $this->assertSame('completed', $status['status']);
        $this->assertSame(555, (int) $status['attachment']['id']);

        // Both temp files are cleaned up.
        $this->assertFileDoesNotExist($sourcePath);
        $this->assertFileDoesNotExist($directory . '/job-test.mp4');
    }

    public function test_conversion_job_reports_failure_and_cleans_up_when_ffmpeg_fails(): void
    {
        $platform = $this->platform();
        $client = Client::factory()->create(['platform_id' => $platform->id, 'wp_post_id' => 61783]);

        $directory = storage_path('app/media-conversions');
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $sourcePath = $directory . '/job-fail.mov';
        file_put_contents($sourcePath, 'quicktime-bytes');

        $this->instance(VideoTranscodeService::class, new class extends VideoTranscodeService {
            public function __construct()
            {
            }

            public function available(): bool
            {
                return true;
            }

            public function toMp4(string $sourcePath, string $targetPath): array
            {
                return ['ok' => false, 'mode' => 'transcode', 'message' => 'Unsupported codec.', 'duration_seconds' => 0.5];
            }
        });

        Http::fake(['*' => Http::response(['data' => []], 200)]);

        (new ConvertClientVideoUploadJob('conv-job-2', (int) $client->id, $sourcePath, 'clip.mov'))
            ->handle(app(VideoTranscodeService::class), app(MediaConversionStatusService::class), app(\App\Services\AuditService::class));

        $status = app(MediaConversionStatusService::class)->get('conv-job-2');
        $this->assertSame('failed', $status['status']);
        $this->assertSame('Unsupported codec.', $status['message']);
        $this->assertFileDoesNotExist($sourcePath);

        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_conversion_is_only_claimed_for_containers_wordpress_cannot_store(): void
    {
        $this->assertTrue(VideoTranscodeService::needsConversion('mov'));
        $this->assertTrue(VideoTranscodeService::needsConversion('MOV'));
        $this->assertTrue(VideoTranscodeService::needsConversion('qt'));
        $this->assertFalse(VideoTranscodeService::needsConversion('mp4'));
        $this->assertFalse(VideoTranscodeService::needsConversion('jpg'));
    }
}
