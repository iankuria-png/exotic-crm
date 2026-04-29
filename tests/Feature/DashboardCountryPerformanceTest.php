<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardCountryPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_country_revenue_uses_the_exact_selected_window_even_when_country_period_is_week(): void
    {
        $platform = $this->createPlatform('Kenya');
        $admin = $this->createUser('admin');
        Sanctum::actingAs($admin);

        $this->createCompletedPayment($platform, 3000, now()->subDays(10));
        $this->createCompletedPayment($platform, 2000, now()->subDays(2));

        $from = now()->subDays(21)->toDateString();
        $to = now()->toDateString();

        $response = $this->getJson("/api/crm/dashboard/country-revenue?platform_id={$platform->id}&from={$from}&to={$to}&country_period=week");

        $response->assertOk()
            ->assertJsonPath('0.previous_revenue', null);

        $this->assertSame(5000.0, (float) $response->json('0.current_revenue'));
        $this->assertSame(5000.0, (float) $response->json('0.current_revenue_breakdown.KES'));
    }

    public function test_country_performance_returns_revenue_trend_and_selected_market_engagement_summary(): void
    {
        $platform = $this->createPlatform('Kenya');
        $user = $this->createUser('marketing', [$platform->id]);
        $this->createClient($platform, ['wp_post_id' => 16835, 'profile_status' => 'publish']);
        $this->createClient($platform, ['wp_post_id' => 16836, 'profile_status' => 'publish']);
        $this->createCompletedPayment($platform, 2000, now()->subDays(4));
        $this->createCompletedPayment($platform, 1000, now()->subDays(1));
        $baseUrl = rtrim((string) $platform->wp_api_url, '/');

        Http::fake([
            $baseUrl . '/analytics/rankings*' => Http::response([
                'platform_totals' => [
                    'profile_view' => ['total' => 326, 'delta_total_percent' => 12.0],
                    'contact_actions' => ['total' => 43, 'delta_total_percent' => 18.0],
                    'contact_rate_percent' => 13.2,
                ],
                'delta_contact_rate_pp' => 1.2,
                'market_averages' => [
                    'contact_rate_percent' => 10.6,
                ],
                'platform_contact_mix' => [
                    ['event_type' => 'whatsapp_click', 'label' => 'WhatsApp', 'total' => 22, 'share_percent' => 51.2],
                    ['event_type' => 'phone_click', 'label' => 'Phone', 'total' => 16, 'share_percent' => 37.2],
                    ['event_type' => 'viber_click', 'label' => 'Viber', 'total' => 5, 'share_percent' => 11.6],
                ],
                'profiles' => [],
            ]),
        ]);

        Sanctum::actingAs($user);

        $from = now()->subDays(6)->toDateString();
        $to = now()->toDateString();

        $response = $this->getJson("/api/crm/dashboard/country-performance/{$platform->id}?from={$from}&to={$to}");

        $response->assertOk()
            ->assertJsonPath('market.platform_id', $platform->id)
            ->assertJsonPath('summary.payments_count', 2)
            ->assertJsonPath('trend.bucket', 'day')
            ->assertJsonPath('user_summary.active_users', 2)
            ->assertJsonPath('user_summary.engagement.available', true)
            ->assertJsonPath('user_summary.engagement.health', 'above_market')
            ->assertJsonPath('contact_mix.0.label', 'WhatsApp')
            ->assertJsonPath('availability.engagement', true)
            ->assertJsonPath('availability.contact_mix', true);

        $this->assertSame(3000.0, (float) $response->json('summary.current_revenue'));
        $this->assertNotEmpty($response->json('trend.points'));
    }

    public function test_country_performance_gracefully_degrades_when_wp_analytics_is_unavailable(): void
    {
        $platform = $this->createPlatform('Kenya');
        $admin = $this->createUser('admin');
        Sanctum::actingAs($admin);
        $this->createCompletedPayment($platform, 1500, now()->subDays(1));
        $baseUrl = rtrim((string) $platform->wp_api_url, '/');

        Http::fake([
            $baseUrl . '/analytics/rankings*' => Http::response(['message' => 'bad gateway'], 502),
        ]);

        $from = now()->subDays(6)->toDateString();
        $to = now()->toDateString();

        $response = $this->getJson("/api/crm/dashboard/country-performance/{$platform->id}?from={$from}&to={$to}");

        $response->assertOk()
            ->assertJsonPath('user_summary.engagement.available', false)
            ->assertJsonPath('availability.engagement', false)
            ->assertJsonPath('availability.contact_mix', false);

        $this->assertSame(1500.0, (float) $response->json('summary.current_revenue'));
        $this->assertSame([], $response->json('contact_mix'));
    }

    private function createUser(string $role, array $assignedMarketIds = []): User
    {
        return User::query()->create([
            'name' => ucfirst($role) . ' User',
            'email' => strtolower($role) . Str::random(6) . '@example.test',
            'password' => bcrypt('password'),
            'role' => $role,
            'assigned_market_ids' => $assignedMarketIds,
            'status' => 'active',
        ]);
    }

    private function createPlatform(string $name): Platform
    {
        return Platform::query()->create([
            'name' => $name,
            'domain' => Str::slug($name) . '-' . Str::random(6) . '.example.test',
            'country' => $name,
            'timezone' => 'Africa/Nairobi',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'is_active' => true,
            'wp_api_url' => 'https://' . Str::slug($name) . '.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }

    private function createClient(Platform $platform, array $overrides = []): Client
    {
        return Client::query()->create(array_merge([
            'platform_id' => $platform->id,
            'name' => 'Client ' . Str::random(5),
            'phone_normalized' => '2547' . random_int(1000000, 9999999),
            'profile_status' => 'publish',
            'needs_payment' => false,
            'notactive' => false,
        ], $overrides));
    }

    private function createCompletedPayment(Platform $platform, float $amount, $createdAt): Payment
    {
        return Payment::query()->create([
            'platform_id' => $platform->id,
            'phone' => '254700' . random_int(100000, 999999),
            'amount' => $amount,
            'currency' => 'KES',
            'transaction_uuid' => (string) Str::uuid(),
            'transaction_reference' => Str::upper(Str::random(10)),
            'status' => 'completed',
            'purpose' => 'subscription',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
