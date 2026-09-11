<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\User;
use App\Services\Ops\OperationsSettingsService;
use App\Services\SupportBoardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupportBoardKillSwitchTest extends TestCase
{
    use RefreshDatabase;

    private function switch(bool $on): void
    {
        $service = app(OperationsSettingsService::class);
        $service->update(
            [['key' => 'ops.support_board.enabled', 'value' => $on]],
            null,
            'admin'
        );
        $service->forget();
    }

    private function platform(): Platform
    {
        return Platform::query()->create([
            'name' => 'Kenya '.Str::random(4),
            'domain' => 'ke-'.Str::random(6).'.example.test',
            'country' => 'Kenya',
            'timezone' => 'Africa/Nairobi',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'is_active' => true,
            'support_board_api_url' => 'https://cloud.board.support/script/include/api.php',
            'support_board_token' => 'sb-token-'.Str::random(8),
            'support_board_sender_id' => '1',
        ]);
    }

    private function admin(): User
    {
        return User::query()->create([
            'name' => 'Admin '.Str::random(4),
            'email' => Str::random(8).'@example.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'active',
            'assigned_market_ids' => [],
        ]);
    }

    public function test_a_fully_credentialed_market_reports_unconfigured_while_the_switch_is_off(): void
    {
        $platform = $this->platform();
        $service = new SupportBoardService($platform);

        $this->switch(true);
        $this->assertTrue($service->hasCredentials());
        $this->assertTrue($service->isConfigured(), 'sanity: enabled + credentials = configured');

        $this->switch(false);
        $this->assertTrue($service->hasCredentials(), 'credentials are untouched by the switch');
        $this->assertFalse($service->isConfigured(), 'the switch must win over credentials');
        $this->assertFalse(SupportBoardService::isEnabled());
    }

    public function test_no_outbound_http_call_is_made_while_the_switch_is_off(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $this->switch(false);
        $platform = $this->platform();
        $service = new SupportBoardService($platform);

        // Every public read path must refuse before reaching the network.
        foreach (['findUserByPhone', 'findUserByEmail'] as $method) {
            try {
                $service->{$method}($method === 'findUserByPhone' ? '254712345678' : 'a@b.test');
            } catch (\Throwable $e) {
                // Refusing loudly is fine; making a request is not.
            }
        }

        Http::assertNothingSent();
    }

    public function test_the_dashboard_chat_count_short_circuits_without_querying_markets(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $this->platform();
        $this->switch(false);
        Sanctum::actingAs($this->admin());

        $response = $this->getJson('/api/crm/dashboard');
        $response->assertOk();

        Http::assertNothingSent();
    }

    public function test_starting_a_sync_is_refused_with_a_message_that_names_the_switch(): void
    {
        $platform = $this->platform();
        $this->switch(false);
        Sanctum::actingAs($this->admin());

        $response = $this->postJson(
            "/api/crm/settings/integrations/platforms/{$platform->id}/support-board/sync",
            ['reason' => 'testing the kill switch']
        );

        $response->assertStatus(422);
        $this->assertStringContainsString('switched off', (string) $response->json('message'));
    }

    public function test_starting_a_lead_import_is_refused_while_the_switch_is_off(): void
    {
        $platform = $this->platform();
        $this->switch(false);
        Sanctum::actingAs($this->admin());

        $response = $this->postJson(
            "/api/crm/settings/integrations/platforms/{$platform->id}/support-board/lead-import",
            ['mode' => 'incremental', 'reason' => 'testing the kill switch']
        );

        $response->assertStatus(422);
        $this->assertStringContainsString('switched off', (string) $response->json('message'));
    }

    public function test_the_console_command_stands_down_without_taking_its_lock(): void
    {
        $this->platform();
        $this->switch(false);

        $this->artisan('crm:sync-sb-users')
            ->expectsOutputToContain('Support Board is switched off.')
            ->assertSuccessful();
    }

    public function test_turning_the_switch_back_on_restores_the_previous_behaviour(): void
    {
        $platform = $this->platform();
        $service = new SupportBoardService($platform);

        $this->switch(false);
        $this->assertFalse($service->isConfigured());

        $this->switch(true);
        $this->assertTrue($service->isConfigured(), 'the switch must be reversible, not a one-way door');
    }
}
