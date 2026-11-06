<?php

namespace Tests\Feature\Trials;

use App\Models\Client;
use App\Models\Deal;
use App\Models\FreeTrialRequest;
use App\Models\MarketTrialApprovalPolicy;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\TrialActivationOperation;
use App\Models\User;
use App\Services\Trials\TrialActivationService;
use App\Services\Trials\TrialApprovalService;
use App\Services\WalletSettingsService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrialAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertTrue(config('database.default') === 'sqlite' || (config('database.default') === 'mysql' && str_starts_with((string) config('database.connections.mysql.database'), 'crm_daily_review_test_')), 'Use a disposable test database.');
        if (config('database.default') === 'mysql') {
            \Tests\Support\WorkflowTestDatabase::reset();
        } else {
            Artisan::call('migrate:fresh', ['--force' => true]);
        }
        $this->travelTo(now()->setDate(2026, 11, 5)->setTime(12, 0));
        Http::preventStrayRequests();
    }

    private function fixture(): array
    {
        $m = Platform::factory()->create(['wp_api_url' => 'https://trials.example.test/wp-json/exotic-crm-sync/v1', 'wp_api_user' => 'fixture', 'wp_api_password' => 'fixture', 'phone_prefix' => '255']);
        $product = Product::factory()->create(['platform_id' => $m->id, 'tier' => 'vip', 'is_active' => true]);
        $c = Client::factory()->create(['platform_id' => $m->id, 'wp_post_id' => 77, 'wp_user_id' => 55, 'profile_status' => 'private', 'profile_removal_requested_at' => null, 'profile_reverted_at' => null]);
        $d = Deal::factory()->create(['platform_id' => $m->id, 'client_id' => $c->id, 'product_id' => $product->id, 'status' => 'pending', 'payment_id' => null, 'activated_at' => null, 'expires_at' => null, 'duration_days' => 7, 'plan_type' => 'vip']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active', 'assigned_market_ids' => [$m->id]]);

        return [$m, $admin, $sales, $c, $d];
    }

    private function site(bool $loseResponse = false, array $contacts = []): void
    {
        $expiry = now()->addDays(7)->timestamp;
        $sent = false;
        Http::fake(['https://trials.example.test/*' => function ($req) use (&$sent, $expiry, $loseResponse, $contacts) {
            if ($req->method() === 'POST') {
                $this->assertSame(0, DB::transactionLevel(), 'No database transaction may surround the site mutation.');
                $sent = true;
                if ($loseResponse) {
                    throw new \Illuminate\Http\Client\ConnectionException('Synthetic lost response');
                }

                return Http::response(['success' => true, 'escort_expire' => $expiry]);
            }

            return Http::response(['wp_post_id' => 77, 'wp_user_id' => 55, 'post_status' => $sent ? 'publish' : 'private', 'escort_expire' => $sent ? $expiry : 0] + $contacts);
        }]);
    }

    public function test_legacy_sales_trial_becomes_pending_without_creating_cash_or_activating(): void
    {
        [$m,$admin,$sales,$c,$d] = $this->fixture();
        Sanctum::actingAs($sales);
        $this->postJson('/api/crm/deals/'.$d->id.'/activate', ['payment_method' => 'free_trial', 'reason' => 'Introduce new advertiser'])->assertAccepted()->assertJsonPath('trial_request.status', 'pending');
        $this->assertSame('pending', $d->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
        Http::assertNothingSent();
    }

    public function test_admin_approves_and_activation_is_verified_once_without_pin(): void
    {
        [$m,$admin,$sales,$c,$d] = $this->fixture();
        $this->site();
        Sanctum::actingAs($sales);
        $id = $this->postJson('/api/crm/deals/'.$d->id.'/activate', ['payment_method' => 'free_trial', 'reason' => 'Introduce advertiser'])->assertAccepted()->json('trial_request.id');
        Sanctum::actingAs($admin);
        $this->postJson('/api/crm/free-trials/requests/'.$id.'/decision', ['action' => 'approve', 'version' => 0, 'reason' => 'Approved introductory trial'])->assertOk()->assertJsonPath('trial_request.status', 'active');
        $this->assertTrue($d->fresh()->is_free_trial);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(0.0, (float) Payment::first()->amount);
        app(TrialActivationService::class)->execute(FreeTrialRequest::find($id));
        Http::assertSentCount(3);
        $this->assertDatabaseCount('trial_activation_operations', 1);
    }

    public function test_pin_works_only_in_explicit_market_and_is_bound_to_rotation(): void
    {
        [$m,$admin,$sales,$c,$d] = $this->fixture();
        app(WalletSettingsService::class)->updateFreeTrialPin('1234', $admin->id);
        Sanctum::actingAs($sales);
        $this->postJson('/api/crm/deals/'.$d->id.'/activate', ['payment_method' => 'free_trial', 'free_trial_pin' => '1234', 'reason' => 'Market introduction'])->assertForbidden();
        MarketTrialApprovalPolicy::create(['platform_id' => $m->id, 'approval_mode' => 'admin_or_pin', 'version' => 1]);
        $terms = app(\App\Http\Controllers\CRM\FreeTrialApprovalController::class)->terms(request()->setUserResolver(fn () => $sales), 'activate', ['deal_id' => $d->id, 'reason' => 'Market introduction']);
        $r = app(TrialApprovalService::class)->create($sales, $terms, '1234');
        $this->assertSame('pin', $r->approved_via);
        $this->assertSame(1, $r->pin_generation);
        app(WalletSettingsService::class)->updateFreeTrialPin('5678', $admin->id);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        DB::transaction(fn () => app(TrialApprovalService::class)->assertDispatch($r));
    }

    public function test_stale_settings_payload_cannot_restore_the_previous_trial_pin(): void
    {
        [$m,$admin] = $this->fixture();
        $s = app(WalletSettingsService::class);
        $s->updateFreeTrialPin('1234', $admin->id);
        $old = $s->currentSystemConfig(false);
        $s->updateFreeTrialPin('5678', $admin->id);
        $s->saveSystemConfig($old, $admin->id);
        $s->updateOperatorPin('9876', $admin->id);
        $s->updateDiscountPin('9876', $admin->id);
        $s->updateDiscountConfig([], $admin->id);
        $this->assertFalse($s->verifyFreeTrialPin('1234'));
        $this->assertTrue($s->verifyFreeTrialPin('5678'));
        $this->assertSame(2, $s->currentSystemConfig(false)['free_trial_pin_generation']);
    }

    public function test_lost_response_is_held_and_never_resent_by_execute_or_recover(): void
    {
        [$m,$admin,$sales,$c,$d] = $this->fixture();
        $this->site(true);
        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/crm/deals/'.$d->id.'/activate', ['payment_method' => 'free_trial', 'reason' => 'Admin approved trial'])->assertAccepted()->assertJsonPath('trial_request.status', 'uncertain');
        $r = FreeTrialRequest::find($response->json('trial_request.id'));
        app(TrialActivationService::class)->execute($r);
        app(TrialActivationService::class)->recover($r);
        $this->assertSame('pending', $d->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(1, TrialActivationOperation::count());
        $this->assertSame('uncertain', TrialActivationOperation::first()->state);
    }

    public function test_finance_review_grant_does_not_authorize_trial_decisions_and_denial_does_not_activate(): void
    {
        [$m,$admin,$sales,$c,$d] = $this->fixture();
        Sanctum::actingAs($sales);
        $id = $this->postJson('/api/crm/deals/'.$d->id.'/activate', ['payment_method' => 'free_trial', 'reason' => 'Request'])->assertAccepted()->json('trial_request.id');
        DB::table('payment_review_grants')->insert(['user_id' => $sales->id, 'platform_id' => $m->id, 'capability' => 'review', 'granted_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/api/crm/free-trials/requests/'.$id.'/decision', ['action' => 'approve', 'version' => 0, 'reason' => 'Review grant'])->assertForbidden();
        Sanctum::actingAs($admin);
        $this->postJson('/api/crm/free-trials/requests/'.$id.'/decision', ['action' => 'deny', 'version' => 0, 'reason' => 'Trial not justified'])->assertAccepted()->assertJsonPath('trial_request.status', 'denied');
        Http::assertNothingSent();
    }

    public function test_authorized_seo_batch_creates_one_zero_cash_trial_after_readback(): void
    {
        [$m,$admin,$sales,$c,$d] = $this->fixture();
        $d->delete();
        $c->update(['city' => 'Kilimani', 'seo_score' => 90, 'is_high_risk' => false, 'source_presence_status' => 'present', 'needs_payment' => true]);
        $this->site();
        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/crm/seo-boost/batches', ['platform_id' => $m->id, 'product_id' => $d->product_id, 'duration_days' => 7,
            'targets' => [['canonical_key' => 'kilimani', 'display_city' => 'Kilimani', 'target_count' => 1]], 'selected_client_ids' => [$c->id], 'notes' => 'Approved coverage trial.'])->assertOk()->assertJsonPath('trial_request.status', 'active');
        $this->assertDatabaseCount('seo_boost_batches', 1);
        $this->assertDatabaseHas('seo_boost_items', ['client_id' => $c->id, 'status' => 'active']);
        $this->assertDatabaseHas('deals', ['client_id' => $c->id, 'origin' => 'seo_boost', 'amount' => 0, 'is_free_trial' => true]);
        $this->assertDatabaseCount('trial_activation_operations', 1);
    }

    public function test_approved_subsidiary_trial_links_the_exact_existing_target_without_second_cash(): void
    {
        [$m,$admin,$sales,$c,$main] = $this->fixture();
        $main->update(['status' => 'active', 'activated_at' => now(), 'expires_at' => now()->addDays(7)]);
        $target = Platform::factory()->create(['wp_api_url' => 'https://trials.example.test/wp-json/exotic-crm-sync/v1', 'wp_api_user' => 'fixture', 'wp_api_password' => 'fixture', 'phone_prefix' => '255']);
        $p = Product::factory()->create(['platform_id' => $target->id, 'tier' => 'vip', 'is_active' => true]);
        $targetClient = Client::factory()->create(['platform_id' => $target->id, 'wp_post_id' => 77, 'wp_user_id' => 55, 'phone_normalized' => '255712345678', 'profile_status' => 'private', 'profile_removal_requested_at' => null, 'profile_reverted_at' => null]);
        \App\Models\BillingSubscriptionRule::create(['market_id' => $target->id, 'free_trial_json' => ['enabled' => true, 'duration_days' => 7]]);
        $this->site(false, ['phone' => $targetClient->phone_normalized, 'email' => $targetClient->email]);
        Sanctum::actingAs($admin);
        $this->postJson('/api/crm/free-trials/requests', ['action' => 'subsidiary', 'deal_id' => $main->id, 'platform_id' => $target->id, 'client_id' => $targetClient->id, 'duration_days' => 7, 'reason' => 'Approved target-market introduction.'])->assertOk()->assertJsonPath('trial_request.status', 'active');
        $this->assertNotNull($main->fresh()->linked_deal_id);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(0.0, (float) Payment::first()->amount);
        $this->assertFalse($main->fresh()->is_free_trial);
    }

    public function test_field_trial_still_requires_deposit_and_waits_for_admin(): void
    {
        [$m,$admin,$sales,$c,$d] = $this->fixture();
        $m->update(['field_trial_product_id' => $d->product_id, 'field_trial_duration_days' => 7, 'field_activation_deposit_minor' => 5000]);
        $sales->update(['role' => 'field_sales']);
        Sanctum::actingAs($sales);
        $wallet = $this->mock(\App\Services\WalletService::class);
        $wallet->shouldReceive('summary')->once()->andReturn(['currency' => 'TZS', 'balance' => '0.00', 'transactions' => []]);
        $this->postJson('/api/crm/field/clients/'.$c->id.'/activate-trial', ['reason' => 'Field introduction'])->assertUnprocessable();
        $this->assertDatabaseCount('free_trial_requests', 0);
        Http::assertNothingSent();
        $wallet->shouldReceive('summary')->andReturn(['currency' => 'TZS', 'balance' => '1000.00', 'transactions' => []]);
        $id = $this->postJson('/api/crm/field/clients/'.$c->id.'/activate-trial', ['reason' => 'Field introduction'])->assertAccepted()->assertJsonPath('trial_request.status', 'pending')->json('trial_request.id');
        $this->site();
        Sanctum::actingAs($admin);
        $this->postJson('/api/crm/free-trials/requests/'.$id.'/decision', ['action' => 'approve', 'version' => 0, 'reason' => 'Deposit checked; trial approved.'])->assertOk()->assertJsonPath('trial_request.status', 'active');
        $this->assertDatabaseHas('deals', ['client_id' => $c->id, 'is_free_trial' => true, 'activated_by_field_agent' => $sales->id]);
    }

    public function test_free_extension_preserves_the_paid_source_and_renewal_preserves_old_history(): void
    {
        [$m,$admin,$sales,$c,$source] = $this->fixture();
        $source->update(['status' => 'active', 'activated_at' => now(), 'expires_at' => now()->addDays(7), 'amount' => 35000, 'is_free_trial' => false]);
        $this->site();
        Sanctum::actingAs($admin);
        $this->postJson('/api/crm/deals/'.$source->id.'/extend', ['payment_method' => 'free_trial', 'additional_days' => 7, 'reason' => 'Approved customer service extension.'])->assertOk()->assertJsonPath('trial_request.status', 'active');
        $this->assertSame(35000.0, (float) $source->fresh()->amount);
        $this->assertFalse($source->fresh()->is_free_trial);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(0.0, (float) Payment::first()->amount);
        $trial = Deal::where('client_id', $c->id)->where('is_free_trial', true)->first();
        $trial->update(['status' => 'expired', 'expires_at' => now()->subDay()]);
        $source->update(['status' => 'expired', 'expires_at' => now()->subDay()]);
        $this->postJson('/api/crm/deals/'.$source->id.'/renew', ['payment_method' => 'free_trial', 'additional_days' => 7, 'reason' => 'Approved new trial period.'])->assertOk()->assertJsonPath('trial_request.status', 'active');
        $this->assertSame('expired', $source->fresh()->status);
        $this->assertSame(35000.0, (float) $source->fresh()->amount);
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_shared_provisioning_refuses_unapproved_discretionary_trial(): void
    {
        [,,,,$d] = $this->fixture();
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(\App\Services\SubscriptionProvisioningService::class)->activateDeal($d, ['payment_method' => 'free_trial', 'is_free_trial' => true]);
    }
}
