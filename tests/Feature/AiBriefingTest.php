<?php

namespace Tests\Feature;

use App\Jobs\RunAiBriefingJob;
use App\Models\AgentSession;
use App\Models\AiInteraction;
use App\Models\AuditLog;
use App\Models\Briefing;
use App\Models\BriefingRecipient;
use App\Models\BriefingRun;
use App\Models\Client;
use App\Models\ClientActiveSnapshot;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\SmsLog;
use App\Models\User;
use App\Services\Ai\AiBriefingSettingsService;
use App\Services\Ai\BriefingService;
use App\Services\Ai\GsmSmsLimiter;
use App\Services\NotificationService;
use App\Services\Seo\Llm\Adapters\DeepSeekAdapter;
use App\Services\Seo\Llm\LlmClient;
use App\Services\Seo\Llm\LlmResponse;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiBriefingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.providers.force_provider' => null]);
        config(['services.seo_engine.providers' => ['deepseek']]);
        config(['ai.briefings.enabled' => true]);
        config(['ai.briefings.base_url' => 'https://crm.test']);
    }

    public function test_dry_run_sends_nothing_and_persists_no_state(): void
    {
        $this->bindAiJson();
        [$platform, $product] = $this->market();
        $this->payment($platform, $product, ['amount' => 500, 'completed_at' => $this->lastWeek()]);
        $ceoUser = $this->user(['role' => 'admin', 'is_ceo' => true]);
        $this->saveRecipients([
            ['user_id' => $ceoUser->id, 'audience' => 'ceo', 'phone' => '254700000001'],
        ]);

        $result = app(BriefingService::class)->run('ceo', true);

        $this->assertSame('completed', $result['status']);
        $this->assertTrue($result['dry_run']);
        $this->assertNotEmpty($result['briefings'][0]['recipients'][0]['sms_text']);

        $this->assertSame(0, BriefingRun::count());
        $this->assertSame(0, Briefing::count());
        $this->assertSame(0, BriefingRecipient::count());
        $this->assertSame(0, SmsLog::count());
    }

    public function test_real_run_creates_run_briefing_recipient_and_sms_log(): void
    {
        $this->bindAiJson();
        [$platform, $product] = $this->market();
        $this->payment($platform, $product, ['amount' => 750, 'completed_at' => $this->lastWeek()]);
        $ceoUser = $this->user(['role' => 'admin', 'is_ceo' => true]);
        $this->saveRecipients([
            ['user_id' => $ceoUser->id, 'audience' => 'ceo', 'phone' => '254700000001'],
        ]);

        $result = app(BriefingService::class)->run('ceo', false);

        $this->assertSame('completed', $result['status']);
        $this->assertSame(1, BriefingRun::count());
        $this->assertSame(1, Briefing::count());
        $this->assertSame(1, BriefingRecipient::count());
        $this->assertSame(1, SmsLog::count());

        $recipient = BriefingRecipient::first();
        $this->assertNotNull($recipient->share_token);
        $this->assertNotNull($recipient->expires_at);
        $this->assertNotNull($recipient->sms_log_id);

        $log = SmsLog::first();
        $this->assertNull($log->payment_id);
        $this->assertSame($recipient->id, (int) $log->briefing_recipient_id);
    }

    public function test_multiple_sales_scopes_in_same_week_do_not_collide(): void
    {
        $this->bindAiJson();
        [$a] = $this->market();
        [$b] = $this->market();

        $repA = $this->user(['role' => 'sales', 'assigned_market_ids' => [$a->id]]);
        $repB = $this->user(['role' => 'sales', 'assigned_market_ids' => [$b->id]]);
        $this->saveRecipients([
            ['user_id' => $repA->id, 'audience' => 'sales', 'phone' => '254700000002'],
            ['user_id' => $repB->id, 'audience' => 'sales', 'phone' => '254700000003'],
        ]);

        $result = app(BriefingService::class)->run('sales', false);

        $this->assertSame('completed', $result['status']);
        $this->assertSame(2, Briefing::count(), 'Distinct sales scopes must yield distinct briefings.');
        $this->assertSame(2, BriefingRecipient::count());
    }

    public function test_dedupe_prevents_true_duplicate_for_same_scope_and_week(): void
    {
        $this->bindAiJson();
        $this->market();
        $ceoUser = $this->user(['role' => 'admin', 'is_ceo' => true]);
        $this->saveRecipients([
            ['user_id' => $ceoUser->id, 'audience' => 'ceo', 'phone' => '254700000001'],
        ]);

        app(BriefingService::class)->run('ceo', false);
        app(BriefingService::class)->run('ceo', false);

        $this->assertSame(1, Briefing::count(), 'Re-running the same week/scope must reuse the deduped briefing.');
        $this->assertSame(2, BriefingRun::count());
    }

    public function test_disabled_flag_blocks_ai_calls_and_sms(): void
    {
        config(['ai.briefings.enabled' => false]);
        $this->bindAiJson();
        $this->market();
        $ceoUser = $this->user(['role' => 'admin', 'is_ceo' => true]);
        $this->saveRecipients([
            ['user_id' => $ceoUser->id, 'audience' => 'ceo', 'phone' => '254700000001'],
        ]);

        $result = app(BriefingService::class)->run('ceo', false);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('disabled', $result['reason']);
        $this->assertSame(0, AiInteraction::count());
        $this->assertSame(0, SmsLog::count());
        $this->assertSame(0, BriefingRun::count());
    }

    public function test_opted_out_recipient_is_excluded(): void
    {
        $this->bindAiJson();
        $this->market();
        $optedOut = $this->user(['role' => 'admin', 'is_ceo' => true]);
        $this->saveRecipients([
            ['user_id' => $optedOut->id, 'audience' => 'ceo', 'phone' => '254700000001', 'opt_out' => true],
        ]);

        $result = app(BriefingService::class)->run('ceo', false);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('no_recipients', $result['reason']);
        $this->assertSame(0, BriefingRecipient::count());
    }

    public function test_template_fallback_when_providers_fail(): void
    {
        config(['services.seo_engine.providers' => []]); // no adapters -> waterfall fails
        $this->market();
        $ceoUser = $this->user(['role' => 'admin', 'is_ceo' => true]);
        $this->saveRecipients([
            ['user_id' => $ceoUser->id, 'audience' => 'ceo', 'phone' => '254700000001'],
        ]);

        $result = app(BriefingService::class)->run('ceo', false);

        $this->assertSame('completed', $result['status']);
        $this->assertFalse($result['briefings'][0]['used_ai']);
        $this->assertSame(1, Briefing::count());
        $this->assertNotEmpty($result['briefings'][0]['sms_digest']);
    }

    public function test_ceo_briefing_routes_sms_by_recipient_phone_prefix_when_scope_is_org_wide(): void
    {
        $this->bindAiJson();
        Http::fake([
            'sms-gateway.example.test/*' => Http::response('OK', 200),
            'karibu.briq.tz/*' => Http::response(['success' => true, 'job_id' => 'wrong-route'], 200),
        ]);

        [$kenya, $product] = $this->market(['phone_prefix' => '254', 'currency_code' => 'KES']);
        $this->payment($kenya, $product, ['amount' => 750, 'currency' => 'KES', 'completed_at' => $this->lastWeek()]);

        app(NotificationService::class)->saveSmsConfig([
            'enabled' => true,
            'active_provider' => 'briq',
            'fallback_provider' => 'none',
            'briq' => [
                'base_url' => 'https://karibu.briq.tz',
                'api_key' => 'global-key',
                'sender_id' => 'TZ',
            ],
            'markets' => [
                (string) $kenya->id => [
                    'active_provider' => 'legacy_gateway',
                    'legacy_gateway' => [
                        'gateway_url' => 'https://sms-gateway.example.test/send',
                        'org_code' => '76',
                    ],
                ],
            ],
        ]);

        $ceoUser = $this->user(['role' => 'admin', 'is_ceo' => true]);
        $this->saveRecipients([
            ['user_id' => $ceoUser->id, 'audience' => 'ceo', 'phone' => '+254769912227'],
        ]);

        $result = app(BriefingService::class)->run('ceo', false);

        $this->assertSame('completed', $result['status']);
        $this->assertSame('sent', $result['briefings'][0]['recipients'][0]['delivery_status']);
        $this->assertDatabaseHas('sms_logs', [
            'provider' => 'legacy_gateway',
            'platform_id' => $kenya->id,
            'status' => 'sent',
            'purpose' => 'ai_briefing',
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sms-gateway.example.test'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'karibu.briq.tz'));
    }

    public function test_period_is_previous_nairobi_week(): void
    {
        $this->bindAiJson();
        $this->market();
        $ceoUser = $this->user(['role' => 'admin', 'is_ceo' => true]);
        $this->saveRecipients([
            ['user_id' => $ceoUser->id, 'audience' => 'ceo', 'phone' => '254700000001'],
        ]);

        // Anchor on Wed 2026-05-27; previous week = Mon 2026-05-18 .. Sun 2026-05-24.
        $result = app(BriefingService::class)->run('ceo', true, Carbon::parse('2026-05-27 10:00:00'));

        $this->assertSame('2026-05-18', $result['period']['from']);
        $this->assertSame('2026-05-24', $result['period']['to']);
        $this->assertSame('Africa/Nairobi', $result['period']['timezone']);
    }

    public function test_briefing_body_uses_executive_scorecard_v3_snapshot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-21 12:00:00', 'Africa/Nairobi'));
        $this->bindAiJson();
        [$kenya, $product] = $this->market(['name' => 'Kenya', 'currency_code' => 'USD']);
        [$ghana] = $this->market(['name' => 'Ghana', 'currency_code' => 'USD']);
        $ceoUser = $this->user(['role' => 'admin', 'is_ceo' => true]);
        $this->saveRecipients([
            ['user_id' => $ceoUser->id, 'audience' => 'ceo', 'phone' => '254700000001'],
        ]);

        $currentStart = Carbon::parse('2026-08-10 10:00:00', 'Africa/Nairobi')->utc();
        $priorStart = Carbon::parse('2026-08-03 10:00:00', 'Africa/Nairobi')->utc();

        $newClient = Client::factory()->create([
            'platform_id' => $kenya->id,
            'created_at' => $currentStart->copy(),
        ]);
        $expiredClient = Client::factory()->create([
            'platform_id' => $kenya->id,
            'lifecycle_expired_at' => $currentStart->copy()->addDay(),
        ]);
        $renewalDeal = Deal::factory()->create([
            'platform_id' => $kenya->id,
            'client_id' => $newClient->id,
            'product_id' => $product->id,
            'expires_at' => $currentStart->copy()->addDays(2),
            'subscription_lifecycle' => 'renewal',
        ]);

        $this->payment($kenya, $product, [
            'client_id' => $newClient->id,
            'amount' => 1000,
            'completed_at' => $currentStart->copy(),
            'phone' => '254700000010',
        ]);
        $this->payment($ghana, $product, [
            'amount' => 800,
            'completed_at' => $currentStart->copy()->addDay(),
            'phone' => '233700000010',
        ]);
        $this->payment($kenya, $product, [
            'client_id' => $newClient->id,
            'deal_id' => $renewalDeal->id,
            'amount' => 500,
            'completed_at' => $currentStart->copy()->addDays(2),
            'subscription_lifecycle' => 'renewal',
            'phone' => '254700000011',
        ]);
        $this->payment($kenya, $product, [
            'amount' => 300,
            'completed_at' => $priorStart->copy(),
            'phone' => '254700000012',
        ]);
        $this->payment($ghana, $product, [
            'amount' => 1200,
            'completed_at' => $priorStart->copy()->addDay(),
            'phone' => '233700000012',
        ]);
        $this->payment($kenya, $product, [
            'status' => 'failed',
            'amount' => 200,
            'created_at' => $currentStart->copy()->addHours(1),
            'completed_at' => null,
            'phone' => '254700000013',
        ]);
        $this->payment($kenya, $product, [
            'amount' => 200,
            'completed_at' => $currentStart->copy()->addHours(3),
            'phone' => '254700000013',
        ]);

        AgentSession::query()->create([
            'user_id' => $ceoUser->id,
            'session_token' => (string) Str::uuid(),
            'started_at' => $currentStart->copy(),
            'last_heartbeat_at' => $currentStart->copy()->addHours(2),
            'ended_at' => $currentStart->copy()->addHours(2),
        ]);
        AuditLog::query()->create([
            'platform_id' => $kenya->id,
            'actor_id' => $ceoUser->id,
            'action' => 'briefing_fixture',
            'entity_type' => 'client',
            'entity_id' => $expiredClient->id,
            'created_at' => $currentStart->copy()->addHour(),
        ]);

        $result = app(BriefingService::class)->run('ceo', false);
        $body = $result['briefings'][0]['full_body'];

        $this->assertSame('executive_scorecard_v3', $body['version']);
        $this->assertSame('Week 33', $body['period']['label']);
        $this->assertSame('10-16 Aug 2026', $body['period']['display']);
        $this->assertNotEmpty($body['scorecards']);
        $this->assertArrayHasKey('market_movement', $body);
        $this->assertArrayHasKey('customer_movement', $body);
        $this->assertArrayHasKey('payment_recovery', $body);
        $this->assertArrayHasKey('team_execution', $body);
        $this->assertSame(1, $body['customer_movement']['expired_profiles']);
        $this->assertGreaterThanOrEqual(50.0, $body['payment_recovery']['payment_recovery_rate']);

        $recipient = BriefingRecipient::first();
        Sanctum::actingAs($ceoUser);
        $this->getJson('/api/crm/briefings/shared/'.$recipient->share_token)
            ->assertOk()
            ->assertJsonPath('period.label', 'Week 33')
            ->assertJsonPath('body.version', 'executive_scorecard_v3');

        Carbon::setTestNow();
    }

    public function test_payment_recovery_money_stays_null_when_fx_is_partial(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-21 12:00:00', 'Africa/Nairobi'));
        $this->bindAiJson();
        [$senegal, $product] = $this->market([
            'name' => 'Senegal',
            'country' => 'Senegal',
            'currency_code' => 'CFA',
        ]);
        $ceoUser = $this->user(['role' => 'admin', 'is_ceo' => true]);
        $this->saveRecipients([
            ['user_id' => $ceoUser->id, 'audience' => 'ceo', 'phone' => '221700000001'],
        ]);

        $failedAt = Carbon::parse('2026-08-11 08:00:00', 'Africa/Nairobi')->utc();
        $this->payment($senegal, $product, [
            'status' => 'failed',
            'amount' => 10000,
            'currency' => 'CFA',
            'created_at' => $failedAt,
            'completed_at' => null,
            'phone' => '221700000002',
        ]);
        $this->payment($senegal, $product, [
            'amount' => 10000,
            'currency' => 'CFA',
            'created_at' => $failedAt->copy()->addHour(),
            'completed_at' => $failedAt->copy()->addHour(),
            'phone' => '221700000002',
        ]);

        $result = app(BriefingService::class)->run('ceo', false);
        $recovery = $result['briefings'][0]['full_body']['payment_recovery'];

        $this->assertSame(1, $recovery['recovered_payments']);
        $this->assertNull($recovery['recovered_value']);
        $this->assertSame(['CFA' => 10000.0], $recovery['recovered_value_breakdown']);
        $this->assertTrue($recovery['normalization_partial']);

        Carbon::setTestNow();
    }

    public function test_gsm_limiter_counts_extension_chars_as_two_units(): void
    {
        $limiter = app(GsmSmsLimiter::class);

        $this->assertSame(1, $limiter->units('a'));
        $this->assertSame(2, $limiter->units('€'));   // extension char
        $this->assertSame(3, $limiter->units('a€'));

        $link = 'https://crm.test/b/'.Str::random(22);
        $fit = $limiter->fitWithLink(str_repeat('A', 400), $link);
        $this->assertLessThanOrEqual(GsmSmsLimiter::SEGMENT_UNITS, $fit['char_count']);
        $this->assertSame(1, $fit['segments']);
        $this->assertStringContainsString($link, $fit['text']);
    }

    public function test_recipient_can_open_their_own_briefing_link(): void
    {
        $recipient = $this->seedBriefingRecipient();
        Sanctum::actingAs(User::find($recipient->user_id));

        $this->getJson('/api/crm/briefings/shared/'.$recipient->share_token)
            ->assertOk()
            ->assertJsonPath('audience', 'ceo')
            ->assertJsonStructure(['body' => ['headline'], 'period', 'scope']);

        $this->assertNotNull($recipient->fresh()->opened_at);
    }

    public function test_non_recipient_is_denied(): void
    {
        $recipient = $this->seedBriefingRecipient();
        $stranger = $this->user(['role' => 'sales', 'is_ceo' => false]);
        Sanctum::actingAs($stranger);

        $this->getJson('/api/crm/briefings/shared/'.$recipient->share_token)
            ->assertForbidden();
    }

    public function test_admin_ceo_override_can_open_any_link(): void
    {
        config(['ai.briefings.admin_override' => true]);
        $recipient = $this->seedBriefingRecipient();
        $ceo = $this->user(['role' => 'admin', 'is_ceo' => true]);
        Sanctum::actingAs($ceo);

        $this->getJson('/api/crm/briefings/shared/'.$recipient->share_token)
            ->assertOk();
    }

    public function test_expired_link_returns_gone(): void
    {
        $recipient = $this->seedBriefingRecipient(['expires_at' => now()->subDay()]);
        Sanctum::actingAs(User::find($recipient->user_id));

        $this->getJson('/api/crm/briefings/shared/'.$recipient->share_token)
            ->assertStatus(410);
    }

    public function test_unknown_token_returns_not_found(): void
    {
        Sanctum::actingAs($this->user(['role' => 'admin', 'is_ceo' => true]));

        $this->getJson('/api/crm/briefings/shared/'.Str::random(32))
            ->assertNotFound();
    }

    public function test_settings_endpoint_returns_config_and_saves_recipients(): void
    {
        $admin = $this->user(['role' => 'admin', 'is_ceo' => false]);
        $target = $this->user(['role' => 'sales']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/crm/settings/ai')
            ->assertOk()
            ->assertJsonStructure(['briefings', 'insights', 'recipients', 'users', 'platforms', 'recent_runs']);

        $this->putJson('/api/crm/settings/ai/recipients', [
            'recipients' => [
                ['user_id' => $target->id, 'audience' => 'sales', 'phone' => '254700000009'],
            ],
        ])->assertOk()->assertJsonCount(1, 'recipients');
    }

    public function test_live_send_endpoint_requires_confirmation(): void
    {
        Sanctum::actingAs($this->user(['role' => 'admin']));

        $this->postJson('/api/crm/settings/ai/briefings/send', [
            'audience' => 'ceo',
        ])->assertStatus(422);
    }

    public function test_live_send_endpoint_schedules_future_job(): void
    {
        Queue::fake();
        $admin = $this->user(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $sendAt = Carbon::now('Africa/Nairobi')->addMinutes(10)->format('Y-m-d\TH:i');

        $this->postJson('/api/crm/settings/ai/briefings/send', [
            'audience' => 'ceo',
            'send_at' => $sendAt,
            'confirm_live' => true,
        ])
            ->assertAccepted()
            ->assertJsonPath('status', 'scheduled')
            ->assertJsonPath('audience', 'ceo');

        Queue::assertPushed(RunAiBriefingJob::class, function (RunAiBriefingJob $job) use ($admin): bool {
            return $job->audience === 'ceo' && $job->triggeredBy === $admin->id;
        });
    }

    // --- helpers -----------------------------------------------------------

    private function lastWeek(): Carbon
    {
        return Carbon::now('Africa/Nairobi')->startOfWeek(Carbon::MONDAY)->subWeek()->addDay()->setTimezone('UTC');
    }

    /** @return array{0:Platform,1:Product} */
    private function market(array $platformOverrides = []): array
    {
        $platform = Platform::factory()->create(array_merge(['currency_code' => 'USD'], $platformOverrides));
        $product = Product::factory()->create(['platform_id' => $platform->id, 'currency' => 'USD']);
        ClientActiveSnapshot::query()->create([
            'date' => now()->toDateString(),
            'platform_id' => $platform->id,
            'count' => 10,
        ]);

        return [$platform, $product];
    }

    private function saveRecipients(array $recipients): void
    {
        app(AiBriefingSettingsService::class)->saveRecipients($recipients, null);
    }

    private function seedBriefingRecipient(array $recipientOverrides = []): BriefingRecipient
    {
        $user = $this->user(['role' => 'sales', 'is_ceo' => false]);
        $run = BriefingRun::create([
            'audience' => 'ceo',
            'period' => 'weekly',
            'period_start' => now()->subWeek(),
            'period_end' => now(),
            'dry_run' => false,
            'status' => 'completed',
            'cost_usd' => 0,
        ]);
        $briefing = Briefing::create([
            'briefing_run_id' => $run->id,
            'audience' => 'ceo',
            'scope_platform_ids' => null,
            'scope_hash' => Briefing::scopeHashFor(null),
            'period' => 'weekly',
            'period_start' => now()->subWeek(),
            'period_end' => now(),
            'summary_sms' => 'Weekly digest',
            'body_full' => json_encode(['headline' => 'Weekly briefing', 'highlights' => ['x']]),
        ]);

        return BriefingRecipient::create(array_merge([
            'briefing_id' => $briefing->id,
            'user_id' => $user->id,
            'audience' => 'ceo',
            'share_token' => Str::random(32),
            'expires_at' => now()->addDays(14),
            'sms_text' => 'Weekly digest https://crm.test/b/x',
            'delivery_status' => 'sent',
            'opt_out_snapshot' => false,
        ], $recipientOverrides));
    }

    private function bindAiJson(): void
    {
        $json = json_encode([
            'sms_digest' => 'Weekly: revenue up, all good.',
            'full_body' => [
                'headline' => 'Strong week',
                'highlights' => ['Revenue up'],
                'watch_items' => [],
                'narrative' => 'A solid week overall.',
            ],
        ]);

        $this->app->bind(DeepSeekAdapter::class, fn () => new class($json) implements LlmClient
        {
            public function __construct(private string $json) {}

            public function name(): string
            {
                return 'deepseek';
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function generate(string $system, string $user, array $opts = []): LlmResponse
            {
                return new LlmResponse(text: $this->json, inputTokens: 100, outputTokens: 200);
            }
        });
    }

    private function payment(Platform $platform, Product $product, array $overrides = []): Payment
    {
        $payment = Payment::factory()->make(array_merge([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'currency' => 'USD',
            'purpose' => 'subscription',
            'provider_environment' => null,
            'record_classification' => Payment::RECORD_CLASSIFICATION_LIVE,
            'reconciliation_state' => 'open',
            'resolution_code' => null,
            'source' => 'gateway',
            'status' => 'completed',
        ], $overrides));
        $payment->save();

        return $payment;
    }

    private function user(array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'name' => 'Test User',
            'email' => Str::uuid().'@example.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'active',
            'is_ceo' => false,
            'assigned_market_ids' => [],
        ], $overrides));
    }
}
