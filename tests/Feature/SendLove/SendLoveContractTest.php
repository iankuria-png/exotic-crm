<?php

namespace Tests\Feature\SendLove;

use App\Models\Client;
use App\Models\LoveGift;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\SendLoveSetting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\BillingGatewayService;
use App\Services\BillingModeService;
use App\Services\PaymentCompletionService;
use App\Services\SendLove\CheckoutService;
use App\Services\SendLove\OwnerService;
use App\Services\SendLove\SettingsService;
use App\Services\WalletSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class SendLoveContractTest extends TestCase
{
    use RefreshDatabase;

    private Platform $p;

    private Client $c;

    private SendLoveSetting $s;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Queue::fake();
        $this->mock(WalletSyncService::class, fn ($m) => $m->shouldReceive('syncClientBalanceById')->andReturn(['status' => 'skipped']));
        $this->mock(BillingModeService::class, function ($m) {
            $m->shouldReceive('assertWalletAvailable')->andReturn([]);
            $m->shouldReceive('providerContext')->andReturn(['environment' => 'production']);
        });
        $this->mock(BillingGatewayService::class, fn ($m) => $m->shouldReceive('initiateSendLove')->andReturn(['action' => ['type' => 'stk_push']]));
        $this->p = Platform::factory()->create(['currency_code' => 'KES', 'phone_prefix' => '254']);
        $this->c = Client::factory()->create(['platform_id' => $this->p->id, 'wp_post_id' => 112541, 'wp_user_id' => 33167, 'profile_status' => 'publish', 'lifecycle_archived_at' => null, 'closed_at' => null, 'escort_expire' => now()->addMonth()->timestamp, 'is_high_risk' => false, 'wallet_currency' => 'KES']);
        User::firstOrCreate(['id' => 1], ['name' => 'QA', 'email' => 'qa@example.test', 'password' => 'qa', 'role' => 'admin', 'status' => 'active']);
        $this->s = app(SettingsService::class)->forPlatform($this->p);
        $this->s->update(['enabled' => true, 'rollout_mode' => 'live']);
    }

    private function input(array $d = []): array
    {
        return array_merge(['wp_post_id' => $this->c->wp_post_id, 'session_proof' => str_repeat('a', 64), 'amount' => 1000, 'card_line' => 'Spoil yourself properly', 'visitor_phone' => '0712345678', 'provider_key' => 'kopokopo', 'message' => 'You made my week.', 'sender_name' => 'K.', 'share_contact' => false], $d);
    }

    private function intent(array $d = [], ?string $key = null): array
    {
        return app(CheckoutService::class)->intent($this->p, $this->input($d), $key ?? (string) Str::uuid(), Request::create('/', 'POST'));
    }

    private function gift(array $d = []): LoveGift
    {
        return LoveGift::where('public_id', $this->intent($d)['gift_reference'])->firstOrFail();
    }

    public function test_duplicate_callback_credits_once_and_subscription_totals_do_not_change(): void
    {
        Payment::create(['platform_id' => $this->p->id, 'amount' => 750, 'currency' => 'KES', 'purpose' => 'subscription', 'status' => 'completed']);
        $before = Payment::excludingWalletTopups()->sum('amount');
        $g = $this->gift();
        app(PaymentCompletionService::class)->complete($g->payment, ['amount' => 1000, 'currency' => 'KES']);
        app(PaymentCompletionService::class)->complete($g->payment->fresh(), ['amount' => 1000, 'currency' => 'KES']);
        $this->assertSame('sent', $g->fresh()->status);
        $this->assertNull($g->payment->client_id);
        $this->assertEquals(1000, WalletTransaction::where('reference_type', 'love_received')->sum('amount'));
        $this->assertSame(1, WalletTransaction::where('reference_type', 'love_received')->count());
        $this->assertEquals($before, Payment::excludingWalletTopups()->sum('amount'));
        $this->assertEquals(750, DB::table('vw_payments_usd')->sum('amount_original'));
        Queue::assertPushed(\App\Jobs\SendLoveNotice::class, 1);
    }

    public function test_idempotent_replay_initiates_only_once(): void
    {
        $key = (string) Str::uuid();
        $a = $this->intent([], $key);
        $b = $this->intent([], $key);
        $this->assertSame($a['gift_reference'], $b['gift_reference']);
        $this->assertTrue($b['replayed']);
        $this->assertSame(1, LoveGift::count());
    }

    public function test_under_settlement_requires_review_without_credit(): void
    {
        $g = $this->gift();
        app(PaymentCompletionService::class)->complete($g->payment, ['amount' => 999, 'currency' => 'KES']);
        $this->assertSame('review', $g->fresh()->status);
        $this->assertSame(0, WalletTransaction::count());
    }

    public function test_wrong_currency_requires_review(): void
    {
        $g = $this->gift();
        app(PaymentCompletionService::class)->complete($g->payment, ['amount' => 1000, 'currency' => 'USD']);
        $this->assertSame('review', $g->fresh()->status);
        $this->assertSame(0, WalletTransaction::count());
    }

    public function test_sandbox_never_credits_wallet(): void
    {
        $this->s->update(['rollout_mode' => 'sandbox', 'test_client_ids' => [$this->c->id]]);
        $g = $this->gift();
        app(PaymentCompletionService::class)->complete($g->payment, ['amount' => 1000, 'currency' => 'KES']);
        $this->assertSame('sent', $g->fresh()->status);
        $this->assertSame(0, WalletTransaction::count());
        Queue::assertNotPushed(\App\Jobs\SendLoveNotice::class);
    }

    public function test_share_rounds_to_minor_units(): void
    {
        $this->s->update(['creator_share_bps' => 3333]);
        $g = $this->gift(['amount' => 101, 'card_line' => 'Just because']);
        app(PaymentCompletionService::class)->complete($g->payment, ['amount' => 101, 'currency' => 'KES']);
        $this->assertSame('33.66', $g->fresh()->creator_credit_amount);
        $this->assertSame('67.34', $g->fresh()->platform_share_amount);
    }

    public function test_contact_is_absent_by_default_and_encrypted_with_consent(): void
    {
        $a = $this->gift();
        $this->assertNull(DB::table('love_gifts')->where('id', $a->id)->value('contact_phone_encrypted'));
        app(PaymentCompletionService::class)->complete($a->payment, ['amount' => 1000, 'currency' => 'KES']);
        $this->assertNull(app(OwnerService::class)->summary($this->c)['gifts'][0]['contact_phone']);
        $b = $this->gift(['share_contact' => true]);
        app(PaymentCompletionService::class)->complete($b->payment, ['amount' => 1000, 'currency' => 'KES']);
        $raw = DB::table('love_gifts')->where('id', $b->id)->value('contact_phone_encrypted');
        $this->assertStringNotContainsString('254712345678', $raw);
        $this->assertNotNull($b->contact_consent_at);
        $rows = collect(app(OwnerService::class)->summary($this->c)['gifts']);
        $this->assertSame('254712345678', $rows->firstWhere('public_id', $b->public_id)['contact_phone']);
        $b->update(['message_state' => 'hidden_by_creator']);
        $rows = collect(app(OwnerService::class)->summary($this->c)['gifts']);
        $this->assertNull($rows->firstWhere('public_id', $b->public_id)['contact_phone']);
    }

    public function test_foreign_device_status_is_not_found(): void
    {
        $g = $this->gift();
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(CheckoutService::class)->status($this->p, $g->public_id, str_repeat('b', 64));
    }

    public function test_invalid_note_rejected(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->intent(['message' => 'Call 0712 345 678']);
    }

    public function test_handle_signoff_rejected(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->intent(['sender_name' => '@contactme']);
    }

    public function test_amount_bounds_rejected(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->intent(['amount' => 99]);
    }

    public function test_wrong_tier_line_rejected(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->intent(['card_line' => 'Chop money, chop life!']);
    }

    public function test_phone_cap_serializes_different_attempts(): void
    {
        $this->s->update(['limits_json' => ['per_phone_daily_amount' => 1500, 'attempts_per_10min' => 5]]);
        $this->gift();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->gift();
    }

    public function test_hidden_creator_cannot_receive(): void
    {
        DB::table('send_love_visibility')->insert(['client_id' => $this->c->id, 'visible' => false]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->gift();
    }

    public function test_expired_creator_cannot_receive(): void
    {
        $this->c->update(['escort_expire' => now()->subDay()->timestamp]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->gift();
    }

    public function test_payments_filter_lists_recipient_and_all_types_excludes_gifts(): void
    {
        $g = $this->gift();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin, 'sanctum')->getJson('/api/crm/payments?purpose=send_love')->assertOk()->assertJsonPath('data.0.visitor_product.label', 'Send love')->assertJsonPath('data.0.visitor_product.recipients.0.id', $this->c->id);
        $this->actingAs($admin, 'sanctum')->getJson('/api/crm/payments')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_owner_proof_does_not_accept_another_user(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(\App\Services\Monetization\ListingEligibility::class)->assertOwner($this->p->id, $this->c->wp_post_id, $this->c->wp_user_id + 1);
    }

    public function test_settings_revision_and_provider_failure_preserve_values(): void
    {
        $input = $this->s->toArray() + ['reason' => 'Enable approved gifting'];
        unset($input['id'],$input['created_at'],$input['updated_at']);
        $saved = app(SettingsService::class)->save($this->p, $input, 1);
        $this->assertSame(2, (int) $saved->config_revision);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(SettingsService::class)->save($this->p, $input, 1);
    }

    public function test_disabled_provider_has_inline_error_without_saving(): void
    {
        $this->mock(BillingModeService::class, fn ($m) => $m->shouldReceive('providerContext')->andThrow(new \InvalidArgumentException('Selected provider is disabled for this market.')));
        $input = $this->s->toArray() + ['reason' => 'Enable approved gifting'];
        try {
            app(SettingsService::class)->save($this->p, $input, 1);
            $this->fail('Expected provider validation');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('Wallet System', $e->errors()['allowed_providers_json'][0]);
        }
        $this->assertSame(1, (int) $this->s->fresh()->config_revision);
    }

    public function test_resend_reuses_payment_after_cooldown_and_blocks_terminal_gift(): void
    {
        $g = $this->gift();
        $g->update(['created_at' => now()->subMinute()]);
        app(CheckoutService::class)->resend($this->p, $g->public_id, str_repeat('a', 64), Request::create('/', 'POST'));
        $this->assertSame(1, LoveGift::count());
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, data_get($g->fresh()->metadata_json, 'resend_count'));
        app(PaymentCompletionService::class)->complete($g->payment, ['amount' => 1000, 'currency' => 'KES']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(CheckoutService::class)->resend($this->p, $g->public_id, str_repeat('a', 64), Request::create('/', 'POST'));
    }

    public function test_resend_requires_thirty_second_cooldown(): void
    {
        $g = $this->gift();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(CheckoutService::class)->resend($this->p, $g->public_id, str_repeat('a', 64), Request::create('/', 'POST'));
    }

    public function test_global_pause_prevents_new_gifts_but_allows_existing_settlement(): void
    {
        $g = $this->gift();
        app(\App\Services\MonetizationSettingsService::class)->system()->update(['send_love_kill_switch' => true]);
        app(PaymentCompletionService::class)->complete($g->payment, ['amount' => 1000, 'currency' => 'KES']);
        $this->assertSame('sent', $g->fresh()->status);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->gift();
    }

    public function test_global_pause_bumps_market_revision_and_rejects_stale_market_save(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $old = $this->s->toArray();
        $system = app(\App\Services\MonetizationSettingsService::class)->system()->fresh();
        $this->partialMock(\App\Http\Controllers\CRM\SendLoveController::class, fn ($m) => $m->shouldReceive('push')->once()->andReturn(['status' => 'synced']));
        $this->actingAs($admin, 'sanctum')->putJson('/api/crm/settings/send-love-system', ['send_love_kill_switch' => true, 'config_revision' => $system->config_revision, 'reason' => 'Pause gifting during maintenance'])->assertOk();
        $this->assertSame((int) $old['config_revision'] + 1, $this->s->fresh()->config_revision);
        $this->assertTrue(app(SettingsService::class)->runtime($this->s->fresh())['kill_switch']);
        $this->actingAs($admin, 'sanctum')->putJson('/api/crm/settings/send-love/'.$this->p->id, array_merge($old, ['reason' => 'An older market settings edit']))->assertStatus(409);
    }

    public function test_staff_clear_contact_and_remove_note_are_audited(): void
    {
        $g = $this->gift(['share_contact' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin, 'sanctum')->postJson('/api/crm/monetization/love/'.$g->id.'/clear-contact', ['reason' => 'Creator requested privacy'])->assertOk();
        $this->assertNull($g->fresh()->contact_phone_encrypted);
        $this->assertNotNull($g->fresh()->contact_consent_at);
        $this->actingAs($admin, 'sanctum')->postJson('/api/crm/monetization/love/'.$g->id.'/remove-note', ['reason' => 'Reported unwanted content'])->assertOk();
        $this->assertNull($g->fresh()->message);
        $this->assertSame(2, \App\Models\PremiumContentEvent::where('kind', 'like', 'send_love_%')->count());
    }

    public function test_creator_report_hides_contact_and_records_moderation_event(): void
    {
        $g = $this->gift(['share_contact' => true]);
        app(PaymentCompletionService::class)->complete($g->payment, ['amount' => 1000, 'currency' => 'KES']);
        $r = Request::create('/', 'POST', ['wp_post_id' => $this->c->wp_post_id, 'wp_user_id' => $this->c->wp_user_id]);
        $r->attributes->set('wallet_platform', $this->p);
        (new \App\Http\Controllers\Wp\SendLoveController)->report($r, $g->public_id);
        $this->assertSame('hidden_by_creator', $g->fresh()->message_state);
        $this->assertSame(1, \App\Models\PremiumContentEvent::where('kind', 'send_love_note_reported')->count());
        $this->assertNull(app(OwnerService::class)->summary($this->c)['gifts'][0]['contact_phone']);
    }

    public function test_unsigned_wp_request_cannot_access_configuration(): void
    {
        $this->postJson('/api/wp-svc/send-love/config', ['cached_revision' => 0])->assertUnauthorized();
    }

    public function test_staff_reports_filter_and_export_without_join_ambiguity(): void
    {
        $g = $this->gift();
        $g->update(['status' => 'failed']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin, 'sanctum')->getJson('/api/crm/monetization/love?platform_id='.$this->p->id.'&status=failed')->assertOk()->assertJsonPath('summary.attempts', 1)->assertJsonPath('failures.0.count', 1);
        $this->actingAs($admin, 'sanctum')->getJson('/api/crm/monetization/love/export?platform_id='.$this->p->id)->assertOk();
        $this->actingAs($admin, 'sanctum')->getJson('/api/crm/settings/send-love/'.$this->p->id)->assertOk()->assertJsonPath('market.currency', 'KES');
    }

    public function test_refund_requires_matching_reversal_and_is_replay_safe(): void
    {
        $g = $this->gift(['share_contact' => true]);
        app(PaymentCompletionService::class)->complete($g->payment, ['amount' => 1000, 'currency' => 'KES']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $path = '/api/crm/monetization/love/'.$g->id.'/refund';
        $body = ['reason' => 'Customer requested refund', 'provider_reference' => 'refund-qa'];
        $this->actingAs($admin, 'sanctum')->postJson($path, $body)->assertUnprocessable();
        $debit = app(\App\Services\WalletService::class)->debit($this->c, 'KES', 1000, ['reference_type' => 'admin_adjustment', 'idempotency_key' => 'love-refund-qa', 'description' => 'Approved refund'])['transaction'];
        $body['wallet_adjustment_id'] = $debit->id;
        $this->actingAs($admin, 'sanctum')->postJson($path, $body)->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson($path, $body)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame('refunded', $g->fresh()->status);
        $this->assertNull($g->fresh()->contact_phone_encrypted);
        $this->assertSame('0.00', app(OwnerService::class)->summary($this->c)['month_total']);
        app(PaymentCompletionService::class)->complete($g->payment->fresh(), ['amount' => 1000, 'currency' => 'KES']);
        $this->assertSame(1, WalletTransaction::where('reference_type', 'love_received')->count());
    }

    public function test_uncertain_provider_initiation_stays_pending_for_late_callback(): void
    {
        $this->mock(BillingGatewayService::class, fn ($m) => $m->shouldReceive('initiateSendLove')->andReturnUsing(function ($p) {
            $p->update(['status' => 'failed', 'failure_reason' => 'transport timeout']);
            throw new \RuntimeException('Transport timeout');
        }));
        $g = $this->gift();
        $this->assertSame('pending', $g->payment->status);
        $this->assertSame('pending_payment', $g->status);
        app(PaymentCompletionService::class)->complete($g->payment, ['amount' => 1000, 'currency' => 'KES']);
        $this->assertSame('sent', $g->fresh()->status);
        $this->assertSame(1, WalletTransaction::where('reference_type', 'love_received')->count());
    }
}
