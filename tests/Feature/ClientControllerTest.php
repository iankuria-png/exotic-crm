<?php

namespace Tests\Feature;

use App\Http\Controllers\CRM\ClientController;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Platform;
use App\Models\Product;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Support\CrmAuditAction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_risk_state_can_be_marked_and_cleared_with_reason(): void
    {
        $platform = Platform::factory()->create();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'is_high_risk' => false,
            'risk_reason_code' => null,
            'risk_marked_at' => null,
            'risk_marked_by' => null,
        ]);
        $admin = $this->adminUser();

        Sanctum::actingAs($admin);

        $this->postJson("/api/crm/clients/{$client->id}/risk-state", [
            'is_high_risk' => true,
            'reason_code' => 'manual_proof_invalid',
            'reason' => 'Uploaded an unrelated screenshot during manual payment review.',
        ])
            ->assertOk()
            ->assertJsonPath('client.is_high_risk', true)
            ->assertJsonPath('client.risk_reason_code', 'manual_proof_invalid')
            ->assertJsonPath('client.risk_marked_by.name', $admin->name);

        $client->refresh();
        $this->assertTrue((bool) $client->is_high_risk);
        $this->assertSame('manual_proof_invalid', $client->risk_reason_code);
        $this->assertSame($admin->id, (int) $client->risk_marked_by);
        $this->assertNotNull($client->risk_marked_at);

        $markedEvent = TimelineEvent::query()
            ->where('entity_type', 'client')
            ->where('entity_id', $client->id)
            ->where('event_type', 'client_risk_marked')
            ->firstOrFail();
        $this->assertSame('manual_proof_invalid', data_get($markedEvent->content, 'reason_code'));
        $this->assertFalse((bool) data_get($markedEvent->content, 'manual_proof_upload_allowed'));

        $this->assertDatabaseHas('audit_log', [
            'platform_id' => $platform->id,
            'actor_id' => $admin->id,
            'action' => CrmAuditAction::CLIENT_RISK_MARK,
            'entity_type' => 'client',
            'entity_id' => $client->id,
        ]);

        $this->postJson("/api/crm/clients/{$client->id}/risk-state", [
            'is_high_risk' => false,
            'reason_code' => 'manual_crm_review',
            'reason' => 'Verified the client and cleared the prior payment proof concern.',
        ])
            ->assertOk()
            ->assertJsonPath('client.is_high_risk', false)
            ->assertJsonPath('client.risk_reason_code', null);

        $client->refresh();
        $this->assertFalse((bool) $client->is_high_risk);
        $this->assertNull($client->risk_reason_code);
        $this->assertNull($client->risk_marked_at);
        $this->assertNull($client->risk_marked_by);

        $this->assertDatabaseHas('timeline_events', [
            'platform_id' => $platform->id,
            'entity_type' => 'client',
            'entity_id' => $client->id,
            'event_type' => 'client_risk_cleared',
            'actor_id' => $admin->id,
        ]);

        $this->assertDatabaseHas('audit_log', [
            'platform_id' => $platform->id,
            'actor_id' => $admin->id,
            'action' => CrmAuditAction::CLIENT_RISK_CLEAR,
            'entity_type' => 'client',
            'entity_id' => $client->id,
        ]);
    }

    public function test_client_create_profile_payload_maps_bio_to_wordpress_content(): void
    {
        $platform = Platform::factory()->create([
            'name' => 'Kenya',
            'domain' => 'kenya.example.test',
            'country' => 'Kenya',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'timezone' => 'Africa/Nairobi',
            'wp_api_url' => 'https://kenya.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'crm-password',
        ]);

        $request = \Illuminate\Http\Request::create('/api/crm/clients', 'POST', [
            'bio' => '<p>Generated profile bio.</p>',
            'services' => ['1', '2', '3'],
            'whatsapp' => '+254743394489',
            'telegram' => 'https://telegram.im/@rawexotichub?lang=en',
        ]);

        $controller = app(ClientController::class);
        $method = new \ReflectionMethod(ClientController::class, 'prepareProvisioningProfilePayload');

        $payload = $method->invoke($controller, $request, $platform);

        $this->assertSame('<p>Generated profile bio.</p>', $payload['content']);
        $this->assertArrayNotHasKey('bio', $payload);
        $this->assertSame(['1', '2', '3'], $payload['services']);
        $this->assertSame('+254743394489', $payload['whatsapp']);
        $this->assertSame('https://telegram.im/@rawexotichub?lang=en', $payload['telegram']);
    }

    public function test_wp_profile_update_skips_catalog_fetches_when_saving_unrelated_fields(): void
    {
        [$platform, $client] = $this->createLinkedClientFixture();
        $updateWasSent = false;

        Http::fake(function (ClientRequest $request) use ($client, &$updateWasSent) {
            $url = (string) $request->url();

            if (str_ends_with($url, "/clients/{$client->wp_post_id}/update")) {
                $updateWasSent = true;
                $this->assertSame('POST', $request->method());
                $this->assertSame(['content' => 'Updated public profile bio.'], $request->data()['fields'] ?? null);

                return Http::response([
                    'wp_post_id' => $client->wp_post_id,
                    'meta' => ['currency' => 50],
                ]);
            }

            if (str_ends_with($url, "/clients/{$client->wp_post_id}")) {
                return Http::response($this->wordpressClientPayload($client, [
                    'content' => 'Updated public profile bio.',
                ]));
            }

            if (str_ends_with($url, '/locations') || str_ends_with($url, '/currencies')) {
                return Http::response([
                    'code' => 'rest_no_route',
                    'message' => 'No route was found matching the URL and request method.',
                    'data' => ['status' => 404],
                ], 404);
            }

            return Http::response(['message' => 'Unexpected request: '.$url], 500);
        });

        Sanctum::actingAs($this->adminUser());

        $this->patchJson("/api/crm/clients/{$client->id}/wp-profile", [
            'fields' => ['content' => 'Updated public profile bio.'],
            'force' => true,
            'reason' => 'Regression test profile bio update',
        ])->assertOk();

        $this->assertTrue($updateWasSent);
        Http::assertNotSent(fn (ClientRequest $request): bool => str_ends_with((string) $request->url(), '/locations'));
        Http::assertNotSent(fn (ClientRequest $request): bool => str_ends_with((string) $request->url(), '/currencies'));
    }

    public function test_wp_profile_location_update_still_requires_location_catalog(): void
    {
        [$platform, $client] = $this->createLinkedClientFixture();

        Http::fake(function (ClientRequest $request) use ($client) {
            $url = (string) $request->url();

            if (str_ends_with($url, "/clients/{$client->wp_post_id}")) {
                return Http::response($this->wordpressClientPayload($client));
            }

            if (str_ends_with($url, '/locations')) {
                return Http::response([
                    'code' => 'rest_no_route',
                    'message' => 'No route was found matching the URL and request method.',
                    'data' => ['status' => 404],
                ], 404);
            }

            return Http::response(['message' => 'Unexpected request: '.$url], 500);
        });

        Sanctum::actingAs($this->adminUser());

        $this->patchJson("/api/crm/clients/{$client->id}/wp-profile", [
            'fields' => ['region_id' => 10, 'city_id' => 11],
            'force' => true,
            'reason' => 'Regression test location update',
        ])
            ->assertStatus(404)
            ->assertJsonPath('code', 'rest_no_route');

        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with((string) $request->url(), '/locations'));
        Http::assertNotSent(fn (ClientRequest $request): bool => str_ends_with((string) $request->url(), "/clients/{$client->wp_post_id}/update"));
    }

    public function test_wp_profile_currency_update_still_requires_currency_catalog_only(): void
    {
        [$platform, $client] = $this->createLinkedClientFixture();

        Http::fake(function (ClientRequest $request) use ($client) {
            $url = (string) $request->url();

            if (str_ends_with($url, "/clients/{$client->wp_post_id}")) {
                return Http::response($this->wordpressClientPayload($client, [
                    'meta' => ['currency' => 50],
                ]));
            }

            if (str_ends_with($url, '/currencies')) {
                return Http::response([
                    'code' => 'rest_no_route',
                    'message' => 'No route was found matching the URL and request method.',
                    'data' => ['status' => 404],
                ], 404);
            }

            return Http::response(['message' => 'Unexpected request: '.$url], 500);
        });

        Sanctum::actingAs($this->adminUser());

        $this->patchJson("/api/crm/clients/{$client->id}/wp-profile", [
            'fields' => ['currency' => 76],
            'force' => true,
            'reason' => 'Regression test currency update',
        ])
            ->assertStatus(404)
            ->assertJsonPath('code', 'rest_no_route');

        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with((string) $request->url(), '/currencies'));
        Http::assertNotSent(fn (ClientRequest $request): bool => str_ends_with((string) $request->url(), '/locations'));
        Http::assertNotSent(fn (ClientRequest $request): bool => str_ends_with((string) $request->url(), "/clients/{$client->wp_post_id}/update"));
    }

    public function test_wp_profile_update_normalizes_legacy_availability_labels_before_validation(): void
    {
        [$platform, $client] = $this->createLinkedClientFixture();
        $updateWasSent = false;

        Http::fake(function (ClientRequest $request) use ($client, &$updateWasSent) {
            $url = (string) $request->url();

            if (str_ends_with($url, "/clients/{$client->wp_post_id}/update")) {
                $updateWasSent = true;
                $this->assertSame('POST', $request->method());
                $this->assertSame(['availability' => ['1', '2'], 'currency' => 50], $request->data()['fields'] ?? null);

                return Http::response([
                    'wp_post_id' => $client->wp_post_id,
                    'meta' => ['currency' => 50, 'availability' => ['1', '2']],
                ]);
            }

            if (str_ends_with($url, "/clients/{$client->wp_post_id}")) {
                return Http::response($this->wordpressClientPayload($client, [
                    'meta' => [
                        'currency' => 50,
                        'availability' => 'Incall, Outcall',
                    ],
                ]));
            }

            if (str_ends_with($url, '/currencies')) {
                return Http::response([
                    'currencies' => [
                        ['id' => 50, 'code' => 'RWF', 'name' => 'Rwandan Franc', 'symbol' => 'FRw'],
                    ],
                ]);
            }

            return Http::response(['message' => 'Unexpected request: '.$url], 500);
        });

        Sanctum::actingAs($this->adminUser());

        $this->patchJson("/api/crm/clients/{$client->id}/wp-profile", [
            'fields' => [
                'availability' => ['Incall', 'Outcall'],
                'currency' => 50,
            ],
            'force' => true,
            'reason' => 'Regression test availability normalization',
        ])->assertOk();

        $this->assertTrue($updateWasSent);
    }

    public function test_wp_profile_update_returns_location_hierarchy_errors_as_422_with_specific_message(): void
    {
        [$platform, $client] = $this->createLinkedClientFixture();

        Http::fake(function (ClientRequest $request) use ($client) {
            $url = (string) $request->url();

            if (str_ends_with($url, "/clients/{$client->wp_post_id}")) {
                return Http::response($this->wordpressClientPayload($client));
            }

            if (str_ends_with($url, '/locations')) {
                return Http::response([
                    'locations' => [
                        [
                            'id' => 10,
                            'name' => 'Nairobi',
                            'slug' => 'nairobi',
                            'cities' => [
                                ['id' => 11, 'name' => 'CBD', 'slug' => 'cbd'],
                            ],
                        ],
                    ],
                ]);
            }

            return Http::response(['message' => 'Unexpected request: '.$url], 500);
        });

        Sanctum::actingAs($this->adminUser());

        $this->patchJson("/api/crm/clients/{$client->id}/wp-profile", [
            'fields' => [
                'region_id' => 10,
                'city_id' => null,
            ],
            'force' => true,
            'reason' => 'Regression test validation surfacing',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Select a city within the selected region.')
            ->assertJsonPath('errors.city_id.0', 'Select a city within the selected region.');
    }

    public function test_wp_profile_location_update_allows_region_only_when_region_has_no_child_cities(): void
    {
        [$platform, $client] = $this->createLinkedClientFixture();
        $updateWasSent = false;

        Http::fake(function (ClientRequest $request) use ($client, &$updateWasSent) {
            $url = (string) $request->url();

            if (str_ends_with($url, "/clients/{$client->wp_post_id}")) {
                return Http::response($this->wordpressClientPayload($client));
            }

            if (str_ends_with($url, '/locations')) {
                return Http::response([
                    'locations' => [
                        [
                            'id' => 10,
                            'name' => 'Kisumu',
                            'slug' => 'kisumu',
                            'cities' => [],
                        ],
                    ],
                ]);
            }

            if (str_ends_with($url, "/clients/{$client->wp_post_id}/update")) {
                $updateWasSent = true;
                $this->assertSame([
                    'region_id' => 10,
                    'city_id' => null,
                ], $request->data()['fields'] ?? null);

                return Http::response([
                    'wp_post_id' => $client->wp_post_id,
                    'meta' => ['city' => 10],
                ]);
            }

            return Http::response(['message' => 'Unexpected request: '.$url], 500);
        });

        Sanctum::actingAs($this->adminUser());

        $this->patchJson("/api/crm/clients/{$client->id}/wp-profile", [
            'fields' => [
                'region_id' => 10,
                'city_id' => null,
            ],
            'force' => true,
            'reason' => 'Regression test region-only location update',
        ])->assertOk();

        $this->assertTrue($updateWasSent);
    }

    public function test_show_payload_contains_short_url_permalink_slug_and_canonical_expiry_context(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 6, 12, 0, 0, 'Africa/Nairobi'));

        try {
            $platform = Platform::factory()->create([
                'name' => 'Kenya',
                'domain' => 'kenya.example.test',
                'country' => 'Kenya',
                'phone_prefix' => '254',
                'currency_code' => 'KES',
                'timezone' => 'Africa/Nairobi',
                'wp_api_url' => 'https://kenya.example.test/wp-json/exotic-crm-sync/v1',
            ]);
            $product = Product::factory()->create([
                'platform_id' => $platform->id,
                'name' => 'VIP Profile',
                'display_name' => 'VIP Profile',
                'slug' => 'vip-profile',
                'tier' => 'vip',
            ]);
            $client = Client::factory()->create([
                'platform_id' => $platform->id,
                'wp_post_id' => 10026,
                'wp_user_id' => 34647,
                'wp_profile_permalink' => 'https://kenya.example.test/escort/faithvideossquirtingnudes/',
                'wp_profile_slug' => 'faithvideossquirtingnudes',
                'escort_expire' => now()->addDays(20)->timestamp,
            ]);
            $dealExpiry = now()->addDays(7)->startOfSecond();

            Deal::factory()->create([
                'platform_id' => $platform->id,
                'client_id' => $client->id,
                'product_id' => $product->id,
                'plan_type' => 'vip',
                'status' => 'active',
                'expires_at' => $dealExpiry,
            ]);

            Sanctum::actingAs(User::factory()->create([
                'role' => 'admin',
                'status' => 'active',
                'assigned_market_ids' => [],
            ]));

            $response = $this->getJson("/api/crm/clients/{$client->id}");

            $response->assertOk()
                ->assertJsonPath('wp_profile_url', 'https://kenya.example.test/?p=10026')
                ->assertJsonPath('wp_profile_permalink', 'https://kenya.example.test/escort/faithvideossquirtingnudes/')
                ->assertJsonPath('wp_profile_slug', 'faithvideossquirtingnudes');

            $this->assertNotEmpty($response->json('active_deal.expires_at'));
        } finally {
            Carbon::setTestNow();
        }
    }

    private function createLinkedClientFixture(): array
    {
        $platform = Platform::factory()->create([
            'name' => 'Rwanda',
            'domain' => 'rwanda.example.test',
            'country' => 'Rwanda',
            'phone_prefix' => '250',
            'currency_code' => 'RWF',
            'timezone' => 'Africa/Kigali',
            'wp_api_url' => 'https://rwanda.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'crm-password',
        ]);

        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 24694,
            'wp_user_id' => 34747,
            'name' => 'Rwanda Client',
            'phone_normalized' => '250788123456',
            'email' => 'client@example.test',
            'profile_status' => 'publish',
            'last_synced_at' => now(),
        ]);

        return [$platform, $client];
    }

    private function wordpressClientPayload(Client $client, array $overrides = []): array
    {
        return array_replace_recursive([
            'wp_post_id' => (int) $client->wp_post_id,
            'wp_user_id' => (int) $client->wp_user_id,
            'name' => $client->name,
            'phone' => $client->phone_normalized,
            'email' => $client->email,
            'post_status' => $client->profile_status,
            'modified_at' => now()->subMinute()->toIso8601String(),
            'wp_profile_permalink' => 'https://rwanda.example.test/escort/rwanda-client/',
            'wp_profile_slug' => 'rwanda-client',
            'main_image_url' => null,
            'taxonomies' => [
                'region' => null,
                'city' => null,
            ],
            'meta' => [
                'currency' => 50,
                'city' => null,
                'country' => null,
            ],
        ], $overrides);
    }

    private function adminUser(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'assigned_market_ids' => [],
        ]);
    }
}
