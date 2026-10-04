<?php

namespace Tests\Feature\Rebates;

use App\Models\Client;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Rebates\RebateChannelResolver;
use App\Services\Rebates\RebateGrantService;
use App\Services\Rebates\RebateProgramService;
use App\Services\WalletService;
use App\Services\WalletSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RebateContractTest extends TestCase
{
    use RefreshDatabase;

    private Platform $p;

    private Client $c;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->mock(WalletSyncService::class, fn ($m) => $m->shouldReceive('syncClientBalanceById', 'syncPlatformConfig', 'syncClientBalance')->andReturn(['status' => 'skipped']));
        $this->p = Platform::factory()->create(['currency_code' => 'KES', 'timezone' => 'Africa/Nairobi', 'wallet_settings' => ['enabled' => true]]);
        $this->c = Client::factory()->create(['platform_id' => $this->p->id, 'wallet_currency' => 'KES', 'wallet_balance' => 0, 'client_type' => 'escort', 'verified' => true]);
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $s = app(RebateProgramService::class)->forPlatform($this->p);
        $d = $s->draft_json;
        $d['rollout_mode'] = 'live';
        $s->update(['draft_json' => $d]);
        app(RebateProgramService::class)->publish($this->p, 1, 'QA contract launch', $this->admin->id);
    }

    private function payment(array $values = []): Payment
    {
        return Payment::factory()->create(array_merge(['product_id' => null, 'platform_id' => $this->p->id, 'client_id' => $this->c->id, 'purpose' => 'wallet_topup', 'source' => 'gateway', 'provider_environment' => 'production', 'amount' => 5000, 'currency' => 'KES', 'payment_data' => ['initiator' => 'companion']], $values));
    }

    public function test_duplicate_grants_credit_once_preserve_base_pointer_and_consume_budget(): void
    {
        $p = $this->payment();
        $g = app(RebateGrantService::class);
        $a = $g->grantFor($p);
        $b = $g->grantFor($p);
        $this->assertNotNull($a);
        $this->assertSame($a->id, $b->id);
        $this->assertEquals(600, $a->amount);
        $this->assertSame(1, WalletTransaction::where('reference_type', 'wallet_rebate')->count());
        $this->assertNull($p->fresh()->wallet_transaction_id);
        $this->assertDatabaseHas('rebate_budget_periods', ['platform_id' => $this->p->id, 'issued_amount' => 600]);
    }

    public function test_budget_and_companion_caps_clip_next_settlement(): void
    {
        $service = app(RebateProgramService::class);
        $s = $service->forPlatform($this->p);
        $d = $s->draft_json;
        $d['guard']['budget'] = 650;
        $service->save($this->p, $d, $s->draft_revision, $this->admin->id);
        $service->publish($this->p, 2, 'Small QA budget', $this->admin->id);
        $g = app(RebateGrantService::class);
        $g->grantFor($this->payment());
        $second = $g->grantFor($this->payment());
        $this->assertEquals(50, $second->amount);
        $this->assertSame('capped', $second->status);
        $this->assertSame('budget', $second->reason);
        $third = $g->grantFor($this->payment());
        $this->assertSame('skipped', $third->status);
    }

    public function test_sandbox_simulates_and_channel_off_explains_zero(): void
    {
        $g = app(RebateGrantService::class);
        $a = $g->grantFor($this->payment(['provider_environment' => 'sandbox']));
        $this->assertSame('simulated', $a->status);
        $this->assertSame(0, WalletTransaction::count());
        $a = $g->grantFor($this->payment(['purpose' => 'subscription', 'source' => 'manual_confirmation', 'payment_data' => ['initiator' => 'companion', 'manual_submission' => ['submission_id' => 1]]]));
        $this->assertSame('skipped', $a->status);
        $this->assertSame('channel_off', $a->reason);
    }

    public function test_staff_link_gets_its_own_rate_and_no_money_paths_make_no_row(): void
    {
        $a = app(RebateGrantService::class)->grantFor($this->payment(['purpose' => 'subscription', 'source' => 'crm_lifecycle', 'amount' => 6000, 'payment_data' => ['initiator' => 'staff_link']]));
        $this->assertEquals(120, $a->amount);
        foreach (['deal_manual_payment', 'mpesa_import', 'manual_bundle', 'free_trial'] as $source) {
            $p = $this->payment(['purpose' => 'subscription', 'source' => $source, 'payment_data' => ['initiator' => 'staff_link']]);
            $this->assertNull(app(RebateGrantService::class)->grantFor($p));
        }
        foreach (['send_love', 'premium_content_sale', 'visitor_contact_unlock'] as $purpose) {
            $this->assertNull(app(RebateChannelResolver::class)->resolve($this->payment(['purpose' => $purpose])));
        }
    }

    public function test_failed_grant_can_retry_without_changing_base_payment(): void
    {
        $p = $this->payment();
        $wallet = app(WalletService::class);
        $this->mock(WalletService::class, fn ($m) => $m->shouldReceive('credit')->once()->andThrow(new \RuntimeException('QA forced failure')));
        $this->assertNull(app(RebateGrantService::class)->grantFor($p));
        $this->assertSame('completed', $p->fresh()->status);
        $this->assertDatabaseHas('wallet_rebates', ['source_payment_id' => $p->id, 'status' => 'failed']);
        $this->assertSame(0, WalletTransaction::count());
        app()->instance(WalletService::class, $wallet);
        app()->forgetInstance(RebateGrantService::class);
        $this->artisan('rebates:retry')->assertSuccessful();
        $this->assertDatabaseHas('wallet_rebates', ['source_payment_id' => $p->id, 'status' => 'credited', 'amount' => 600]);
    }

    public function test_reversal_recovers_available_balance_records_shortfall_and_does_not_reopen_budget(): void
    {
        $g = app(RebateGrantService::class);
        $a = $g->grantFor($this->payment());
        app(WalletService::class)->debit($this->c, 'KES', 550);
        $r = $g->reverse($a, 'QA reversal with shortfall', $this->admin->id);
        $this->assertEquals(550, $r->reversal_shortfall);
        $this->assertSame('reversed', $r->status);
        $this->assertEquals(0, app(WalletService::class)->balanceFor($this->c, 'KES'));
        $this->assertDatabaseHas('rebate_budget_periods', ['issued_amount' => 600]);
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/crm/rebates/'.$a->id.'/reverse', ['reason' => 'Try again'])->assertStatus(409);
    }

    public function test_endpoints_enforce_roles_market_and_stale_revisions(): void
    {
        Sanctum::actingAs($this->admin);
        $url = '/api/crm/settings/billing/rebates/'.$this->p->id;
        $this->getJson($url)->assertOk()->assertJsonPath('published.rollout_mode', 'live');
        $draft = app(RebateProgramService::class)->forPlatform($this->p)->draft_json;
        $this->putJson($url, ['draft' => $draft, 'draft_revision' => 0])->assertStatus(409);
        $draft['topup']['tiers'][1]['min'] = 500;
        $this->putJson($url, ['draft' => $draft, 'draft_revision' => 1])->assertStatus(422);
        $sub = User::factory()->create(['role' => 'sub_admin', 'status' => 'active', 'assigned_market_ids' => [$this->p->id]]);
        Sanctum::actingAs($sub);
        $this->getJson($url)->assertOk();
        $this->postJson($url.'/publish', ['draft_revision' => 1, 'reason' => 'QA publish'])->assertForbidden();
        $other = Platform::factory()->create();
        $this->getJson('/api/crm/settings/billing/rebates/'.$other->id)->assertForbidden();
        $this->postJson($url.'/simulate', ['scenario' => 'topup', 'channel' => 'topup', 'amount' => 5000, 'first_topup' => true, 'use' => 'published'])->assertOk()->assertJsonPath('total', 600);
    }

    public function test_draft_never_leaks_and_pause_restores_discount(): void
    {
        $svc = app(RebateProgramService::class);
        $s = $svc->forPlatform($this->p);
        $draft = $s->draft_json;
        $draft['topup']['tiers'][2]['pct'] = 40;
        $svc->save($this->p, $draft, 1, $this->admin->id);
        $a = app(RebateGrantService::class)->grantFor($this->payment());
        $this->assertEquals(600, $a->amount);
        $this->assertTrue($svc->replacesDiscount($this->p->id));
        $svc->pause($this->p, true, 'QA instant pause', $this->admin->id);
        $this->assertFalse($svc->replacesDiscount($this->p->id));
        $this->assertNull($svc->runtime($this->p, 'production'));
    }

    public function test_schedule_and_audience_gate_settlement_and_end_only_schedule_is_valid(): void
    {
        $svc = app(RebateProgramService::class);
        $s = $svc->forPlatform($this->p);
        $d = $s->draft_json;
        $d['ends_at'] = now()->addDays(5)->toIso8601String();
        $this->assertNotNull($svc->validate($this->p, $d)['ends_at']);
        $d['starts_at'] = now()->addDay()->toIso8601String();
        $d['audience']['verified_only'] = true;
        $s = $svc->save($this->p, $d, $s->draft_revision, $this->admin->id);
        $svc->publish($this->p, $s->draft_revision, 'QA scheduled verified audience', $this->admin->id);
        $this->assertNull($svc->runtime($this->p, 'production'));
        $this->travel(2)->days();
        $this->assertNotNull($svc->runtime($this->p, 'production'));
        $this->c->update(['verified' => false]);
        $this->assertSame('unverified', app(RebateGrantService::class)->grantFor($this->payment())->reason);
        $this->c->update(['verified' => true, 'client_type' => 'agency']);
        $this->assertSame('agency_excluded', app(RebateGrantService::class)->grantFor($this->payment())->reason);
        $this->assertSame(0, WalletTransaction::count());
        $this->travelBack();
    }

    public function test_resolver_requires_creation_markers_and_classifies_paid_origins(): void
    {
        $resolver = app(RebateChannelResolver::class);
        foreach ([
            ['wallet', ['initiator' => 'companion'], 'wallet'],
            ['wallet', ['initiator' => 'companion', 'renewal_mode' => 'auto_renew'], 'auto_renew'],
            ['self_checkout', ['initiator' => 'companion', 'billing_surface' => 'self_service_subscription'], 'self_checkout'],
            ['manual_confirmation', ['initiator' => 'companion', 'manual_submission' => ['submission_id' => 1]], 'manual_submission'],
            ['manual_submission', [], null],
            ['crm_activation', ['initiator' => 'sales'], 'sales_assisted'],
            ['crm_lifecycle', ['initiator' => 'staff_link'], 'staff_link'],
            ['crm_activation', [], null],
            ['free_trial', ['initiator' => 'sales'], null],
        ] as [$source, $data, $channel]) {
            $this->assertSame($channel, $resolver->resolve($this->payment(['purpose' => 'subscription', 'source' => $source, 'payment_data' => $data])), $source);
        }
    }

    public function test_sandbox_does_not_pin_the_live_launch_budget_before_first_real_credit(): void
    {
        $svc = app(RebateProgramService::class);
        $s = $svc->forPlatform($this->p);
        $d = $s->draft_json;
        $d['rollout_mode'] = 'sandbox';
        $d['test_client_ids'] = [$this->c->id];
        $s = $svc->save($this->p, $d, $s->draft_revision, $this->admin->id);
        $svc->publish($this->p, $s->draft_revision, 'QA sandbox budget', $this->admin->id);
        $g = app(RebateGrantService::class);
        $this->assertSame('simulated', $g->grantFor($this->payment(['provider_environment' => 'sandbox']))->status);
        $d['rollout_mode'] = 'live';
        $d['guard']['budget'] = 15;
        $s = $svc->save($this->p, $d, $s->draft_revision, $this->admin->id);
        $svc->publish($this->p, $s->draft_revision, 'Small live canary budget', $this->admin->id);
        $this->assertDatabaseHas('rebate_budget_periods', ['budget_amount' => 15, 'issued_amount' => 0]);
        $this->assertEquals(15, $g->grantFor($this->payment(['amount' => 500]))->amount);
        $this->assertDatabaseHas('rebate_budget_periods', ['budget_amount' => 15, 'issued_amount' => 15]);
        $d['guard']['budget'] = 150000;
        $s = $svc->save($this->p, $d, $s->draft_revision, $this->admin->id);
        $svc->publish($this->p, $s->draft_revision, 'Next month budget change', $this->admin->id);
        $this->assertDatabaseHas('rebate_budget_periods', ['budget_amount' => 15, 'issued_amount' => 15]);
        $this->assertSame('skipped', $g->grantFor($this->payment())->status);
    }
}
