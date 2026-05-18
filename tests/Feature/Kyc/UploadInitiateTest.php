<?php

namespace Tests\Feature\Kyc;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Kyc\Concerns\InteractsWithKycFixtures;
use Tests\TestCase;

class UploadInitiateTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithKycFixtures;

    public function test_db_mode_upload_initiate_returns_upload_target_and_subject(): void
    {
        $platform = $this->createPlatform();
        $client = $this->createClientForPlatform($platform);
        $this->setKycSettings(['active_storage_driver' => 'db']);

        $response = $this->withHeaders($this->sharedKeyHeaders())->postJson('/api/kyc/uploads/initiate', [
            'platform_id' => $platform->id,
            'wp_user_id' => $client->wp_user_id,
            'wp_post_id' => $client->wp_post_id,
            'kind' => 'id_front',
            'mime' => 'image/jpeg',
            'byte_size' => 2048,
            'sha256' => str_repeat('a', 64),
        ]);

        $response->assertOk()
            ->assertJsonPath('mode', 'db')
            ->assertJsonPath('upload.method', 'POST')
            ->assertJsonStructure(['subject_id', 'upload' => ['url', 'method', 'headers', 'expires_at']]);
    }
}
