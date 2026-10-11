<?php

namespace Tests\Unit;

use App\Services\PushNotification\ExoticPushProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExoticPushSubscriberErrorTest extends TestCase
{
    public function test_structured_provider_error_preserves_diagnostics_without_array_cast(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 'unauthorized', 'message' => 'Invalid site']], 401)]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('"code":"unauthorized"');
        (new ExoticPushProvider)->getSubscriberCount(['site_id' => 'test', 'api_key' => 'synthetic', 'auth_token' => 'synthetic']);
    }
}
