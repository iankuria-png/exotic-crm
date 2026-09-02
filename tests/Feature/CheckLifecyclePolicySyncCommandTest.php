<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Support\LifecyclePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The CRM flag is intent; the WordPress option is what actually stops the theme's
 * legacy sweep privatising lapsed profiles and deleting escort_expire. This
 * command exists because nothing read the option back, so a market could look
 * protected in the CRM while WordPress kept expiring profiles.
 */
class CheckLifecyclePolicySyncCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_flags_a_market_whose_wordpress_never_received_the_option(): void
    {
        $platform = $this->createPlatform(true);
        $this->fakeLifecyclePolicy($platform, ['enabled' => false]);

        $this->artisan('crm:check-lifecycle-policy-sync')
            ->expectsOutputToContain('SWEEP ARMED')
            ->assertExitCode(1);
    }

    public function test_it_flags_a_market_whose_plugin_is_too_old_to_report_the_option(): void
    {
        // A build predating the GET endpoint returns no `enabled` key. That is the
        // dangerous direction: the option cannot be honoured there at all.
        $platform = $this->createPlatform(true);
        $this->fakeLifecyclePolicy($platform, ['ok' => true]);

        $this->artisan('crm:check-lifecycle-policy-sync')
            ->expectsOutputToContain('SWEEP ARMED')
            ->assertExitCode(1);
    }

    public function test_it_passes_when_both_sides_agree(): void
    {
        $platform = $this->createPlatform(true);
        $this->fakeLifecyclePolicy($platform, ['enabled' => true]);

        $this->artisan('crm:check-lifecycle-policy-sync')
            ->expectsOutputToContain('in sync (lifecycle)')
            ->assertExitCode(0);
    }

    public function test_a_legacy_market_agreeing_with_wordpress_is_not_a_failure(): void
    {
        $platform = $this->createPlatform(false);
        $this->fakeLifecyclePolicy($platform, ['enabled' => false]);

        $this->artisan('crm:check-lifecycle-policy-sync')
            ->expectsOutputToContain('in sync (legacy)')
            ->assertExitCode(0);
    }

    public function test_it_reports_the_reverse_divergence_without_failing(): void
    {
        // WP stood down but the CRM expects legacy. Nothing loses paid access here,
        // so it is reported but must not fail a scheduled check.
        $platform = $this->createPlatform(false);
        $this->fakeLifecyclePolicy($platform, ['enabled' => true]);

        $this->artisan('crm:check-lifecycle-policy-sync')
            ->expectsOutputToContain('CRM expects legacy')
            ->assertExitCode(0);
    }

    public function test_an_unreachable_market_is_reported_but_does_not_fail_the_check(): void
    {
        $platform = $this->createPlatform(true);
        $base = rtrim((string) $platform->wp_api_url, '/');
        Http::fake(["{$base}/lifecycle-policy" => Http::response('gateway down', 502)]);

        $this->artisan('crm:check-lifecycle-policy-sync')
            ->expectsOutputToContain('unreachable')
            ->assertExitCode(0);
    }

    public function test_master_switch_off_means_no_market_is_protected(): void
    {
        LifecyclePolicy::setMasterEnabled(false);

        $platform = $this->createPlatform(true);
        $this->fakeLifecyclePolicy($platform, ['enabled' => true]);

        // With the master off the CRM treats every market as legacy, so a WordPress
        // site that has stood down is the reverse divergence, not agreement.
        $this->artisan('crm:check-lifecycle-policy-sync')
            ->expectsOutputToContain('master off')
            ->assertExitCode(0);
    }

    public function test_it_writes_nothing_to_wordpress(): void
    {
        $platform = $this->createPlatform(true);
        $this->fakeLifecyclePolicy($platform, ['enabled' => false]);

        $this->artisan('crm:check-lifecycle-policy-sync')->assertExitCode(1);

        $mutating = Http::recorded(fn ($request) => $request->method() !== 'GET');

        $this->assertCount(
            0,
            $mutating,
            'crm:check-lifecycle-policy-sync must be read-only — it may only issue GET requests.'
        );
        $this->assertGreaterThan(0, Http::recorded()->count(), 'The market was never actually queried.');
    }

    private function createPlatform(bool $lifecycleEnabled): Platform
    {
        return Platform::factory()->create([
            'name' => 'Policy Market',
            'country' => 'Kenya',
            'domain' => 'policy-'.Str::random(6).'.example.test',
            'timezone' => 'Africa/Nairobi',
            'currency_code' => 'KES',
            'is_active' => true,
            'lifecycle_policy_enabled' => $lifecycleEnabled,
            'wp_api_url' => 'https://policy-market.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }

    private function fakeLifecyclePolicy(Platform $platform, array $body): void
    {
        $base = rtrim((string) $platform->wp_api_url, '/');

        Http::fake([
            "{$base}/lifecycle-policy" => Http::response($body, 200),
            '*' => Http::response([], 200),
        ]);
    }
}
