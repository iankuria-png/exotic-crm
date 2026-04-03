<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Platform;
use App\Services\CredentialDeliveryService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class ClientAccessServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_access_context_reports_disabled_reset_and_available_session_link_when_only_api_credentials_exist(): void
    {
        $platform = Platform::factory()->create([
            'db_host' => null,
            'db_name' => null,
            'db_user' => null,
            'db_pass' => null,
            'domain' => 'tanzania.example.test',
            'wp_api_url' => 'https://tanzania.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);

        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 7777,
            'wp_user_id' => null,
        ]);

        $service = new CredentialDeliveryService(
            $this->mockedNotificationService()
        );

        $context = $service->accessContext($client);

        $this->assertSame('https://tanzania.example.test/wp-login.php', $context['login_url']);
        $this->assertSame('https://tanzania.example.test/wp-login.php?action=lostpassword', $context['setup_url']);
        $this->assertSame('https://tanzania.example.test/?p=7777', $context['profile_url']);
        $this->assertFalse($context['can_reset_password']);
        $this->assertTrue($context['can_generate_session_link']);
        $this->assertSame(CredentialDeliveryService::RESET_PASSWORD_DISABLED_MESSAGE, $context['messages']['reset_password']);
        $this->assertNull($context['messages']['login_as_client']);
    }

    private function mockedNotificationService(): NotificationService
    {
        $mock = $this->createMock(NotificationService::class);
        $mock->method('sendSms')->willReturn([
            'success' => true,
            'status' => 'sent',
            'provider' => 'fake',
            'provider_response' => 'ok',
        ]);

        return $mock;
    }
}
