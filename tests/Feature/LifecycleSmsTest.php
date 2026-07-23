<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Template;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\LifecycleSmsService;
use App\Services\LifecycleSmsSettingsService;
use App\Services\NotificationService;
use App\Services\PaymentLinkService;
use App\Services\SubscriptionProvisioningService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LifecycleSmsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 12:00 Nairobi — clear of the 20:00–08:00 quiet-hours gate.
        Carbon::setTestNow(Carbon::parse('2026-07-23 09:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function settings(): LifecycleSmsSettingsService
    {
        return app(LifecycleSmsSettingsService::class);
    }

    private function service(): LifecycleSmsService
    {
        return app(LifecycleSmsService::class);
    }

    private function admin(): User
    {
        return User::query()->create([
            'name' => 'Admin User',
            'email' => 'admin' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function onboardingTemplate(): Template
    {
        return Template::query()->create([
            'platform_id' => null,
            'title' => 'Lifecycle welcome test',
            'category' => 'welcome',
            'channel' => 'sms',
            'body' => 'Hi {{first_name}}! Activate {{plan_name}} here: {{payment_link}}',
            'variables' => [],
            'status' => 'active',
            'is_quick_reply' => false,
        ]);
    }

    private function recoveryTemplate(): Template
    {
        return Template::query()->create([
            'platform_id' => null,
            'title' => 'Lifecycle recovery test',
            'category' => 'payment',
            'channel' => 'sms',
            'body' => 'Hi {{first_name}}, finish your payment: {{payment_link}}',
            'variables' => [],
            'status' => 'active',
            'is_quick_reply' => false,
        ]);
    }

    /** @return array{0: Platform, 1: Product, 2: ProductPrice} */
    private function marketWithOffer(array $flowOverrides = [], array $marketOverrides = []): array
    {
        $platform = Platform::factory()->create();
        $product = Product::factory()->create(['platform_id' => $platform->id, 'name' => 'PREMIUM', 'tier' => 'premium']);
        $price = ProductPrice::factory()->create([
            'product_id' => $product->id,
            'duration_key' => '1_week',
            'duration_days' => 7,
            'price' => 1000,
            'currency' => 'KES',
            'is_active' => true,
        ]);

        $this->settings()->saveConfig([
            'enabled' => true,
            'markets' => [
                (string) $platform->id => array_merge([
                    'sms_enabled' => true,
                    'onboarding' => array_merge([
                        'enabled' => true,
                        'product_id' => $product->id,
                        'product_price_id' => $price->id,
                        'free_trial_enabled' => true,
                        'free_trial_days' => 2,
                    ], $flowOverrides),
                    'recovery' => ['enabled' => true],
                    'reactivation' => ['enabled' => true, 'product_id' => $product->id, 'product_price_id' => $price->id],
                ], $marketOverrides),
            ],
        ]);

        return [$platform, $product, $price];
    }

    private function fakeTokenizedLinks(): void
    {
        $this->partialMock(PaymentLinkService::class, function ($mock) {
            $mock->shouldReceive('hasTokenizedProvider')->andReturn(true);
            $mock->shouldReceive('prepareTokenizedUrl')->andReturn([
                'success' => true,
                'payment_url' => 'https://market.test/api/payments/link/TESTTOKEN',
                'provider' => 'pawapay',
                'mode' => PaymentLinkService::MODE_PROXY_HOSTED_CHECKOUT,
            ]);
        });
    }

    private function fakeSmsDelivery(): void
    {
        $this->partialMock(NotificationService::class, function ($mock) {
            $mock->shouldReceive('sendSmsToClient')->andReturn([
                'success' => true,
                'status' => 'sent',
                'provider' => 'test_provider',
                'provider_response' => 'OK',
            ]);
        });
    }

    // -----------------------------------------------------------------
    // Config + gates
    // -----------------------------------------------------------------

    public function test_everything_is_disabled_by_default(): void
    {
        $platform = Platform::factory()->create();

        $this->assertFalse($this->settings()->globalEnabled());
        foreach (LifecycleSmsSettingsService::FLOWS as $flow) {
            $this->assertFalse($this->settings()->flowEnabled((int) $platform->id, $flow), "flow {$flow} should be off");
        }
    }

    public function test_partial_save_preserves_sibling_market_config(): void
    {
        $platform = Platform::factory()->create();

        $this->settings()->saveConfig([
            'enabled' => true,
            'markets' => [
                (string) $platform->id => [
                    'sms_enabled' => true,
                    'onboarding' => ['enabled' => true, 'free_trial_days' => 3],
                ],
            ],
        ]);

        // Second save touches only recovery — onboarding must survive.
        $this->settings()->saveConfig([
            'markets' => [
                (string) $platform->id => [
                    'sms_enabled' => true,
                    'recovery' => ['enabled' => true],
                ],
            ],
        ]);

        $market = $this->settings()->marketConfig((int) $platform->id);
        $this->assertTrue((bool) $market['onboarding']['enabled']);
        $this->assertSame(3, (int) $market['onboarding']['free_trial_days']);
        $this->assertTrue((bool) $market['recovery']['enabled']);
    }

    public function test_send_skips_when_globally_disabled(): void
    {
        $client = Client::factory()->create(['signup_source' => 'fast_signup']);

        $result = $this->service()->send('onboarding', $client);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('disabled_global', $result['skip_reason']);
    }

    public function test_send_skips_when_market_disabled(): void
    {
        $this->settings()->saveConfig(['enabled' => true]);
        $client = Client::factory()->create(['signup_source' => 'fast_signup']);

        $result = $this->service()->send('onboarding', $client);

        $this->assertSame('market_sms_disabled', $result['skip_reason']);
    }

    public function test_send_skips_market_without_tokenized_psp(): void
    {
        [$platform] = $this->marketWithOffer();
        $this->onboardingTemplate();
        $client = Client::factory()->create(['platform_id' => $platform->id, 'signup_source' => 'fast_signup']);

        // No payment_link_providers configured on the platform → tokenized-only
        // policy skips the send with market_no_psp, minting nothing.
        $result = $this->service()->send('onboarding', $client);

        $this->assertSame('market_no_psp', $result['skip_reason']);
        $this->assertSame(0, Deal::query()->count());
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_onboarding_excludes_other_signup_sources(): void
    {
        [$platform] = $this->marketWithOffer();
        $this->fakeTokenizedLinks();
        $client = Client::factory()->create(['platform_id' => $platform->id, 'signup_source' => 'crm_manual']);

        $result = $this->service()->send('onboarding', $client);

        $this->assertSame('signup_source_excluded', $result['skip_reason']);
    }

    public function test_onboarding_skips_client_who_already_converted(): void
    {
        [$platform] = $this->marketWithOffer();
        $this->fakeTokenizedLinks();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'signup_source' => 'fast_signup',
            'escort_expire' => now()->addDays(5)->timestamp,
        ]);

        $result = $this->service()->send('onboarding', $client);

        $this->assertSame('client_already_active', $result['skip_reason']);
    }

    public function test_quiet_hours_hold_automated_sends_but_not_manual(): void
    {
        // 23:00 Nairobi
        Carbon::setTestNow(Carbon::parse('2026-07-23 20:00:00', 'UTC'));

        [$platform] = $this->marketWithOffer();
        $this->fakeTokenizedLinks();
        $this->onboardingTemplate();
        $client = Client::factory()->create(['platform_id' => $platform->id, 'signup_source' => 'fast_signup']);

        $automated = $this->service()->evaluate('onboarding', $client, ['source' => 'automated']);
        $this->assertSame('quiet_hours', $automated['skip_reason'] ?? null);

        $manual = $this->service()->evaluate('onboarding', $client, ['source' => 'manual']);
        $this->assertSame('ok', $manual['status']);
    }

    // -----------------------------------------------------------------
    // Recovery exclusions (manual / test / sibling state gate)
    // -----------------------------------------------------------------

    public function test_recovery_excludes_manual_payments(): void
    {
        [$platform] = $this->marketWithOffer();
        $this->fakeTokenizedLinks();
        $this->recoveryTemplate();
        $client = Client::factory()->create(['platform_id' => $platform->id]);

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'status' => 'failed',
            'provider_key' => 'manual_confirmation',
            'reconciliation_state' => 'open',
        ]);

        $result = $this->service()->send('recovery', $client->fresh(), ['payment' => $payment]);
        $this->assertSame('manual_payment', $result['skip_reason']);

        $reviewPayment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'status' => 'failed',
            'provider_key' => 'pawapay',
            'reconciliation_state' => 'manual_review',
        ]);

        $result = $this->service()->send('recovery', $client->fresh(), ['payment' => $reviewPayment]);
        $this->assertSame('manual_payment', $result['skip_reason']);
    }

    public function test_recovery_excludes_sandbox_payments(): void
    {
        [$platform] = $this->marketWithOffer();
        $this->fakeTokenizedLinks();
        $client = Client::factory()->create(['platform_id' => $platform->id]);

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'status' => 'failed',
            'provider_key' => 'pawapay',
            'provider_environment' => 'sandbox',
            'reconciliation_state' => 'open',
        ]);

        $result = $this->service()->send('recovery', $client->fresh(), ['payment' => $payment]);
        $this->assertSame('test_payment', $result['skip_reason']);
    }

    public function test_recovery_sibling_gate_skips_when_client_converted_after_failure(): void
    {
        [$platform] = $this->marketWithOffer();
        $this->fakeTokenizedLinks();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            // Client paid via a fresh link after the failure — escort_expire is
            // in the future while the failed Payment row stays 'failed' forever.
            'escort_expire' => now()->addDays(6)->timestamp,
        ]);

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'status' => 'failed',
            'provider_key' => 'pawapay',
            'reconciliation_state' => 'open',
        ]);

        $result = $this->service()->send('recovery', $client->fresh(), ['payment' => $payment]);
        $this->assertSame('client_already_active', $result['skip_reason']);
    }

    // -----------------------------------------------------------------
    // Sending, dedup, pro-forma bonus days
    // -----------------------------------------------------------------

    public function test_onboarding_send_mints_bonus_inflated_deal_and_clean_payment(): void
    {
        [$platform, $product] = $this->marketWithOffer();
        $this->fakeTokenizedLinks();
        $this->fakeSmsDelivery();
        $this->onboardingTemplate();
        $this->admin();

        $client = Client::factory()->create(['platform_id' => $platform->id, 'signup_source' => 'fast_signup']);

        $result = $this->service()->send('onboarding', $client);

        $this->assertSame('sent', $result['status'], json_encode($result));

        $deal = Deal::query()->firstOrFail();
        $this->assertSame('pending', $deal->status);
        $this->assertSame($product->id, (int) $deal->product_id);
        // 7 plan days + 2 bonus days live ONLY on deal.duration_days.
        $this->assertSame(9, (int) $deal->duration_days);

        $payment = Payment::query()->where('deal_id', $deal->id)->firstOrFail();
        $this->assertSame('initiated', $payment->status);
        $this->assertNull(data_get($payment->payment_data, 'duration_days'));
        $this->assertSame('crm_lifecycle', data_get($payment->raw_payload, 'source'));

        // Telemetry: timeline event + dedup key recorded.
        $this->assertSame(1, TimelineEvent::query()
            ->where('entity_type', 'client')
            ->where('entity_id', $client->id)
            ->where('event_type', LifecycleSmsService::TIMELINE_EVENT_TYPE)
            ->count());

        // Second send for the same trigger is deduped and does NOT re-mint.
        $second = $this->service()->send('onboarding', $client->fresh());
        $this->assertSame('already_sent', $second['skip_reason']);
        $this->assertSame(1, Deal::query()->count());
        $this->assertSame(1, Payment::query()->count());
    }

    public function test_bonus_days_flow_through_provisioning_end_to_end(): void
    {
        [$platform] = $this->marketWithOffer();
        $this->fakeTokenizedLinks();
        $this->fakeSmsDelivery();
        $this->onboardingTemplate();
        $this->admin();

        $client = Client::factory()->create(['platform_id' => $platform->id, 'signup_source' => 'fast_signup']);
        $result = $this->service()->send('onboarding', $client);
        $this->assertSame('sent', $result['status']);

        $payment = Payment::query()->firstOrFail();

        Http::fake([
            $platform->wp_api_url . '/clients/' . $client->wp_post_id . '/activate' => Http::response(['ok' => true], 200),
            $platform->wp_api_url . '/clients/' . $client->wp_post_id => Http::response([
                'wp_post_id' => $client->wp_post_id,
                'wp_user_id' => $client->wp_user_id,
                'name' => $client->name,
                'phone' => $client->phone_normalized,
                'post_status' => 'publish',
                'escort_expire' => now()->addDays(9)->timestamp,
            ], 200),
        ]);

        $payment->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

        $deal = app(SubscriptionProvisioningService::class)->provisionCompletedPayment(
            $payment->fresh(['client.platform', 'platform', 'product'])
        );

        // pay for 7, get 9: expiry = activation + plan_days + bonus_days.
        $this->assertSame('active', $deal->status);
        $this->assertSame(9, (int) $deal->duration_days);
        $this->assertSame(
            9,
            (int) round($deal->activated_at->diffInDays($deal->expires_at))
        );
    }

    public function test_reactivation_targets_respect_windows(): void
    {
        [$platform] = $this->marketWithOffer();

        $inWindow = Client::factory()->create([
            'platform_id' => $platform->id,
            'escort_expire' => now()->subDays(7)->timestamp,
        ]);
        Client::factory()->create([
            'platform_id' => $platform->id,
            'escort_expire' => now()->subDays(10)->timestamp,
        ]);

        $targets = $this->service()->reactivationTargets((int) $platform->id, [7])->get();

        $this->assertSame([$inWindow->id], $targets->pluck('id')->all());
        $this->assertSame(7, $this->service()->reactivationWindowFor($inWindow, [7, 30]));
    }

    public function test_renewal_link_variables_degrade_gracefully(): void
    {
        [$platform] = $this->marketWithOffer();
        $client = Client::factory()->create(['platform_id' => $platform->id]);
        $deal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'status' => 'active',
        ]);
        $deal->setRelation('client', $client);

        $plainTemplate = Template::query()->create([
            'platform_id' => null,
            'title' => 'Plain renewal',
            'category' => 'renewal',
            'channel' => 'sms',
            'body' => 'Hi {{client_name}}, renew now.',
            'variables' => [],
            'status' => 'active',
            'is_quick_reply' => false,
        ]);

        // No {{payment_link}} in the template → nothing to do (renewal suite
        // keeps its existing behaviour byte-for-byte).
        $this->assertSame([], $this->service()->renewalLinkVariables($deal, $plainTemplate));

        $linkTemplate = Template::query()->create([
            'platform_id' => null,
            'title' => 'Link renewal',
            'category' => 'renewal',
            'channel' => 'sms',
            'body' => 'Hi {{client_name}}, renew now to stay visible. {{payment_link}}',
            'variables' => [],
            'status' => 'active',
            'is_quick_reply' => false,
        ]);

        // Placeholder present but renewal links not enabled for this market →
        // degrade to an empty link (NOT a hard skip) so the reminder still
        // renders as a clean nudge, and no pro-forma deal/payment is minted.
        $vars = $this->service()->renewalLinkVariables($deal, $linkTemplate);
        $this->assertSame(['payment_link' => ''], $vars);
        $this->assertSame(1, Deal::query()->count());
        $this->assertSame(0, Payment::query()->count());

        // Rendering with the degraded link yields no missing variables and,
        // after the send path's rtrim, no dangling whitespace or token.
        $rendered = app(\App\Services\TemplateService::class)->renderTemplate(
            $linkTemplate,
            array_merge(
                app(\App\Services\TemplateService::class)->buildClientVariables($deal->client, $deal),
                $vars
            )
        );
        $this->assertSame([], $rendered['missing']);
        $this->assertStringNotContainsString('{{payment_link}}', $rendered['body']);
        $this->assertSame('Hi ' . $deal->client->name . ', renew now to stay visible.', rtrim($rendered['body']));
    }

    public function test_seeded_lifecycle_templates_resolve_and_render_without_missing_variables(): void
    {
        // Seed the real production template sets, then exercise the resolver +
        // render path end-to-end so a legacy template can never be silently
        // picked with unresolved variables (the transaction_reference /
        // profile_url failures seen in QA).
        $this->seed(\Database\Seeders\WelcomeTemplateSeeder::class);
        $this->seed(\Database\Seeders\PaymentTemplateSeeder::class);
        $this->seed(\Database\Seeders\SprintThreeTemplateSeeder::class);
        $this->seed(\Database\Seeders\LifecycleSmsTemplateSeeder::class);

        [$platform] = $this->marketWithOffer();
        $this->fakeTokenizedLinks();
        $this->fakeSmsDelivery();
        $this->admin();

        // Onboarding: resolver must prefer the link-bearing lifecycle template
        // over the legacy Welcome (profile_url/support_chat_url) template.
        $onboardingTemplate = $this->service()->resolveTemplate('onboarding', (int) $platform->id);
        $this->assertStringContainsString('payment_link', (string) $onboardingTemplate->body);

        $onboardingClient = Client::factory()->create(['platform_id' => $platform->id, 'signup_source' => 'fast_signup']);
        $result = $this->service()->send('onboarding', $onboardingClient);
        $this->assertSame('sent', $result['status'], json_encode($result));

        // Recovery: resolver must prefer the lifecycle recovery template over
        // the legacy Payment Confirmation (transaction_reference) template.
        $recoveryTemplate = $this->service()->resolveTemplate('recovery', (int) $platform->id);
        $this->assertStringContainsString('payment_link', (string) $recoveryTemplate->body);

        $recoveryClient = Client::factory()->create(['platform_id' => $platform->id]);
        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $recoveryClient->id,
            'status' => 'failed',
            'provider_key' => 'pawapay',
            'reconciliation_state' => 'open',
        ]);
        $recoveryResult = $this->service()->send('recovery', $recoveryClient->fresh(), ['payment' => $payment]);
        $this->assertSame('sent', $recoveryResult['status'], json_encode($recoveryResult));
    }

    public function test_campaign_renewal_templates_have_no_misleading_reply_copy(): void
    {
        $this->seed(\Database\Seeders\SprintThreeTemplateSeeder::class);

        $smsRenewalTemplates = Template::query()
            ->where('channel', 'sms')
            ->whereIn('category', ['renewal', 'win_back'])
            ->pluck('body');

        $this->assertNotEmpty($smsRenewalTemplates);
        foreach ($smsRenewalTemplates as $body) {
            $this->assertDoesNotMatchRegularExpression(
                '/reply\s+(to\s+)?(renew|reactivate|activate|now)/i',
                (string) $body,
                'Campaign SMS copy must not tell clients to reply to renew/activate.'
            );
        }
    }

    public function test_default_renewal_templates_carry_a_payment_link(): void
    {
        // The shipped renewal reminders must give clients a one-tap way to pay —
        // a nudge with no link and no next step doesn't convert.
        $this->seed(\Database\Seeders\SprintThreeTemplateSeeder::class);

        $day0 = Template::query()->where('title', 'Renewal SMS Day 0')->where('channel', 'sms')->firstOrFail();
        $this->assertStringContainsString('{{payment_link}}', (string) $day0->body);

        $winBack = Template::query()->where('title', 'Renewal SMS Day +3')->where('channel', 'sms')->firstOrFail();
        $this->assertStringContainsString('{{payment_link}}', (string) $winBack->body);
    }

    public function test_paused_client_is_skipped(): void
    {
        [$platform] = $this->marketWithOffer();
        $this->fakeTokenizedLinks();
        $this->onboardingTemplate();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'signup_source' => 'fast_signup',
            'reminders_paused_until' => now()->addDays(3),
        ]);

        $result = $this->service()->send('onboarding', $client);
        $this->assertSame('reminders_paused', $result['skip_reason']);
    }

    public function test_successful_send_stamps_first_contact_and_counts_in_stats(): void
    {
        [$platform] = $this->marketWithOffer();
        $this->fakeTokenizedLinks();
        $this->fakeSmsDelivery();
        $this->onboardingTemplate();
        $this->admin();

        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'signup_source' => 'fast_signup',
            'first_contact_at' => null,
        ]);

        $result = $this->service()->send('onboarding', $client);
        $this->assertSame('sent', $result['status'], json_encode($result));

        $fresh = $client->fresh();
        $this->assertNotNull($fresh->first_contact_at, 'a successful send should record first contact');
        $this->assertNotNull($fresh->last_contact_at);

        $stats = $this->service()->reminderStats($fresh);
        $this->assertSame(1, $stats['reminders_sent']);
        $this->assertNotNull($stats['last_sent_at']);
        $this->assertSame(1, $stats['by_flow']['onboarding'] ?? 0);
        $this->assertFalse($stats['paused']);
    }

    public function test_preview_for_client_renders_without_sending(): void
    {
        [$platform] = $this->marketWithOffer();
        $this->fakeTokenizedLinks();
        $this->onboardingTemplate();
        $client = Client::factory()->create(['platform_id' => $platform->id, 'signup_source' => 'fast_signup']);

        $preview = $this->service()->previewForClient('onboarding', $client);

        $this->assertTrue($preview['would_send']);
        $this->assertNotEmpty($preview['body']);
        $this->assertGreaterThanOrEqual(1, $preview['segments']);
        // Preview must not create deals/payments or a timeline record.
        $this->assertSame(0, Deal::query()->count());
        $this->assertSame(0, TimelineEvent::query()->where('event_type', LifecycleSmsService::TIMELINE_EVENT_TYPE)->count());
    }

    public function test_run_command_dry_run_reports_targets_without_sending(): void
    {
        [$platform] = $this->marketWithOffer();
        $this->fakeTokenizedLinks();
        $this->onboardingTemplate();
        Client::factory()->create(['platform_id' => $platform->id, 'signup_source' => 'fast_signup']);

        $this->artisan('crm:run-lifecycle-sms', ['--flow' => 'onboarding', '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame(0, TimelineEvent::query()->where('event_type', LifecycleSmsService::TIMELINE_EVENT_TYPE)->count());
        $this->assertSame(0, Deal::query()->count());
    }
}
