<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\TimelineEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DealDeactivationStructuredTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_reversed_reason_reverses_payment_and_flags_client_high_risk(): void
    {
        [$platform, $client, $deal, $payment] = $this->createDealFixture();
        $user = $this->createUser($platform, 'admin');
        $this->fakeWpDeactivationApis($platform, $client);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/deals/{$deal->id}/deactivate", [
            'reason_code' => 'payment_reversed',
            'reason_notes' => 'Bank reversed the operator payment.',
            'linked_payment_action' => 'reverse',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('cancellation_reason_code', 'payment_reversed')
            ->assertJsonPath('cancelled_payment_id', $payment->id)
            ->assertJsonPath('client.is_high_risk', true)
            ->assertJsonPath('client.risk_reason_code', 'payment_reversed');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'completed',
            'resolution_code' => 'reversed',
        ]);

        $this->assertDatabaseHas('deals', [
            'id' => $deal->id,
            'status' => 'cancelled',
            'cancellation_reason_code' => 'payment_reversed',
            'cancelled_payment_id' => $payment->id,
        ]);

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'is_high_risk' => true,
            'risk_reason_code' => 'payment_reversed',
            'risk_marked_by' => $user->id,
        ]);

        $profileEvent = TimelineEvent::query()
            ->where('entity_type', 'client')
            ->where('entity_id', $client->id)
            ->where('event_type', 'profile_deactivated')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('payment_reversed', data_get($profileEvent->content, 'reason_code'));
        $this->assertSame('reverse', data_get($profileEvent->content, 'linked_payment_action'));

        $riskEvent = TimelineEvent::query()
            ->where('entity_type', 'client')
            ->where('entity_id', $client->id)
            ->where('event_type', 'client_risk_marked')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('payment_reversed', data_get($riskEvent->content, 'reason_code'));

        $audit = AuditLog::query()
            ->where('entity_type', 'deal')
            ->where('entity_id', $deal->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('payment_reversed', data_get($audit->after_state, 'cancellation_reason_code'));
        $this->assertSame('reversed', data_get($audit->after_state, 'payment_resolution_code'));
    }

    public function test_invalid_reference_reason_invalidates_payment_without_marking_client_high_risk(): void
    {
        [$platform, $client, $deal, $payment] = $this->createDealFixture();
        $user = $this->createUser($platform, 'admin');
        $this->fakeWpDeactivationApis($platform, $client);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/deals/{$deal->id}/deactivate", [
            'reason_code' => 'invalid_reference',
            'reason_notes' => 'Reference did not exist in the provider export.',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('cancellation_reason_code', 'invalid_reference')
            ->assertJsonPath('client.is_high_risk', false);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'failed',
            'resolution_code' => 'invalid_reference',
        ]);

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'is_high_risk' => false,
            'risk_reason_code' => null,
        ]);
    }

    public function test_existing_high_risk_client_preserves_original_attribution_and_records_reaffirmed_event(): void
    {
        [$platform, $client, $deal] = $this->createDealFixture([
            'is_high_risk' => true,
            'risk_reason_code' => 'fraud_suspected',
        ]);
        $originalMarkedAt = now()->subDays(5)->startOfMinute();
        $client->forceFill([
            'risk_marked_at' => $originalMarkedAt,
        ])->save();

        $user = $this->createUser($platform, 'admin');
        $this->fakeWpDeactivationApis($platform, $client);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/deals/{$deal->id}/deactivate", [
            'reason_code' => 'payment_reversed',
            'reason_notes' => 'Repeat reversal after prior fraud review.',
        ]);

        $response->assertOk()
            ->assertJsonPath('client.is_high_risk', true)
            ->assertJsonPath('client.risk_reason_code', 'fraud_suspected');

        $client->refresh();
        $this->assertSame('fraud_suspected', $client->risk_reason_code);
        $this->assertTrue($client->risk_marked_at?->equalTo($originalMarkedAt));

        $reaffirmedEvent = TimelineEvent::query()
            ->where('entity_type', 'client')
            ->where('entity_id', $client->id)
            ->where('event_type', 'client_risk_reaffirmed')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('payment_reversed', data_get($reaffirmedEvent->content, 'reason_code'));
        $this->assertSame('fraud_suspected', data_get($reaffirmedEvent->content, 'original_risk_reason_code'));
    }

    public function test_legacy_reason_payload_maps_to_other_reason_code(): void
    {
        [$platform, $client, $deal] = $this->createDealFixture();
        $user = $this->createUser($platform, 'admin');
        $this->fakeWpDeactivationApis($platform, $client);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/deals/{$deal->id}/deactivate", [
            'reason' => 'Legacy free-text reason from older clients.',
        ]);

        $response->assertOk()
            ->assertJsonPath('cancellation_reason_code', 'other')
            ->assertJsonPath('cancellation_notes', 'Legacy free-text reason from older clients.');

        $this->assertDatabaseHas('deals', [
            'id' => $deal->id,
            'cancellation_reason_code' => 'other',
            'cancellation_notes' => 'Legacy free-text reason from older clients.',
        ]);
    }

    public function test_deals_index_can_filter_high_risk_and_cancellation_reason(): void
    {
        [$platform, $client, $deal] = $this->createDealFixture([
            'is_high_risk' => true,
            'risk_reason_code' => 'fraud_suspected',
        ]);
        $user = $this->createUser($platform, 'admin');

        $deal->forceFill([
            'status' => 'cancelled',
            'cancellation_reason_code' => 'payment_reversed',
            'cancellation_notes' => 'Historical reversal.',
        ])->save();

        Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => Client::factory()->create([
                'platform_id' => $platform->id,
                'is_high_risk' => false,
            ])->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/crm/deals?platform_id={$platform->id}&high_risk=1&cancellation_reason_code=payment_reversed");

        $response->assertOk()
            ->assertJsonPath('targets.data.0.client.id', $client->id)
            ->assertJsonPath('targets.data.0.cancellation_reason_code', 'payment_reversed')
            ->assertJsonPath('targets.data.0.client.is_high_risk', true);
    }

    /**
     * @param  array<string, mixed>  $clientOverrides
     * @return array{0: Platform, 1: Client, 2: Deal, 3: Payment}
     */
    private function createDealFixture(array $clientOverrides = []): array
    {
        $platform = Platform::query()->create([
            'name' => 'Structured Deactivation Market',
            'domain' => 'structured-deactivation-' . Str::random(6) . '.example.test',
            'country' => 'Kenya',
            'timezone' => 'Africa/Nairobi',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'is_active' => true,
            'wp_api_url' => 'https://structured-deactivation.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);

        $client = Client::factory()->create(array_merge([
            'platform_id' => $platform->id,
            'wp_post_id' => 92001,
            'wp_user_id' => 82001,
            'name' => 'Structured Client',
            'phone_normalized' => '254700001111',
            'profile_status' => 'publish',
            'is_high_risk' => false,
        ], $clientOverrides));

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'status' => 'completed',
            'reconciliation_state' => 'open',
            'transaction_reference' => 'STRUCTURED-PAY-' . Str::upper(Str::random(6)),
            'reference_number' => 'STRUCTURED-REF-' . Str::upper(Str::random(6)),
        ]);

        $deal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'payment_id' => $payment->id,
            'status' => 'active',
        ]);

        $payment->forceFill([
            'deal_id' => $deal->id,
        ])->save();

        return [$platform, $client, $deal, $payment];
    }

    private function createUser(Platform $platform, string $role): User
    {
        return User::query()->create([
            'name' => ucfirst($role) . ' Structured User',
            'email' => strtolower($role) . '-' . Str::random(6) . '@example.test',
            'password' => bcrypt('password'),
            'role' => $role,
            'assigned_market_ids' => [$platform->id],
            'status' => 'active',
        ]);
    }

    private function fakeWpDeactivationApis(Platform $platform, Client $client): void
    {
        $baseUrl = rtrim((string) $platform->wp_api_url, '/');

        Http::fake([
            "{$baseUrl}/clients/{$client->wp_post_id}/deactivate" => Http::response([
                'success' => true,
            ], 200),
            "{$baseUrl}/clients/{$client->wp_post_id}" => Http::response([
                'wp_post_id' => (int) $client->wp_post_id,
                'wp_user_id' => (int) $client->wp_user_id,
                'name' => $client->name,
                'phone' => $client->phone_normalized,
                'email' => $client->email,
                'city' => $client->city,
                'post_status' => 'private',
                'premium' => false,
                'featured' => false,
                'verified' => false,
                'main_image_url' => $client->main_image_url,
                'modified_at' => now()->toIso8601String(),
            ], 200),
        ]);
    }
}
