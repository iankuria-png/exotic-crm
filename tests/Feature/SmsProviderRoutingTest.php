<?php

namespace Tests\Feature;

use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsProviderRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function service(): NotificationService
    {
        return new NotificationService();
    }

    public function test_provider_options_expose_all_registered_providers_with_fields(): void
    {
        $options = $this->service()->smsProviderOptions();
        $ids = array_column($options, 'id');

        $this->assertEqualsCanonicalizing(
            ['legacy_gateway', 'africastalking', 'briq', 'uganda_bulk_sms', 'kullsms', 'ghana_bulk_sms'],
            $ids
        );

        $briq = collect($options)->firstWhere('id', 'briq');
        $this->assertSame('Briq (Tanzania)', $briq['label']);
        $this->assertContains('api_key', array_column($briq['fields'], 'key'));
    }

    public function test_secrets_are_masked_and_configured_flag_set(): void
    {
        $service = $this->service();
        $service->saveSmsConfig([
            'enabled' => true,
            'active_provider' => 'briq',
            'fallback_provider' => 'none',
            'briq' => [
                'base_url' => 'https://karibu.briq.tz',
                'api_key' => 'super-secret-key',
                'sender_id' => 'EXOTIC',
            ],
        ]);

        $masked = $service->currentSmsConfig(masked: true);
        $this->assertSame('••••••••', $masked['briq']['api_key']);
        $this->assertTrue($masked['briq']['api_key_configured']);

        $unmasked = $service->currentSmsConfig(masked: false);
        $this->assertSame('super-secret-key', $unmasked['briq']['api_key']);
    }

    public function test_blank_or_masked_secret_does_not_overwrite_stored_secret(): void
    {
        $service = $this->service();
        $service->saveSmsConfig([
            'enabled' => true,
            'active_provider' => 'briq',
            'briq' => ['base_url' => 'https://karibu.briq.tz', 'api_key' => 'keep-me', 'sender_id' => 'A'],
        ]);

        // Re-save with a blank api_key and a masked api_key; neither should wipe it.
        $service->saveSmsConfig([
            'enabled' => true,
            'active_provider' => 'briq',
            'briq' => ['api_key' => '', 'sender_id' => 'B'],
        ]);
        $service->saveSmsConfig([
            'enabled' => true,
            'active_provider' => 'briq',
            'briq' => ['api_key' => '••••••••'],
        ]);

        $unmasked = $service->currentSmsConfig(masked: false);
        $this->assertSame('keep-me', $unmasked['briq']['api_key']);
        $this->assertSame('B', $unmasked['briq']['sender_id']);
    }

    public function test_global_dispatch_routes_to_selected_provider(): void
    {
        Http::fake([
            'karibu.briq.tz/*' => Http::response(['success' => true, 'job_id' => 'job-1'], 200),
        ]);

        $service = $this->service();
        $service->saveSmsConfig([
            'enabled' => true,
            'active_provider' => 'briq',
            'fallback_provider' => 'none',
            'briq' => ['base_url' => 'https://karibu.briq.tz', 'api_key' => 'k', 'sender_id' => 'EXOTIC'],
        ]);

        $result = $service->sendSms('0712345678', 'Hello TZ', ['phone_prefix' => '255']);

        $this->assertTrue($result['success']);
        $this->assertSame('briq', $result['provider']);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v1/message/send-instant')
                && $request['recipients'] === ['255712345678']
                && $request->hasHeader('X-API-Key', 'k');
        });
    }

    public function test_market_override_selects_a_different_provider_than_global(): void
    {
        Http::fake([
            'clientlogin.bulksmsgh.com/*' => Http::response('1000', 200),
            'karibu.briq.tz/*' => Http::response(['success' => true], 200),
        ]);

        $service = $this->service();
        $service->saveSmsConfig([
            'enabled' => true,
            'active_provider' => 'briq',
            'fallback_provider' => 'none',
            'briq' => ['base_url' => 'https://karibu.briq.tz', 'api_key' => 'k', 'sender_id' => 'TZ'],
            'markets' => [
                '7' => [
                    'active_provider' => 'ghana_bulk_sms',
                    'ghana_bulk_sms' => [
                        'base_url' => 'https://clientlogin.bulksmsgh.com/smsapi',
                        'api_key' => 'gh-key',
                        'sender_id' => 'EXOTICGH',
                    ],
                ],
            ],
        ]);

        $result = $service->sendSms('0244123456', 'Hello GH', ['platform_id' => 7, 'phone_prefix' => '233']);

        $this->assertTrue($result['success']);
        $this->assertSame('ghana_bulk_sms', $result['provider']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'clientlogin.bulksmsgh.com'));
    }

    public function test_fallback_provider_is_used_when_active_fails(): void
    {
        Http::fake([
            'karibu.briq.tz/*' => Http::response('server error', 500),
            'api.africastalking.com/*' => Http::response(['SMSMessageData' => ['Recipients' => []]], 200),
        ]);

        $service = $this->service();
        $service->saveSmsConfig([
            'enabled' => true,
            'active_provider' => 'briq',
            'fallback_provider' => 'africastalking',
            'briq' => ['base_url' => 'https://karibu.briq.tz', 'api_key' => 'k', 'sender_id' => 'TZ'],
            'africastalking' => ['username' => 'sandbox', 'api_key' => 'at-key', 'sender_id' => 'EXOTIC'],
        ]);

        $result = $service->sendSms('0712345678', 'Hi', ['phone_prefix' => '255']);

        $this->assertTrue($result['success']);
        $this->assertSame('africastalking', $result['provider']);
        $this->assertTrue($result['fallback_attempted']);
        $this->assertSame('briq', $result['fallback_from']);
    }

    public function test_ghana_provider_accepts_json_envelope_response(): void
    {
        Http::fake([
            'clientlogin.bulksmsgh.com/*' => Http::response(['success' => true, 'code' => '1000', 'message' => 'sent'], 200),
        ]);

        $service = $this->service();
        $service->saveSmsConfig([
            'enabled' => true,
            'active_provider' => 'ghana_bulk_sms',
            'fallback_provider' => 'none',
            'ghana_bulk_sms' => [
                'base_url' => 'https://clientlogin.bulksmsgh.com/smsapi',
                'api_key' => 'gh-key',
                'sender_id' => 'EXOTICGH',
            ],
        ]);

        $result = $service->sendSms('0244123456', 'JSON path', ['phone_prefix' => '233']);

        $this->assertTrue($result['success']);
        $this->assertSame('ghana_bulk_sms', $result['provider']);
        $this->assertSame('1000', $result['actual_success_code']);
    }

    public function test_ghana_provider_json_envelope_with_wrong_code_is_failure(): void
    {
        Http::fake([
            'clientlogin.bulksmsgh.com/*' => Http::response(['success' => true, 'code' => '1004'], 200),
        ]);

        $service = $this->service();
        $service->saveSmsConfig([
            'enabled' => true,
            'active_provider' => 'ghana_bulk_sms',
            'fallback_provider' => 'none',
            'ghana_bulk_sms' => [
                'base_url' => 'https://clientlogin.bulksmsgh.com/smsapi',
                'api_key' => 'gh-key',
                'sender_id' => 'EXOTICGH',
            ],
        ]);

        $result = $service->sendSms('0244123456', 'Wrong code', ['phone_prefix' => '233']);

        $this->assertFalse($result['success']);
        $this->assertSame('1004', $result['actual_success_code']);
    }

    public function test_ghana_provider_still_accepts_plain_text_code(): void
    {
        Http::fake([
            'clientlogin.bulksmsgh.com/*' => Http::response('1000|msg-id-42', 200),
        ]);

        $service = $this->service();
        $service->saveSmsConfig([
            'enabled' => true,
            'active_provider' => 'ghana_bulk_sms',
            'fallback_provider' => 'none',
            'ghana_bulk_sms' => [
                'base_url' => 'https://clientlogin.bulksmsgh.com/smsapi',
                'api_key' => 'gh-key',
                'sender_id' => 'EXOTICGH',
            ],
        ]);

        $result = $service->sendSms('0244123456', 'Plain path', ['phone_prefix' => '233']);

        $this->assertTrue($result['success']);
        $this->assertSame('1000', $result['actual_success_code']);
    }

    public function test_skip_fallback_does_not_invoke_the_fallback_provider(): void
    {
        Http::fake([
            'karibu.briq.tz/*' => Http::response('server error', 500),
            'api.africastalking.com/*' => Http::response(['ok' => true], 200),
        ]);

        $service = $this->service();
        $service->saveSmsConfig([
            'enabled' => true,
            'active_provider' => 'briq',
            'fallback_provider' => 'africastalking',
            'briq' => ['base_url' => 'https://karibu.briq.tz', 'api_key' => 'k', 'sender_id' => 'TZ'],
            'africastalking' => ['username' => 'sandbox', 'api_key' => 'at-key', 'sender_id' => 'EXOTIC'],
        ]);

        $result = $service->sendSms('0712345678', 'Isolated test', [
            'phone_prefix' => '255',
            'skip_fallback' => true,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('briq', $result['provider']);
        $this->assertFalse($result['fallback_attempted']);
        $this->assertTrue($result['fallback_skipped']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'africastalking'));
    }

    public function test_trace_records_attempts_and_never_leaks_secrets(): void
    {
        Http::fake([
            'karibu.briq.tz/*' => Http::response('server error', 500),
            'api.africastalking.com/*' => Http::response(['ok' => true], 200),
        ]);

        $service = $this->service();
        $service->saveSmsConfig([
            'enabled' => true,
            'active_provider' => 'briq',
            'fallback_provider' => 'africastalking',
            'briq' => ['base_url' => 'https://karibu.briq.tz', 'api_key' => 'top-secret', 'sender_id' => 'TZ'],
            'africastalking' => ['username' => 'sandbox', 'api_key' => 'at-secret', 'sender_id' => 'EXOTIC'],
        ]);

        $result = $service->sendSms('0712345678', 'Trace me', [
            'phone_prefix' => '255',
            'trace' => true,
        ]);

        $this->assertArrayHasKey('trace', $result);
        $this->assertCount(2, $result['trace']['attempts']);
        $this->assertSame('briq', $result['trace']['attempts'][0]['provider']);
        $this->assertSame('active', $result['trace']['attempts'][0]['role']);
        $this->assertSame('fallback', $result['trace']['attempts'][1]['role']);

        // Secret redaction: api_key must be a set/unset flag, never the value.
        $this->assertSame('set', $result['trace']['attempts'][0]['request']['api_key']);
        $encoded = json_encode($result['trace']);
        $this->assertStringNotContainsString('top-secret', $encoded);
        $this->assertStringNotContainsString('at-secret', $encoded);
    }

    public function test_dispatch_is_recorded_in_sms_logs_with_routing_fields(): void
    {
        Http::fake([
            'karibu.briq.tz/*' => Http::response(['success' => true, 'job_id' => 'j1'], 200),
        ]);

        $service = $this->service();
        $service->saveSmsConfig([
            'enabled' => true,
            'active_provider' => 'briq',
            'fallback_provider' => 'none',
            'briq' => ['base_url' => 'https://karibu.briq.tz', 'api_key' => 'k', 'sender_id' => 'TZ'],
        ]);

        $service->sendSms('0712345678', 'Logged send', [
            'platform_id' => 9,
            'phone_prefix' => '255',
            'purpose' => 'unit_test',
        ]);

        $this->assertDatabaseHas('sms_logs', [
            'provider' => 'briq',
            'platform_id' => 9,
            'status' => 'sent',
            'purpose' => 'unit_test',
            'http_code' => 200,
        ]);
    }

    public function test_log_dispatch_opt_out_skips_central_logging(): void
    {
        Http::fake([
            'karibu.briq.tz/*' => Http::response(['success' => true], 200),
        ]);

        $service = $this->service();
        $service->saveSmsConfig([
            'enabled' => true,
            'active_provider' => 'briq',
            'fallback_provider' => 'none',
            'briq' => ['base_url' => 'https://karibu.briq.tz', 'api_key' => 'k', 'sender_id' => 'TZ'],
        ]);

        $service->sendSms('0712345678', 'No log', ['phone_prefix' => '255', 'log_dispatch' => false]);

        $this->assertDatabaseCount('sms_logs', 0);
    }

    public function test_legacy_flat_market_shape_still_resolves(): void
    {
        // Simulates a market saved under the pre-existing flat provider shape.
        Http::fake(['sms-gateway.example.test/*' => Http::response('OK', 200)]);

        $service = $this->service();
        $service->saveSmsConfig([
            'enabled' => true,
            'active_provider' => 'africastalking',
            'fallback_provider' => 'none',
            'markets' => [
                '3' => [
                    'active_provider' => 'legacy_gateway',
                    'legacy_gateway' => [
                        'gateway_url' => 'https://sms-gateway.example.test/send',
                        'org_code' => '58',
                    ],
                ],
            ],
        ]);

        $result = $service->sendSms('0712345678', 'Legacy', ['platform_id' => 3]);

        $this->assertTrue($result['success']);
        $this->assertSame('legacy_gateway', $result['provider']);
    }
}
