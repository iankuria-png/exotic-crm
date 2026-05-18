<?php

namespace Tests\Feature\Kyc\Concerns;

use App\Models\Client;
use App\Models\KycDocument;
use App\Models\KycDocumentBlob;
use App\Models\KycSetting;
use App\Models\KycSubject;
use App\Models\Platform;
use App\Models\User;
use App\Services\Kyc\KycStorage\DbStorage;
use Laravel\Sanctum\Sanctum;

trait InteractsWithKycFixtures
{
    protected function createKycUser(string $role = 'admin', array $marketIds = []): User
    {
        $user = User::factory()->create([
            'role' => $role,
            'status' => 'active',
            'assigned_market_ids' => $role === 'admin' ? [] : $marketIds,
        ]);

        if ($marketIds !== []) {
            $user->platforms()->syncWithoutDetaching($marketIds);
        }

        return $user;
    }

    protected function actingAsKycUser(string $role = 'admin', array $marketIds = []): User
    {
        $user = $this->createKycUser($role, $marketIds);
        Sanctum::actingAs($user);
        return $user;
    }

    protected function createPlatform(array $overrides = []): Platform
    {
        return Platform::factory()->create($overrides);
    }

    protected function createClientForPlatform(Platform $platform, array $overrides = []): Client
    {
        return Client::factory()->create($overrides + [
            'platform_id' => $platform->id,
            'verified' => false,
            'kyc_required' => true,
        ]);
    }

    protected function createSubjectForClient(Client $client, array $overrides = []): KycSubject
    {
        return KycSubject::query()->create($overrides + [
            'client_id' => $client->id,
            'status' => KycSubject::STATUS_UNVERIFIED,
            'grace_started_at' => now(),
        ]);
    }

    protected function setKycSettings(array $overrides = []): KycSetting
    {
        $settings = app(\App\Services\Kyc\KycSettingsService::class)->get();
        $settings->fill($overrides);
        $settings->save();
        return $settings->fresh();
    }

    protected function sharedKeyHeaders(string $key = 'test-shared-key'): array
    {
        config(['services.exotic_crm_sync.shared_key' => $key]);
        return ['X-Exotic-CRM-Sync-Key' => $key];
    }

    protected function createDbDocument(KycSubject $subject, string $kind = 'id_front', string $contents = 'plain-image-bytes', string $mime = 'image/jpeg'): KycDocument
    {
        $document = KycDocument::query()->create([
            'subject_id' => $subject->id,
            'kind' => $kind,
            'storage_driver' => 'db',
            'mime' => $mime,
            'byte_size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'original_filename' => $kind . '.jpg',
            'uploaded_at' => now(),
        ]);

        KycDocumentBlob::query()->create([
            'document_id' => $document->id,
            'body' => app(DbStorage::class)->encryptRaw($contents),
        ]);

        return $document->fresh(['blob', 'subject.client']);
    }

    protected function signedPathFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $query = parse_url($url, PHP_URL_QUERY);
        return $query ? $path . '?' . $query : $path;
    }
}
